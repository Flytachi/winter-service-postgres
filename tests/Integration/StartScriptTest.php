<?php

declare(strict_types=1);

namespace Flytachi\Winter\Service\Postgres\Tests\Integration;

/**
 * The start script against a real server: a first start initialises the data, a later
 * one keeps it; data of another version, or a directory of something else, is refused;
 * a database and a password follow the code on every start; over TCP a password is asked.
 */
final class StartScriptTest extends ServerCase
{
    public function test_a_first_start_initialises_the_data_and_creates_the_database(): void
    {
        $postgres = $this->postgres(database: 'shop');

        self::assertTrue($this->start($postgres), $this->serverOutput());

        self::assertStringContainsString('initialised PostgreSQL 18 data in', $this->serverOutput());
        self::assertStringContainsString('created database shop.', $this->serverOutput());
        $db = $this->socket($postgres);
        self::assertSame('shop', $db->query('SELECT current_database()')->fetchColumn());
        self::assertSame('winter', $db->query('SELECT current_user')->fetchColumn());
        self::assertSame('UTF8', $db->query('SHOW server_encoding')->fetchColumn());
    }

    public function test_a_later_start_keeps_the_data(): void
    {
        $postgres = $this->postgres(database: 'shop');
        self::assertTrue($this->start($postgres));
        $this->socket($postgres)->exec("CREATE TABLE notes (text text); INSERT INTO notes VALUES ('kept')");
        self::assertSame(0, $this->stop(), 'a fast shutdown ends with 0');

        self::assertTrue($this->start($postgres), $this->serverOutput());

        self::assertStringNotContainsString('initialised', $this->serverOutput());
        self::assertStringNotContainsString('created database', $this->serverOutput());
        self::assertSame('kept', $this->socket($postgres)->query('SELECT text FROM notes')->fetchColumn());
    }

    public function test_data_of_another_version_is_refused_with_what_to_do(): void
    {
        self::assertTrue($this->start($this->postgres(version: 17)));
        $this->stop();

        self::assertSame(1, $this->failedStart($this->postgres(version: 18)));
        self::assertStringContainsString(
            'is PostgreSQL 17, the server is 18: upgrade it with pg_upgrade, or set version: 17.',
            $this->serverOutput(),
        );
    }

    public function test_a_directory_of_something_else_is_not_initialised_over(): void
    {
        mkdir($this->dir . '/data');
        file_put_contents($this->dir . '/data/notes.txt', 'mine');

        self::assertSame(1, $this->failedStart($this->postgres()));
        self::assertStringContainsString('holds files but no PostgreSQL data', $this->serverOutput());
        self::assertSame('mine', file_get_contents($this->dir . '/data/notes.txt'), 'left as it was');
    }

    public function test_a_database_added_later_is_created_on_the_next_start(): void
    {
        self::assertTrue($this->start($this->postgres()));
        $this->stop();

        $postgres = $this->postgres(database: 'reports');
        self::assertTrue($this->start($postgres), $this->serverOutput());

        self::assertStringContainsString('created database reports.', $this->serverOutput());
        self::assertSame('reports', $this->socket($postgres)->query('SELECT current_database()')->fetchColumn());
    }

    public function test_over_tcp_a_password_is_asked_through_the_socket_it_is_not(): void
    {
        $port = $this->freePort();
        $postgres = $this->postgres(host: '127.0.0.1', port: $port, password: "it's s3cret");
        self::assertTrue($this->start($postgres), $this->serverOutput());

        $tcp = "pgsql:host=127.0.0.1;port={$port};dbname=postgres";
        self::assertSame(1, (new \PDO($tcp, 'winter', "it's s3cret"))->query('SELECT 1')->fetchColumn());
        try {
            new \PDO($tcp, 'winter', 'wrong');
            self::fail('a wrong password was let in');
        } catch (\PDOException $e) {
            self::assertStringContainsString('password authentication failed', $e->getMessage());
        }
        self::assertSame(1, $this->socket($postgres)->query('SELECT 1')->fetchColumn(), 'the socket asks no password');
    }

    public function test_a_changed_password_is_the_one_asked_after_the_next_start(): void
    {
        $port = $this->freePort();
        self::assertTrue($this->start($this->postgres(host: '127.0.0.1', port: $port, password: 'old')));
        $this->stop();

        self::assertTrue($this->start($this->postgres(host: '127.0.0.1', port: $port, password: 'new')));

        $tcp = "pgsql:host=127.0.0.1;port={$port};dbname=postgres";
        self::assertSame(1, (new \PDO($tcp, 'winter', 'new'))->query('SELECT 1')->fetchColumn());
        $this->expectException(\PDOException::class);
        new \PDO($tcp, 'winter', 'old');
    }

    public function test_the_password_is_not_on_the_servers_command_line(): void
    {
        $port = $this->freePort();
        self::assertTrue($this->start($this->postgres(host: '127.0.0.1', port: $port, password: 'hidden-pw')));

        exec('ps -o args', $lines);
        self::assertSame([], preg_grep('/hidden-pw/', $lines), 'no process shows it');
    }

    public function test_settings_reach_the_server(): void
    {
        $postgres = $this->postgres(settings: ['max_connections' => 42, 'fsync' => false]);
        self::assertTrue($this->start($postgres), $this->serverOutput());

        $db = $this->socket($postgres);
        self::assertSame('42', $db->query('SHOW max_connections')->fetchColumn());
        self::assertSame('off', $db->query('SHOW fsync')->fetchColumn());
    }

    public function test_a_fast_shutdown_ends_the_sessions_and_the_server_cleanly(): void
    {
        $postgres = $this->postgres();
        self::assertTrue($this->start($postgres));
        $session = $this->socket($postgres);
        $session->query('SELECT 1');

        self::assertSame(0, $this->stop(SIGINT));
        self::assertStringContainsString('received fast shutdown request', $this->serverOutput());
        self::assertStringContainsString('database system is shut down', $this->serverOutput());
    }
}
