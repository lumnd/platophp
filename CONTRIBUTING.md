# Contributing to PlatoPHP

PlatoPHP is a framework package installed into another project's `vendor/` directory. Contributions
must preserve that boundary: this repository contains reusable framework code, not an application,
admin panel, domain model, business configuration, or example site.

This file is the public maintenance contract. `phpcs.xml`, `phpstan.neon`, and
`composer check:architecture` are the executable authorities where a written rule can be checked.

## Development environment

PHP 8.0 is the package minimum. CI syntax-checks PHP 8.0 and 8.1, and runs the test suite on PHP 8.2
through 8.5.

All PHP verification must run in a Docker PHP container with the required extensions. Host PHP
results are not accepted because its extensions, service names, and process permissions may differ.
In the current DNMP environment, run from the DNMP root:

```bash
docker compose exec -T -e REDIS_HOST=redis6 php82 sh -lc \
  'cd /data/web/platophp && composer test'

docker compose exec -T php82 sh -lc \
  'cd /data/web/platophp && composer check:architecture && composer style && composer analyse'
```

Contributors using another Docker setup may adapt the mount path and service names. The GitHub
Actions matrix in `.github/workflows/tests.yml` is the reference environment.

The available Composer commands are:

| Command | Purpose |
| --- | --- |
| `composer test` | All Pest unit and feature tests |
| `composer test:unit` | Tests that do not start subprocesses or use external services |
| `composer test:feature` | HTTP, CLI, fork, Redis, MySQL, and other integration behaviour |
| `composer check:architecture` | Architecture rules and the public API snapshot |
| `composer analyse` | PHPStan level 5, with no baseline |
| `composer style` | PHP_CodeSniffer; zero errors is required |
| `composer style:fix` | Automatically fix supported style violations |

If Redis, MySQL, Memcached, Kafka, or another optional service is unavailable, report that result.
Never weaken a test or assertion to hide an environment failure.

## Coding standard

`phpcs.xml` is the source of truth. PlatoPHP follows PSR-12 except for these explicit conventions:

| Area | PlatoPHP convention |
| --- | --- |
| Class names | Lowercase snake_case, for example `client_response` |
| Method names | Lowercase snake_case, for example `get_img_url` |
| Private members | A leading underscore is allowed, for example `_resolve()` and `self::$_config` |
| Braces | Allman style for classes, methods, control structures, and multiline signatures |
| Control structures | One space inside parentheses, for example `if ( $ready )` |

Everything else in PSR-12 applies. Files use UTF-8 without a BOM and LF line endings. The 120-column
limit is a warning, not an error. Do not reformat the deliberate conventions above as a drive-by
cleanup.

The PSR-3 and PSR-16 adapters under `src/psr/` follow their interface signatures. Methods such as
`getMultiple()`, `setMultiple()`, and `deleteMultiple()` therefore remain camelCase and are the
explicit exception to the project method naming convention. PlatoPHP does not implement a PSR-11
container and does not provide autowiring.

### Language and comments

- Source comments, docblocks, test descriptions, configuration comments, workflow comments, TODOs,
  and commit messages are English.
- User-facing Chinese belongs in `README.zh-CN.md` and `docs/source/zh-CN/`.
- Comments explain a contract, tradeoff, or non-obvious reason. Do not narrate the next statement.
- When modifying code with a non-English comment, update the comment you touched without rewriting
  unrelated files solely for language consistency.

## Namespaces and layout

