<?php

declare(strict_types=1);

namespace Flytachi\Winter\Service\Postgres\Tests\Unit;

use Flytachi\Winter\Kernel\App\ComponentKind;
use Flytachi\Winter\Kernel\App\Priority;
use Flytachi\Winter\Kernel\App\Probe;
use Flytachi\Winter\Service\Postgres\Postgres;
use Flytachi\Winter\Service\Postgres\Shutdown;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a Postgres service makes of its parameters: the component the supervisor runs,
 * the image it needs, how the application connects — and what it refuses.
 */
final class PostgresTest extends TestCase
{
    public function test_the_defaults_make_a_database_the_rest_can_stand_on(): void
    {
        $component = (new Postgres())->component();

        self::assertSame(ComponentKind::Command, $component->kind);
        self::assertSame('postgres', $component->name());
        self::assertSame(Priority::High, $component->priority, 'up first, down last');
        self::assertTrue($component->critical);
        self::assertSame(3, $component->retries);
        self::assertSame(30.0, $component->grace);
        self::assertSame(60, $component->startTimeout);
        self::assertSame(SIGINT, $component->stopSignal, 'a fast shutdown');
        self::assertSame(
            ['/usr/libexec/postgresql18/pg_isready', '-q', '-h', '/run/postgresql', '-p', '5432', '-U', 'winter', '-d', 'winter'],
            $component->ready,
            'as the application, to its database: both there before the server starts',
        );
        self::assertNotNull($component->alive, 'checked while it runs');
        self::assertSame($component->ready, $component->alive->command);
        self::assertSame([10.0, 5.0, 6], [$component->alive->every, $component->alive->timeout, $component->alive->failures]);
    }

    public function test_the_parameters_reach_the_start_script(): void
    {
        $command = (new Postgres(
            version: 17,
            data: '/srv/pg',
            socket: '/tmp/pg',
            port: 5433,
            user: 'app',
            database: 'shop',
            timezone: 'Asia/Tashkent',
            settings: ['shared_buffers' => '256MB', 'max_connections' => 200, 'fsync' => false],
            args: ['-c', 'log_min_messages=warning'],
        ))->component()->command;

        self::assertSame('/bin/sh', $command[0]);
        self::assertStringEndsWith('/bin/winter-postgres', $command[1]);
        self::assertSame([
            '--bin=/usr/libexec/postgresql17',
            '--version=17',
            '--data=/srv/pg',
            '--socket=/tmp/pg',
            '--port=5433',
            '--superuser=postgres',
            '--user=app',
            '--database=shop',
            '--encoding=UTF8',
            '--locale=C',
            '--',
            '-c', 'timezone=Asia/Tashkent',
            '-c', 'log_timezone=Asia/Tashkent',
            '-c', 'shared_buffers=256MB',
            '-c', 'max_connections=200',
            '-c', 'fsync=off',
            '-c', 'log_min_messages=warning',
        ], array_slice($command, 2));
    }

    public function test_the_time_zone_is_the_applications_unless_given(): void
    {
        $before = getenv('TIME_ZONE');
        putenv('TIME_ZONE=Europe/Berlin');
        $_ENV['TIME_ZONE'] = 'Europe/Berlin';
        try {
            self::assertSame('Europe/Berlin', (new Postgres())->timezone);
            self::assertSame('UTC', (new Postgres(timezone: 'UTC'))->timezone);
        } finally {
            $before === false ? putenv('TIME_ZONE') : putenv("TIME_ZONE={$before}");
            unset($_ENV['TIME_ZONE']);
        }
        self::assertSame('UTC', (new Postgres())->timezone, 'UTC without TIME_ZONE');
    }

    public function test_the_password_goes_through_the_environment_never_an_option(): void
    {
        $command = (new Postgres(host: '127.0.0.1', password: 's3cret'))->component()->command;

        self::assertSame(['/usr/bin/env', 'WINTER_POSTGRES_PASSWORD=s3cret', '/bin/sh'], array_slice($command, 0, 3));
        self::assertContains('--host=127.0.0.1', $command);
        $options = array_slice($command, 3);
        self::assertSame([], array_filter($options, static fn(string $o): bool => str_contains($o, 's3cret')));
    }

    /** @return iterable<string, array{Shutdown, int}> */
    public static function shutdowns(): iterable
    {
        yield 'smart: waits for every session' => [Shutdown::Smart, SIGTERM];
        yield 'fast: ends them, cleanly' => [Shutdown::Fast, SIGINT];
        yield 'immediate: no checkpoint' => [Shutdown::Immediate, SIGQUIT];
    }

    #[DataProvider('shutdowns')]
    public function test_each_shutdown_mode_is_its_signal(Shutdown $mode, int $signal): void
    {
        self::assertSame($signal, (new Postgres(shutdown: $mode))->component()->stopSignal);
    }

    public function test_alive_is_default_own_or_off(): void
    {
        $own = Probe::command(['pg_isready', '-q'], every: 30.0, failures: 10);

        self::assertSame($own, (new Postgres(alive: $own))->component()->alive);
        self::assertNull((new Postgres(alive: null))->component()->alive, 'null — not checked');
        self::assertNull((new Postgres(alive: false))->component()->alive, 'false — not checked');
        self::assertNotNull((new Postgres(alive: true))->component()->alive);
    }

