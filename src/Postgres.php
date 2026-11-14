<?php

declare(strict_types=1);

namespace Flytachi\Winter\Service\Postgres;

use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\App\Priority;
use Flytachi\Winter\Kernel\App\Probe;
use Flytachi\Winter\Kernel\App\Service;
use Flytachi\Winter\Kernel\App\ServiceImage;

/**
 * PostgreSQL inside the application's container: started before the rest (High), ready
 * once `pg_isready` answers, checked while it runs, restarted when it crashes or hangs,
 * stopped last with a fast shutdown.
 *
 * ```php
 * protected static function components(Components $components): void
 * {
 *     $components->service(new Postgres(version: 18, database: 'app'));
 * }
 * ```
 *
 * On its first start the data directory is initialised (`initdb`); on every start the
 * version of the data is checked against the server's, and the application's role, its
 * password and its database are kept in step with the code. By default the server
 * listens on its Unix socket only: nothing outside the container reaches it.
 *
 * Two roles: the superuser (`postgres`), for the start script and an administrator,
 * through the socket only; and the application's (`$user`) — a login without superuser
 * rights, the owner of its database. An SQL injection through the application's
 * connection reaches that database's data, not the container: no `COPY … PROGRAM`, no
 * `pg_read_file()`, no `ALTER SYSTEM`.
 *
 * @link https://winterframe.net/docs/services Services inside the application
 */