The `plato\` namespace maps exactly to `src/`. Directory segments, namespace segments, filenames,
and class names must match case-for-case. For example, `src/security/security.php` declares
`namespace plato\security;` and `class security`. Keep one type per file.

A capability carried by one class belongs at the root of `src/`. Create a directory when a stable
responsibility needs multiple collaborating classes or an interface with multiple implementations.
Nested directories are allowed only when each level represents a stable subdomain; keep the layout
shallow by default.

Composer is the only autoloader. The framework does not register an SPL autoloader or infer class
files from the host application's directory layout. Host namespaces belong in the host project's
Composer configuration.

When moving or renaming a type, check imports, inheritance, construction, static calls, string FQCNs,
and string callables. Prefer `[lock::class, 'unlock']` over `['plato\lock', 'unlock']` because IDE
refactors cannot reliably follow the latter. Large mechanical changes require scripted execution
and scripted verification.

## Framework boundaries

The following do not belong in this package:

- business tables, fields, states, workflows, or project-specific settings;
- admin UI, application layering rules, base domain models, or business response codes;
- application-specific authentication protocols or shared request parameters;
- reverse dependencies that include host application files or edit its `composer.json`;
- business SDKs, payments, OAuth, mail delivery, MIME construction, or cloud service implementations;
- code whose licence is incompatible with MIT.

Queue drivers are framework infrastructure, so Redis list, Redis stream, and Kafka implementations
may live under `src/queue/`. Optional extensions and unreachable services must produce clear errors,
not silent fallback.

The framework must not change host process settings while a class file is loaded. Calls such as
`error_reporting()`, `ini_set()`, `date_default_timezone_set()`, and `session_start()` require an
explicit bootstrap setting. Framework paths come from `plato::app_path()` and its sibling accessors,
not constants that the host is expected to define.

If a proposed feature is not clearly reusable framework infrastructure, treat it as application
functionality and discuss it before implementation.

Framework configuration is recursively overlaid by the host project's files of the same name.
Environment differences and secrets come from `.env` / `$_ENV`; do not add environment-suffixed
configuration files or commit sensitive defaults. Use `config::has()` to test key presence because
`false`, `0`, and an empty string are valid configured values.

## Initialisation model

Loading a class performs no initialisation. New facilities must fit one of these three axes.

### Static configuration facades

Static configuration is lazy, overridable, and resettable:

- `$_config` uses `null` as the only unloaded sentinel; an empty array is valid configuration.
- `config()` reads the configuration section owned by that class on first use.
- `configure()` merges with `$config + (array) self::config()`; it does not replace the facade config.
- A reset method sets `$_config` back to `null`. Use `reset_config()` if `reset()` already describes
  request state.
- If configuration creates derived static state, both first load and `configure()` rebuild it.

Each configuration section has one owner. Other classes call the owner's `config()` method instead
of reading the file again or accessing another class's configuration property.

### Process and request side effects

Process-level side effects use an idempotent public `boot()`. Request-level side effects use a
repeatable public `capture()`. `plato::registry()` owns their order:

```text
cli::boot() -> log::boot() -> req::capture() -> error_handler::capture() -> security::capture()
```

Do not call `boot()` defensively from business methods. The sole exception is `log::write()`, which
must be able to record an early bootstrap failure. A class that owns both configuration and side
effects keeps those axes separate.

Resident workers establish a request boundary before handling the next request. A repeated
`capture()` must not leak prior request state. When replacing a raw request body, call
`req::set_raw()` or `reset_input()` before `capture()`.

### Driver instances

Driver configuration and resources belong to an object instance. One named connection maps to one
instance; do not introduce a static binding or mutable global "current connection".

- A driver's `configure(array $config)` replaces its instance configuration and clears derived
  state without opening a connection.
- Connections and handles open on first real use and are registered with `plato\runtime`.
- An instance that caches a handle compares `runtime::epoch()` and reacquires it after a fork.
- Facade configuration still uses the merge rules above. Named instances are obtained explicitly
  through `connection($name)` or `driver($name)`.

No initialisation path may make a network request, write business data, or connect to an optional
service.

## Concurrency and process resources

PlatoPHP processes one request at a time in each process. It supports php-fpm, forked CLI workers,
and resident workers that handle requests serially. It does not support concurrent requests inside
one process through coroutines or fibers because request state is held by static facades.

Connections, file handles, and process-private tokens are owned by `plato\runtime`:

- use `runtime::share($key, $factory, $closer)` for resources;
- use `runtime::on_fork()` for non-resource state invalidated by a fork;
- use `runtime::epoch()` in instances that cache handles;
- discard inherited child resources without running a close protocol that affects the parent;
- reseed randomness in manually forked children; `plato\pool` handles its own children.

`plato\pool` is a foreground worker supervisor. Daemonisation, startup, restart policy, pid files,
privilege changes, service status, and log rotation belong to systemd, supervisord, or the container
runtime. The pool flushes runtime resources before its first fork and waits only for known child
PIDs.

`src/server/` contains the resident server driver contract, connection value, server facade, and
message dispatcher. Socket listening, handshakes, framing, keepalive, TLS, event loops, and worker
management belong to a separate adapter package. Which protocol a listener speaks is the adapter's
choice -- websocket is the default, not the limit -- but whatever it speaks has to hand the
dispatcher one whole application message, never a byte stream. Dispatch resets request state through
`plato::reset_request()`. An in-process connection map must never be represented as cross-process
broadcast.

The CLI and resident-server branches of `plato::run()` skip the HTTP permission callback and CSRF check by
default, controlled by `cli_auth` and `cli_csrf`. Any change that makes those defaults more permissive
must be called out explicitly as a security-relevant behaviour change.

## Tests

- `tests/Pest.php` is the shared bootstrap. Fixtures belong in `tests/Fixtures/`; architecture tools
  belong in `tests/tools/`.
- Unit tests mirror `src/` and do not start subprocesses or contact external services. Integration
  behaviour belongs in `tests/Feature/`.
- Keep one test file per class and describe behaviour with English `it('...')` descriptions.
- Tests have no ordering dependencies. Save and restore shared static state in `beforeEach` and
  `afterEach`; warm lazy state before taking a snapshot when necessary.
- Tests must not write into the repository tree. Use an isolated temporary directory and clean it.
  The worktree must remain unchanged after a test run.
- Fork tests run through a child script. Concurrency tests use a synchronisation gate so the workers
  actually overlap. Use a leading backslash for FQCNs embedded in child scripts.

## Public API and compatibility

`tests/tools/api-snapshot.txt` records public and protected symbols in `src/`. Protected symbols are
included because downstream subclasses depend on them. To make an intentional API change, run in
the Docker PHP container:

```bash
composer check:architecture -- --update-api
```

Commit the snapshot with the implementation and document the behaviour and reason in `CHANGELOG.md`.
During the `0.x` series, breaking changes may ship in a minor release, but they must be explicit.

The compatibility surface includes:

- public and protected symbols;
- configuration keys and their meaning;
- exception types and codes;
- documented return semantics;
- queue and envelope wire formats.

Private implementation, test internals, build scripts, exact log text, and semantically equivalent
generated SQL are not compatibility promises.

## Documentation

Documentation describes the framework only. It does not describe an admin product or the structure,
business workflow, response codes, or deployment policy of a host application.

Every page has matching `docs/source/en/` and `docs/source/zh-CN/` files with the same slug. Update
both languages together. `README.md` and `README.zh-CN.md` must likewise stay equivalent.

Build the documentation in the Docker PHP container after installing development dependencies:

```bash
php docs/build.php
```

The builder updates `docs/manifest.json` hashes and validates locale parity, sources, local links,
and assets. Do not edit generated `docs/site/` files or manifest hashes manually.

## Pull requests

1. Keep one subject per pull request. Do not mix a large rename with unrelated behaviour changes.
2. State what changes for callers, including configuration and return semantics.
3. Update tests, documentation, the API snapshot, and `CHANGELOG.md` when the affected surface
   requires them.
4. Report every verification command run, failure, skipped check, and unavailable external service.
5. Use English commit messages.

For security issues, do not open a public issue. Follow `SECURITY.md`.