    public function test_the_component_parameters_can_be_set(): void
    {
        $component = (new Postgres(
            name: 'reports-db',
            priority: Priority::Normal,
            critical: false,
            retries: 5,
            grace: 60.0,
            startTimeout: 120,
        ))->component();

        self::assertSame(
            ['reports-db', Priority::Normal, false, 5, 60.0, 120],
            [$component->name(), $component->priority, $component->critical, $component->retries, $component->grace, $component->startTimeout],
        );
    }

    /**
     * The apk package makes /var/lib/postgresql the system postgres user's, mode 0700:
     * winter could not reach the data directory inside it ("Permission denied").
     */
    public function test_the_default_data_directory_s_parent_is_winter_s_too(): void
    {
        self::assertSame(
            ['/var/lib/postgresql' => 0755, '/var/lib/postgresql/data' => 0700, '/run/postgresql' => 0755],
            (new Postgres())->image()->directories,
        );
    }

    public function test_contrib_is_in_the_image_unless_it_is_turned_off(): void
    {
        self::assertSame(
            ['postgresql18', 'postgresql18-client', 'postgresql18-contrib'],
            (new Postgres())->image()->packages,
            'so that CREATE EXTENSION pgcrypto, pg_trgm… (winter-ppa #[Extension]) finds its files',
        );
        self::assertSame(['postgresql18', 'postgresql18-client'], (new Postgres(contrib: false))->image()->packages);
    }

    public function test_the_application_s_database_is_named_after_its_role_unless_given(): void
    {
        self::assertSame('winter', (new Postgres())->database);
        self::assertSame('app', (new Postgres(user: 'app'))->database);
        self::assertSame('shop', (new Postgres(user: 'app', database: 'shop'))->database);
        self::assertContains('--database=winter', (new Postgres())->component()->command);
    }

    public function test_the_image_holds_this_version_its_directories_and_a_volume(): void
    {
        $image = (new Postgres(version: 16, data: '/srv/pg', contrib: false, name: 'main-db'))->image();

        self::assertSame(['postgresql16', 'postgresql16-client'], $image->packages);
        self::assertSame(['/srv/pg' => 0700, '/run/postgresql' => 0755], $image->directories);
        self::assertSame(['main-db-data' => '/srv/pg'], $image->volumes);
    }

    public function test_the_application_connects_through_the_socket(): void
    {
        self::assertSame('pgsql:host=/run/postgresql;port=5432;dbname=winter', (new Postgres())->dsn());
        self::assertSame('pgsql:host=/tmp/pg;port=5433;dbname=shop', (new Postgres(socket: '/tmp/pg', port: 5433, database: 'shop'))->dsn());
        self::assertSame('app', (new Postgres(user: 'app'))->user());
    }

    /** @return iterable<string, array{\Closure(): Postgres, string}> */
    public static function refused(): iterable
    {
        yield 'a version the image cannot install' => [static fn() => new Postgres(version: 13), 'PostgreSQL 13 is not one the image can install'];
        yield 'a relative data directory' => [static fn() => new Postgres(data: 'data'), 'data must be an absolute path'];
        yield 'not a time zone' => [static fn() => new Postgres(timezone: 'Mars/Olympus'), 'timezone "Mars/Olympus" is not a time zone name'];
        yield 'the time zone as a setting' => [static fn() => new Postgres(settings: ['TimeZone' => 'UTC']), 'the time zone is set with timezone:'];
        yield 'a path climbing up' => [static fn() => new Postgres(socket: '/run/../etc'), 'socket must be an absolute path'];
        yield 'a port out of range' => [static fn() => new Postgres(port: 70000), 'port must be 1–65535'];
        yield 'a host without a password' => [static fn() => new Postgres(host: '0.0.0.0'), 'set a password'];
        yield 'a user that is not a plain name' => [static fn() => new Postgres(user: 'app; drop'), 'user must be a plain name'];
        yield 'a database that is not a plain name' => [static fn() => new Postgres(database: "x'y"), 'database must be a plain name'];
        yield 'the superuser for the application' => [static fn() => new Postgres(user: 'postgres'), 'the superuser\'s or a reserved name'];
        yield 'a reserved role name' => [static fn() => new Postgres(user: 'pg_app'), 'the superuser\'s or a reserved name'];
        yield 'the cluster\'s own database' => [static fn() => new Postgres(database: 'postgres'), 'is the cluster\'s own'];
        yield 'a template' => [static fn() => new Postgres(database: 'template1'), 'is the cluster\'s own'];
        yield 'a setting name with a space' => [static fn() => new Postgres(settings: ['shared buffers' => '1']), 'not a setting name'];
        yield 'a setting value on two lines' => [static fn() => new Postgres(settings: ['x' => "a\nb"]), 'a value is one line'];
        yield 'args that are not strings' => [static fn() => new Postgres(args: [1]), 'args are a list of strings'];
        yield 'a name with a capital' => [static fn() => new Postgres(name: 'Postgres'), 'name must be lowercase'];
    }

    #[DataProvider('refused')]
    public function test_what_postgresql_or_the_image_cannot_take_is_refused(\Closure $make, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $make();
    }

    public function test_the_password_is_not_in_a_trace(): void
    {
        $reflection = new \ReflectionParameter([Postgres::class, '__construct'], 'password');

        self::assertNotEmpty($reflection->getAttributes(\SensitiveParameter::class));
    }
}
