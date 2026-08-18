# Architecture and Layout

PlatoPHP is a Composer framework package. The repository root is the package root, `src/` contains framework code only, and application or domain capabilities belong to projects that depend on it.

## Modules

```text
src/
├── plato.php runtime.php worker.php pool.php log.php cli.php
├── http/       routing, requests, replies, middleware, HTTP client
├── database/   connections, queries, grammars, migrations, schema, seeders
├── cache/      facade, repository, store contract, four implementations
├── queue/      facade, driver contract, messages, workers, three implementations
├── console/    kernel, generators, migration, queue, schedule, seed commands
├── security/   CSRF, validation, throttling, signing, encryption
├── storage/    disk contract, path validation, local disk
├── server/     protocol-neutral driver, connection, dispatcher, facade
├── debug/      benchmark, profiler, error handling
├── psr/        thin PSR-3 and PSR-16 adapters
└── exception/  framework exceptions
```

The `plato\` namespace maps exactly to `src/`. For example, `src/security/validate.php` declares `plato\security\validate`.

## Framework boundary

The framework supplies reusable protocols and runtime mechanisms. It does not define business tables, business states, administration UI, domain models, payment integrations, or vendor SDKs. Host applications compose public framework interfaces. Product-specific layout, authorization models, and operational documentation belong to those projects.

## Two boundaries

The request boundary clears static state created by one request. The process boundary rebuilds connections and handles after a fork. `plato::reset_request()` manages the first, and every resident entry point calls it -- `plato\server\dispatcher` before each message, `plato\queue\worker` before each job. An application adds its own request-state cleanup with the registry `reset_handle`, which runs after framework state is clear. `plato\runtime` manages the process boundary.

This design supports php-fpm, foreground multiprocess CLI services, and serial resident workers. It does not support concurrent requests inside one process.

Persistent connections are the one thing the process boundary cannot manage. `PDO::ATTR_PERSISTENT` and phpredis' `pconnect()` keep the socket in the extension's own pool, keyed by endpoint, where `plato\runtime` cannot release it: a forked worker is handed its parent's socket and the two interleave on it. Both settings default to off; leave them off in any process that forks.
