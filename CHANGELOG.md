# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] — unreleased

### Added

- `Postgres` — PostgreSQL as a service of a Winter application (`Components::service()`):
  High priority, ready once `pg_isready` answers, checked while it runs (`alive`), stopped
  with a fast shutdown, restarted after a crash. Server parameters (`version`, `data`,
  `socket`, `host`, `port`, `user`, `password`, `database`, `encoding`, `locale`,
  `contrib`, `settings`, `args`, `shutdown`, `logs`) and component parameters (`name`, `priority`,
  `critical`, `retries`, `grace`, `startTimeout`, `alive`), each checked when the object
  is made.
- `Shutdown` — `Smart`, `Fast` and `Immediate`, PostgreSQL's three shutdown modes.
- `PostgresOutput` — PostgreSQL's log read into the application's (kernel's
  `ProgramOutput`): each entry at its level (LOG → info, FATAL → critical…), with the PID
  of the backend that wrote it, its DETAIL/HINT/STATEMENT/CONTEXT lines in its context;
  the start script's lines too, its warnings and errors at their levels. On by default;
  `logs: false` leaves the output as PostgreSQL writes it.
- Two roles: the superuser `postgres` (the start script's and an administrator's, through
  the socket only) and the application's `user` — a login without superuser rights, the
  owner of its database. An SQL injection through the application's connection stays in
  that database: no `COPY … PROGRAM`, `pg_read_file()`, `ALTER SYSTEM`. Without
  `database`, the application's database is named after the role.
- `bin/winter-postgres` — initialises the data on the first start, refuses data of another
  version and a directory of something else; on every start keeps the role, its password
  (set or removed) and its database (created, or handed to the role) in step with the
  code, and writes `pg_hba.conf` whole: over TCP only the role, to its database, with its
  password. A role that is a superuser is left working and named in the log. Then it
  becomes the server. Its warnings and errors say so first (`WARNING:`, `ERROR:`).
- `image()` for `call service docker`: `postgresql{version}`, its client and — with
  `contrib` (the default) — its contrib, for `CREATE EXTENSION` (winter-ppa's
  `#[Extension]`); the data and socket directories owned by winter, the data volume.
- `dsn()` and `user()` for connecting.
