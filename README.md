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

It runs the server; to **connect** to it, use winter-ppa (or PDO) with `dsn()` below.

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

**1. Declare it** — one object for running it and connecting to it:

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
        return new PDO($pg->dsn(), $pg->user());   // through the socket: no password
    }
}

final class Application extends WinterApplication
{
    protected static function components(Components $components): void
    {
        $components->service(Db::service());
    }

    protected static function onStarting(): void
    {
        Migrations::apply();                         // postgres is ready here
    }
}
```

**2. Put it in the image:**

```bash
php call service docker          # Dockerfile block + docker-compose.override.yml
docker compose up -d --build
```

The Dockerfile gets `apk add postgresql18 postgresql18-client` and its directories, owned by
`winter`; `docker-compose.override.yml` gets the `postgres-data` volume and the
`stop_grace_period` the stop needs. Your `docker-compose.yml` is not edited.

**3. Start it.** On the first start the log says:

```
Application starting High: postgres
winter-postgres: initialised PostgreSQL 18 data in /var/lib/postgresql/data.
winter-postgres: created database app.
postgres is up.
onStarting done in 40 ms.
Application starting Normal: Http
```

---

## Parameters

### The server

| Parameter | Default | What it sets |
| --- | --- | --- |
| `version` | `18` | The major version: the image installs `postgresql{version}`. Data of another version is refused at start (see *Upgrading*) |
| `data` | `/var/lib/postgresql/data` | The data directory — kept in a volume |
| `socket` | `/run/postgresql` | The directory of the Unix socket |
| `host` | `null` | `null`: the socket only — nothing outside the container reaches the server. `127.0.0.1`: TCP inside the container too. `0.0.0.0`: other containers as well. **A host needs a password** |
| `port` | `5432` | The TCP port; the socket's name carries it too (`.s.PGSQL.5432`) |
| `user` | `'winter'` | The superuser `initdb` creates |
| `password` | `null` | Asked over TCP (scram-sha-256); never through the socket. Kept in step on every start |
| `database` | `null` | A database created when it is missing — on any start, not only the first |
| `encoding` | `'UTF8'` | The cluster's encoding (`initdb`) |
| `locale` | `'C'` | The cluster's locale (`initdb`); Alpine's musl has few others |
| `settings` | `[]` | Server settings, each passed as `-c key=value`: `['shared_buffers' => '256MB', 'max_connections' => 200]`; `true`/`false` become `on`/`off` |
| `args` | `[]` | Further `postgres` arguments, as they are |
| `shutdown` | `Shutdown::Fast` | How it is asked to stop — see *Stopping* |

### The component

| Parameter | Default | What it sets |
| --- | --- | --- |
| `name` | `'postgres'` | How logs and `app status` name it; the volume is `{name}-data` |
| `priority` | `Priority::High` | Up before the rest, down after it |
| `critical` | `true` | The application stops when it cannot run |
| `retries` | `3` | Restarts after a crash within 30 minutes |
| `grace` | `30.0` | Seconds to stop before SIGKILL |
| `startTimeout` | `60` | Seconds to become ready; a first start runs `initdb` |
| `alive` | `true` | `true`: `pg_isready` every 10 s, 5 s for an answer, 6 misses in a row mean it hangs. A `Probe` of your own. `null` or `false`: not checked |

Everything is checked when the object is made: a version the image cannot install, a
relative path, a port out of range, a host without a password, a user or database that is
not a plain name, a setting on two lines — each is an `InvalidArgumentException` naming
what is wrong.

---

## What a start does

`bin/winter-postgres` runs before the server and then becomes it (`exec`), so the stop
signal reaches the server itself:

1. **No data yet** → `initdb` with `user`, `encoding`, `locale` (and the password). A
   directory that holds files but no PostgreSQL data is refused — it is never initialised
   over.
2. **Data there** → its `PG_VERSION` is checked against `version`; a mismatch stops the
   start with what to do (below).
3. **`database`** set and missing → created, in single-user mode, before the server runs.
4. **`password`** set → applied, so a changed password takes effect on the next start. It
   reaches the script in an environment variable, never on a command line.
5. **`host`** set to an address other than the loopback → `pg_hba.conf` lets other hosts
   in, with a password.
6. `exec postgres -D … -k <socket> -p <port> -c listen_addresses=<host> …`

---

## Connecting

```php
$pg->dsn();    // pgsql:host=/run/postgresql;port=5432;dbname=app
$pg->user();   // winter
```

Through the socket, inside the container, no password is asked. Over TCP (`host` set):
`pgsql:host=127.0.0.1;port=5432;dbname=app` with `user` and `password`.

---

## Stopping

| `shutdown` | Signal | What PostgreSQL does |
| --- | --- | --- |
| `Shutdown::Smart` | SIGTERM | Waits for every session to end. A client that stays — an admin's psql — holds the stop until the grace runs out |
| `Shutdown::Fast` (default) | SIGINT | Ends the sessions, rolls their transactions back, writes a checkpoint: clean and quick |
| `Shutdown::Immediate` | SIGQUIT | Stops without a checkpoint; the next start recovers as after a crash |

A server that hangs — its `alive` check fails six times in a row — is stopped step by step:
its shutdown signal, SIGTERM at half its `grace`, SIGKILL at the end of it; then restarted.

---

## Upgrading the major version

PostgreSQL 19 cannot read the data of 18: moving `version` up without upgrading the data
would leave a server that does not start. So it does not try:

```
winter-postgres: the data in /var/lib/postgresql/data is PostgreSQL 18, the server is 19:
upgrade it with pg_upgrade, or set version: 18.
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