final readonly class Postgres implements Service
{
    /** The major versions Alpine ships, and so the image can install. */
    public const array VERSIONS = [14, 15, 16, 17, 18];

    /** The variable the password reaches the start script in — never the command line. */
    public const string PASSWORD_VARIABLE = 'WINTER_POSTGRES_PASSWORD';

    /** The superuser `initdb` makes: the start script's and an administrator's, never the application's. */
    public const string SUPERUSER = 'postgres';

    /** The databases a cluster has of its own: not one to hand to the application. */
    private const array SYSTEM_DATABASES = ['postgres', 'template0', 'template1'];

    private const string NAME = '/^[A-Za-z_][A-Za-z0-9_]{0,62}$/';

    /** What the apk package makes its own: the parent of the default data directory. */
    private const string HOME = '/var/lib/postgresql';

    /** The application's database: the one given, or one named after the role. */
    public string $database;

    /** The server's time zone: the one given, or the application's (`TIME_ZONE`). */
    public string $timezone;

    /**
     * @param int $version The major version: the image installs `postgresql{version}`, and
     *     data of another version is refused at start rather than half-read.
     * @param string $data The data directory — kept in a volume.
     * @param string $socket The directory of the Unix socket.
     * @param string|null $host Null: the socket only. `127.0.0.1`: TCP inside the container too.
     *     `0.0.0.0`: other containers as well. A host needs a password.
     * @param int $port The TCP port; the socket's name carries it too (`.s.PGSQL.5432`).
     * @param string $user The application's role: a login without superuser rights, the owner
     *     of its database; created when it is missing.
     * @param string|null $password The role's password: over TCP it is asked for
     *     (scram-sha-256); through the socket it is not. Kept in step on every start.
     * @param string|null $database The application's database, created when it is missing;
     *     null — one named after the role.
     * @param string $encoding The encoding `initdb` gives the cluster.
     * @param string $locale The locale `initdb` gives the cluster; `C` on Alpine, whose musl
     *     has few others.
     * @param string|null $timezone The server's time zone (`timezone`, `log_timezone`), set
     *     on every start: what `now()::date` and `date_trunc('day', …)` count days by. Null —
     *     the application's, `TIME_ZONE` (UTC without it), as PHP has it.
     * @param bool $contrib Whether the image gets `postgresql{version}-contrib` — pgcrypto,
     *     pg_trgm, citext, hstore and the rest, for `CREATE EXTENSION` (winter-ppa's
     *     `#[Extension]`).
     * @param array<string, scalar> $settings Server settings, each passed as `-c key=value`:
     *     `['shared_buffers' => '256MB', 'max_connections' => 200]`.
     * @param list<string> $args Further `postgres` arguments, as they are.
     * @param Shutdown $shutdown How the server is asked to stop.
     * @param bool $logs Whether PostgreSQL's log goes into the application's
     *     ({@see PostgresOutput}): its levels, `LOG_LEVEL`, the line or JSON format. False:
     *     it is written as PostgreSQL writes it.
     * @param string $name How logs, `app status` and the volume name it.
     * @param Priority $priority When it starts and stops relative to the other components.
     * @param bool $critical Whether the application stops when it cannot run.
     * @param int $retries Restarts after a crash within 30 minutes.
     * @param float $grace Seconds it has to stop before SIGKILL.
     * @param int $startTimeout Seconds it has to become ready; a first start runs `initdb`.
     * @param Probe|bool|null $alive How it is checked once ready: true (the default) asks
     *     `pg_isready` every 10 s (5 s for an answer, 6 misses in a row mean it hangs); a
     *     Probe of your own; null or false — not checked.
     * @throws \InvalidArgumentException A value PostgreSQL or the image cannot take.
     */
    public function __construct(
        public int $version = 18,
        public string $data = '/var/lib/postgresql/data',
        public string $socket = '/run/postgresql',
        public ?string $host = null,
        public int $port = 5432,
        public string $user = 'winter',
        #[\SensitiveParameter]
        public ?string $password = null,
        ?string $database = null,
        public string $encoding = 'UTF8',
        public string $locale = 'C',
        ?string $timezone = null,
        public bool $contrib = true,
        public array $settings = [],
        public array $args = [],
        public Shutdown $shutdown = Shutdown::Fast,
        public bool $logs = true,
        public string $name = 'postgres',
        public Priority $priority = Priority::High,
        public bool $critical = true,
        public int $retries = 3,
        public float $grace = 30.0,
        public int $startTimeout = 60,
        public Probe|bool|null $alive = true,
    ) {
        if (!in_array($version, self::VERSIONS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'PostgreSQL %d is not one the image can install: %s.',
                $version,
                implode(', ', self::VERSIONS),
            ));
        }
        foreach (['data' => $data, 'socket' => $socket] as $what => $path) {
            if (preg_match('#^/[A-Za-z0-9._/-]+$#', $path) !== 1 || str_contains($path, '..')) {
                throw new \InvalidArgumentException("{$what} must be an absolute path, \"{$path}\" given.");
            }
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException("port must be 1–65535, {$port} given.");
        }
        if ($host !== null && preg_match('/^[A-Za-z0-9.:-]+$/', $host) !== 1) {
            throw new \InvalidArgumentException("host must be an address, \"{$host}\" given.");
        }
        if ($host !== null && ($password === null || $password === '')) {
            throw new \InvalidArgumentException(
                "PostgreSQL listens on {$host}: set a password — over TCP every connection is asked for one."
            );
        }
        foreach (['user' => $user, 'database' => $database ?? $user] as $what => $value) {
            if (preg_match(self::NAME, $value) !== 1) {
                throw new \InvalidArgumentException(
                    "{$what} must be a plain name (letters, digits, _), \"{$value}\" given."
                );
            }
        }
        if ($user === self::SUPERUSER || str_starts_with($user, 'pg_')) {
            throw new \InvalidArgumentException(
                "user \"{$user}\" is the superuser's or a reserved name: the application connects as a role of its own."
            );
        }
        $database ??= $user;
        if (in_array($database, self::SYSTEM_DATABASES, true)) {
            throw new \InvalidArgumentException(
                "database \"{$database}\" is the cluster's own: name the application's database."
            );
        }
        $this->database = $database;
        $timezone ??= (string) env('TIME_ZONE', 'UTC');
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            throw new \InvalidArgumentException(
                "timezone \"{$timezone}\" is not a time zone name, as Asia/Tashkent or UTC."
            );
        }
        $this->timezone = $timezone;
        foreach ($settings as $key => $value) {
            if (preg_match('/^[a-z_][a-z0-9_.]*$/i', (string) $key) !== 1) {
                throw new \InvalidArgumentException("not a setting name: \"{$key}\".");
            }
            if (in_array(strtolower((string) $key), ['timezone', 'log_timezone'], true)) {
                throw new \InvalidArgumentException("setting {$key}: the time zone is set with timezone:.");
            }
            if (!is_scalar($value) || str_contains((string) $value, "\n")) {
                throw new \InvalidArgumentException("setting {$key}: a value is one line.");
            }
        }
        if (!array_is_list($args) || array_filter($args, static fn($arg): bool => !is_string($arg)) !== []) {
            throw new \InvalidArgumentException('args are a list of strings.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $name) !== 1) {
            throw new \InvalidArgumentException("name must be lowercase letters, digits, _ . -, \"{$name}\" given.");
        }
    }

    public function component(): Component
    {
        $script = ['/bin/sh', dirname(__DIR__) . '/bin/winter-postgres'];
        $options = [
            '--bin=' . $this->bin(),
            '--version=' . $this->version,
            '--data=' . $this->data,
            '--socket=' . $this->socket,
            '--port=' . $this->port,
            '--superuser=' . self::SUPERUSER,
            '--user=' . $this->user,
            '--database=' . $this->database,
            '--encoding=' . $this->encoding,
            '--locale=' . $this->locale,
        ];
        if ($this->host !== null) {
            $options[] = '--host=' . $this->host;
        }
        $server = ['-c', "timezone={$this->timezone}", '-c', "log_timezone={$this->timezone}"];
        foreach ($this->settings as $key => $value) {
            array_push($server, '-c', $key . '=' . self::setting($value));
        }
        $command = [...$script, ...$options, '--', ...$server, ...$this->args];
        if ($this->password !== null && $this->password !== '') {
            $command = ['/usr/bin/env', self::PASSWORD_VARIABLE . '=' . $this->password, ...$command];
        }

        return Component::command(
            $command,
            $this->name,
            critical: $this->critical,
            grace: $this->grace,
            priority: $this->priority,
            ready: $this->isReady(),
            alive: $this->aliveProbe(),
            stopSignal: $this->shutdown->signal(),
            retries: $this->retries,
            startTimeout: $this->startTimeout,
            output: $this->logs ? new PostgresOutput() : null,
        );
    }

    public function image(): ServiceImage
    {
        // The apk package makes /var/lib/postgresql the system postgres user's, mode 0700:
        // a data directory inside it is out of winter's reach unless it is winter's too.
        $directories = str_starts_with($this->data, self::HOME . '/') ? [self::HOME => 0755] : [];
        $directories += [$this->data => 0700, $this->socket => 0755];
        $packages = ["postgresql{$this->version}", "postgresql{$this->version}-client"];
        if ($this->contrib) {
            $packages[] = "postgresql{$this->version}-contrib";
        }
        return new ServiceImage(
            packages: $packages,
            directories: $directories,
            volumes: ["{$this->name}-data" => $this->data],
        );
    }

    /**
     * How the application connects: through the socket, to its database — a DSN for PDO
     * and winter-ppa.
     */
    public function dsn(): string
    {
        return sprintf('pgsql:host=%s;port=%d;dbname=%s', $this->socket, $this->port, $this->database);
    }

    /** The role to connect as: the application's, not the superuser. */
    public function user(): string
    {
        return $this->user;
    }

    /** Where this version's programs are: one directory per major version on Alpine. */
    public function bin(): string
    {
        return "/usr/libexec/postgresql{$this->version}";
    }

    /**
     * `pg_isready` against this server: 0 once it accepts connections. As the application's
     * role, to its database — both there before the server starts, so the server logs no
     * FATAL on a check.
     *
     * @return list<string>
     */
    public function isReady(): array
    {
        return [
            $this->bin() . '/pg_isready',
            '-q',
            '-h', $this->socket,
            '-p', (string) $this->port,
            '-U', $this->user,
            '-d', $this->database,
        ];
    }

    private function aliveProbe(): ?Probe
    {
        return match (true) {
            $this->alive instanceof Probe => $this->alive,
            $this->alive === true => Probe::command($this->isReady()),
            default => null,
        };
    }

    private static function setting(int|float|string|bool $value): string
    {
        return is_bool($value) ? ($value ? 'on' : 'off') : (string) $value;
    }
}
