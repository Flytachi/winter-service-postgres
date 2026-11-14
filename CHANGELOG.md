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
  `settings`, `args`, `shutdown`) and component parameters (`name`, `priority`,
  `critical`, `retries`, `grace`, `startTimeout`, `alive`), each checked when the object
  is made.
- `Shutdown` — `Smart`, `Fast` and `Immediate`, PostgreSQL's three shutdown modes.
- `bin/winter-postgres` — initialises the data on the first start, refuses data of another
  version and a directory of something else, creates a missing database and keeps the
  password in step on every start, then becomes the server.
- `image()` for `call service docker`: `postgresql{version}` and its client, the data and
  socket directories owned by winter, the data volume.
- `dsn()` and `user()` for connecting.
