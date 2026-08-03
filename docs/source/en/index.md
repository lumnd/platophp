# PlatoPHP

PlatoPHP is a lightweight service framework for PHP 8. It covers php-fpm HTTP, resident single-request workers, socket server dispatch, and multiprocess CLI workloads. It is installed as a Composer library and contains framework capabilities only: no administration UI, domain models, application skeleton, or sample site.

## Core capabilities

- HTTP routing, middleware, request parsing, immutable replies, and uploads
- MySQL, ClickHouse, and MongoDB connections, a query builder, migrations, schema builder, and seeders
- Redis, file, Memcached, and in-process caches, plus distributed locks
- Redis list, Redis stream, and Kafka queues with multi-worker consumers
- Console commands, cron scheduling, and foreground process supervision
- A resident server driver contract, connection object, and message-to-controller dispatcher, for websocket and tcp alike
- CSRF, CORS, rate limiting, validation, request signing, and encrypted envelopes
- File storage, Smarty templates, logs, error handling, events, and data helpers

## Runtime model

One process handles one request at a time. Supported environments are php-fpm, forked CLI workers, and resident workers that process requests serially. Request state uses static facades, so coroutine models that execute multiple requests concurrently in one process are not supported.

Process-owned resources are managed by `plato\runtime`. `plato\pool` flushes resources before forking and supervises a fixed number of children. Resident entry points establish a clean boundary before each request.

## Resident server boundary

This package does not implement an event loop, socket listener, protocol framing, TLS, or worker management. An adapter implements `plato\server\driver` and passes complete messages to `plato\server\dispatcher`. Which protocol it speaks is its own choice — websocket is the default, not the limit. The framework owns routing and request state; a separate adapter owns the event loop.

Continue with [Installation](installation.md), or read [Architecture and Layout](architecture.md).
