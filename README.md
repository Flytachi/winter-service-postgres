# Winter Service · PostgreSQL

[![Latest Version on Packagist](https://img.shields.io/packagist/v/flytachi/winter-service-postgres.svg)](https://packagist.org/packages/flytachi/winter-service-postgres)
[![PHP Version Require](https://img.shields.io/packagist/php-v/flytachi/winter-service-postgres.svg?style=flat-square)](https://packagist.org/packages/flytachi/winter-service-postgres)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)

PostgreSQL inside a Winter application's container, declared in one line. The application
starts it before everything else, waits until it answers, keeps checking it, restarts it
when it crashes or hangs, and stops it last — with a fast shutdown.

```php
$components->service(new Postgres(version: 18, database: 'app'));
```

It runs the server; to **connect** to it, use PDO or winter-ppa — see *Connecting*.

📖 **[Services in the documentation](https://winterframe.net/docs/services)**

---

## Installation

```bash
composer require flytachi/winter-service-postgres
```

Requires PHP **8.4+**, `flytachi/winter-kernel` **4.4.5+** and the `flytachi/winter` image
(Alpine): the server itself comes from `apk`, and `call service docker` puts it there.

---

## Quick start

**1. Declare it** in the application:

```php
use Flytachi\Winter\Service\Postgres\Postgres;

final class Application extends WinterApplication
{
    protected static function components(Components $components): void
    {
        $components->service(new Postgres(version: 18, database: 'app'));
    }
}
```

**2. Put it in the image:**

```bash
php call service docker          # Dockerfile block; prints what compose needs
```

The Dockerfile gets `apk add postgresql18 postgresql18-client postgresql18-contrib` and its
directories, owned by `winter`. Compose is yours: the command does not edit it, it prints
what to add. Put into `docker-compose.yml` what suits you:

```yaml
services:
  server:
    stop_grace_period: 70s
    volumes:
      - postgres-data:/var/lib/postgresql/data
volumes:
  postgres-data:
```

- **`stop_grace_period`** — always needed, see below.
- **The volume** — if the database must outlive a recreated container. For tests or a
  throwaway stand it can go. The data directory is already inside a volume of yours
  (`data: '/var/lib/app/postgresql'`)? No separate volume is needed.

> [!WARNING]
> **A stop needs up to 70 seconds:** `stop_grace_period: 70s`. Docker's default is 10 s:
> after that it kills the container, and PostgreSQL has no time to stop cleanly. On
> Kubernetes, set the same 70 seconds — see *Stopping*.

```bash
docker compose up -d --build
```

**3. Start it.** On the first start the log says:

```
[2026-11-15 02:10:00] [INFO ] -sys- [1]: Application starting High: postgres
[2026-11-15 02:10:01] [INFO ] -postgres- [9]: initialised PostgreSQL 18 data in /var/lib/postgresql/data.
[2026-11-15 02:10:01] [INFO ] -postgres- [9]: created role winter.
[2026-11-15 02:10:02] [INFO ] -postgres- [9]: created database app.
[2026-11-15 02:10:02] [INFO ] -postgres- [9]: database system is ready to accept connections
[2026-11-15 02:10:02] [INFO ] -sys- [1]: postgres is up.
[2026-11-15 02:10:02] [INFO ] -sys- [1]: Application starting Normal: Http
```

PostgreSQL is up before everything else: HTTP takes its first request with the database
already answering. Its lines are in the common log, under `-postgres-` — see *Log*.

---

## Parameters

### The server

| Parameter | Default | What it sets |
| --- | --- | --- |
| `version` | `18` | The major version: the image installs `postgresql{version}`. Data of another version is refused at start (see *Upgrading*) |
| `data` | `/var/lib/postgresql/data` | The data directory — keep it in a volume |
| `socket` | `/run/postgresql` | The directory of the Unix socket |
| `host` | `null` | `null`: the socket only — nothing outside the container reaches the server. `127.0.0.1`: TCP inside the container too. `0.0.0.0`: other containers as well. **A host needs a password** |
| `port` | `5432` | The TCP port; the socket's name carries it too (`.s.PGSQL.5432`) |
| `user` | `'winter'` | The application's role: a login without superuser rights, the owner of its database; created when it is missing. See *Roles* |
| `password` | `null` | The role's password: asked over TCP (scram-sha-256), never through the socket. Kept in step on every start — changed, or removed |
| `database` | `null` | The application's database, created when it is missing — on any start, not only the first. `null`: one named after the role (`winter`) |
| `encoding` | `'UTF8'` | The cluster's encoding (`initdb`) |
| `locale` | `'C'` | The cluster's locale (`initdb`); Alpine's musl has few others |
| `timezone` | `null` | The server's time zone (`timezone`, `log_timezone`), set on every start: what `now()::date` and `date_trunc('day', …)` count days by. `null`: the application's `TIME_ZONE` (UTC without it). An IANA name, `Asia/Tashkent`; not in `settings` |
| `contrib` | `true` | Whether the image gets `postgresql{version}-contrib` — `pgcrypto`, `pg_trgm`, `citext` and the rest. See *Extensions* |
| `settings` | `[]` | Server settings, each passed as `-c key=value`: `['shared_buffers' => '256MB', 'max_connections' => 200]`; `true`/`false` become `on`/`off` |
| `args` | `[]` | Further `postgres` arguments, as they are |
| `shutdown` | `Shutdown::Fast` | How it is asked to stop — see *Stopping* |
| `logs` | `true` | Whether PostgreSQL's log goes into the application's — see *Log*. `false`: as PostgreSQL writes it |

### The component

| Parameter | Default | What it sets |
| --- | --- | --- |
| `name` | `'postgres'` | How logs and `app status` name it; the volume in the compose hint is `{name}-data` |
| `priority` | `Priority::High` | Up before the rest, down after it |
| `critical` | `true` | The application stops when it cannot run |
| `retries` | `3` | Restarts after a crash within 30 minutes |
| `grace` | `30.0` | Seconds to stop before SIGKILL |
| `startTimeout` | `60` | Seconds to become ready; a first start runs `initdb` |
| `alive` | `true` | `true`: `pg_isready` every 10 s, 5 s for an answer, 6 misses in a row mean it hangs. A `Probe` of your own. `null` or `false`: not checked |

Everything is checked when the object is made: a version the image cannot install, a
relative path, a port out of range, a host without a password, a user or database that is
not a plain name, `user: 'postgres'` (the superuser), `database: 'postgres'` (the
cluster's own), a setting on two lines — each is an `InvalidArgumentException` naming what
is wrong.

---

## What a start does

`bin/winter-postgres` runs before the server and then becomes it (`exec`), so the stop
signal reaches the server itself:

1. **No data yet** → `initdb` with the superuser `postgres`, `encoding` and `locale`. A
   directory that holds files but no PostgreSQL data is refused — it is never initialised
   over.
2. **Data there** → its `PG_VERSION` is checked against `version`; a mismatch stops the
   start with what to do (below).
3. **The role `user` missing** → created: a login, without superuser rights.
4. **The password** follows the code: set — applied, removed — removed. It reaches the
   script in an environment variable, never on a command line.
5. **The database missing** → created, owned by `user`. There, but someone else's → handed
   to `user`.
6. **`pg_hba.conf` written whole:** through the socket, anyone; over TCP, only `user`, only
   to its database, only with its password — from other addresses too when `host` is not
   the loopback.
7. `exec postgres -D … -k <socket> -p <port> -c listen_addresses=<host> …`

Steps 3–5 run in single-user mode, before the server listens on anything.

---

## Roles

| Role | Who | How it signs in |
| --- | --- | --- |
| `postgres` | the superuser: the start script, an administrator | through the socket only, inside the container, no password |
| `user` (`winter`) | the application: a plain role, the owner of its database | through the socket, no password; over TCP with its password, to its database only |

The application's role can do all the application needs — tables, indexes, `ALTER` in its
database, the extensions of contrib. What it cannot do is what turns an SQL injection into
the container's takeover:

| Through an injection | superuser | the application's role |
| --- | --- | --- |
| read and change its database's data | yes | yes |
| `COPY … TO PROGRAM` — a command in the container | yes | no |
| `pg_read_file()` — any file, `.env` included | yes | no |
| `ALTER SYSTEM`, creating roles | yes | no |

A second line of defence; the first is queries with parameters, as winter-ppa builds them.
An administrator signs in as the superuser through the socket:

```bash
docker exec -it app psql -h /run/postgresql -U postgres
```

Data in which `user` is a superuser (a cluster made otherwise) still starts; the log says
the application connects with every right, and how to change it. The script does not
lower the role's rights itself.

---

## Extensions

`contrib` (on by default) puts PostgreSQL's own extensions in the image — `pgcrypto`,
`pg_trgm`, `citext`, `hstore`, `unaccent`, `ltree`, `uuid-ossp` and more. The service only
**installs** them; `CREATE EXTENSION` **enables** one in the database — in winter-ppa,
`#[Extension]` on the configuration class:

```php
#[Extension('pg_trgm')]
#[Extension('citext')]
class MainDbConfig extends PgDbConfig implements PpaPoolConfigInterface
{
    public static function service(): Postgres
    {
        return new Postgres(version: 18, database: 'app');   // contrib: true
    }
}
```

The application's role enables the **trusted** ones itself — nearly all code needs:
`pgcrypto`, `uuid-ossp`, `citext`, `hstore`, `pg_trgm`, `unaccent`, `ltree`, `btree_gin`,
`btree_gist`, `fuzzystrmatch`, `intarray`, `cube`, `isn`, `lo`, `seg`, `tablefunc`.

> [!WARNING]
> `pg_stat_statements`, `postgres_fdw`, `dblink` and the other untrusted ones only the
> superuser enables: from the application's role `CREATE EXTENSION` stops with
> `permission denied to create extension … Must be superuser`. PostGIS and pgvector are
> not in contrib — they are packages of their own.

UUIDs need no extension: `gen_random_uuid()` is built into PostgreSQL 13+. No need for
contrib at all? `new Postgres(contrib: false)` — 4 MB less in the image.

---

## Connecting

The `Postgres` object knows where its server is:

```php
$pg->dsn();       // pgsql:host=/run/postgresql;port=5432;dbname=app
$pg->user();      // winter — the application's role, not the superuser
$pg->database;    // app — without database: winter, the role's name
```

Through the socket, inside the container, no password is asked. Over TCP (`host` set):
`pgsql:host=127.0.0.1;port=5432;dbname=app` with `user` and `password`.

So the way it runs and the way it is reached are best kept in one place — one method that
both the application and the code connecting to it call.

### With PDO

```php
use Flytachi\Winter\Service\Postgres\Postgres;

final class Db
{
    public static function service(): Postgres
    {
        return new Postgres(version: 18, database: 'app');
    }

    public static function connect(): PDO
    {
        $pg = self::service();
        return new PDO($pg->dsn(), $pg->user(), $pg->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
}

// bootstrap.php
protected static function components(Components $components): void
{
    $components->service(Db::service());
}

// anywhere
$notes = Db::connect()->query('SELECT id, text FROM notes')->fetchAll();
```

### With winter-ppa

The database configuration winter-ppa connects with is the natural home for it: the same
class runs the server and connects to it, so the socket, the port, the database and the
user cannot drift apart.

```php
namespace Main\Configs;

use Flytachi\Winter\Cdo\Config\PgDbConfig;
use Flytachi\Winter\Ppa\Pool\PpaPoolConfigInterface;
use Flytachi\Winter\Ppa\Pool\PpaPoolTrait;
use Flytachi\Winter\Service\Postgres\Postgres;

class MainDbConfig extends PgDbConfig implements PpaPoolConfigInterface
{
    use PpaPoolTrait;

    public int $poolMaxConnections = 10;
    public float $poolWaitTimeout = 5.0;

    /** What the application runs. */
    public static function service(): Postgres
    {
        return new Postgres(version: 18, database: 'app');
    }

    /** How winter-ppa connects to it. */
    public function setUp(): void
    {
        $pg = self::service();
        $this->host = $pg->socket;                     // a socket directory is a host for libpq
        $this->port = $pg->port;
        $this->database = $pg->database;
        $this->username = $pg->user;
        $this->password = $pg->password ?? '';         // through the socket none is asked
    }
}
```

```php
// bootstrap.php
protected static function components(Components $components): void
{
    $components->service(MainDbConfig::service());
}
```

Repositories point at it as at any database configuration:

```php
class NoteRepository extends Repository
{
    protected string $dbConfigClassName = MainDbConfig::class;
    protected string $entityClassName = Note::class;
    public static string $table = 'notes';
}

$notes = NoteRepository::instance()->orderBy('id')->findAll();
$id = new NoteRepository()->insert($note);
```

Over TCP (`host` set, other containers connecting), `setUp()` takes `127.0.0.1` — or the
container's name — for the host, and the password becomes mandatory.

---

## Log

PostgreSQL's log goes into the application's: the same format, the `-postgres-` channel
(blue in colour), the PID of the database process that wrote the line in the brackets.

```
[2026-11-15 02:11:23] [ERROR] -postgres- [64]: relation "usres" does not exist at character 15 {"statement":"SELECT * FROM usres"}
```

| PostgreSQL | Log |
| --- | --- |
| `DEBUG1`–`DEBUG5` | `DEBUG` |
| `LOG`, `INFO` | `INFO` |
| `NOTICE` | `NOTIC` |
| `WARNING` | `WARN` |
| `ERROR` | `ERROR` |
| `FATAL` | `CRIT` |
| `PANIC` | `EMERG` |

- `LOG_LEVEL` applies: `LOG_LEVEL=warning` keeps the database's warnings and errors only.
- `DETAIL`, `HINT`, `STATEMENT`, `CONTEXT` join their error, in its context.
- JSON and the log file work as for the application; in JSON the PID is `extra.program`.
- The start script's lines are under `-postgres-` too, its warnings and errors at `WARN`
  and `ERROR`.

A `log_line_prefix` of your own is not understood: those lines are logged as they are, at
`INFO`. `logs: false` leaves PostgreSQL's output untouched. For a detailed query log
(`log_statement: 'all'`), turn on PostgreSQL's own collector (`'logging_collector' => true`):
thousands of lines a second should not go through the application's process.

## Stopping

| `shutdown` | Signal | What PostgreSQL does |
| --- | --- | --- |
| `Shutdown::Smart` | SIGTERM | Waits for every session to end. A client that stays — an admin's psql — holds the stop until the grace runs out |
| `Shutdown::Fast` (default) | SIGINT | Ends the sessions, rolls their transactions back, writes a checkpoint: clean and quick |
| `Shutdown::Immediate` | SIGQUIT | Stops without a checkpoint; the next start recovers as after a crash |

A server that hangs — its `alive` check fails six times in a row — is stopped step by step:
its shutdown signal, SIGTERM at half its `grace`, SIGKILL at the end of it; then restarted.

### 70 seconds to stop

PostgreSQL stops last, **after** HTTP, so their times add up:

| What | Seconds |
| --- | --- |
| HTTP finishes its requests | 30 |
| PostgreSQL (`grace`) | 30 |
| the application's margin | 5 |
| the container's margin | 5 |
| **`stop_grace_period`** | **70** |

That is the number `call service docker` prints. A stop usually takes a fraction of a
second: 70 is the limit, not a wait.

Changed `grace` (`new Postgres(grace: 60.0)`)? Run `call service docker` again and update
`stop_grace_period` in compose — 100 seconds here. On Kubernetes, the same number goes into
`terminationGracePeriodSeconds`.

---

## Upgrading the major version

PostgreSQL 19 cannot read the data of 18: moving `version` up without upgrading the data
would leave a server that does not start. So it does not try:

```
[2026-11-15 02:10:01] [ERROR] -postgres- [9]: the data in /var/lib/postgresql/data is PostgreSQL 18, the server is 19: upgrade it with pg_upgrade, or set version: 18.
```

Upgrade with `pg_upgrade` (both versions in the image), or dump and restore
(`pg_dumpall`), then raise `version`. Minor versions (18.6 → 18.7) need nothing: the image
gets them with its next build.

---

## Testing

```bash
composer test                     # unit tests
composer test-integration         # against real PostgreSQL 17 and 18, in Docker
```

## License

MIT — see [LICENSE](LICENSE).
