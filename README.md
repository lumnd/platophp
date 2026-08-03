# PlatoPHP

**English** | [简体中文](README.zh-CN.md)

[![tests](https://github.com/lumnd/platophp/actions/workflows/tests.yml/badge.svg)](https://github.com/lumnd/platophp/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/lumnd/platophp/php?logo=php&logoColor=white)](https://packagist.org/packages/lumnd/platophp)
[![Latest version](https://img.shields.io/packagist/v/lumnd/platophp?logo=packagist&logoColor=white)](https://packagist.org/packages/lumnd/platophp)
[![License](https://img.shields.io/packagist/l/lumnd/platophp)](LICENSE)

PlatoPHP is a lightweight HTTP, resident socket, and multiprocess CLI service framework for PHP 8.
It is installed as a Composer library and supplies framework capabilities only: no administration
UI, domain model, application skeleton, business configuration, or sample site.

## Requirements

PHP 8.0 or later with `json`, `mbstring`, `openssl`, and `zlib`. CI covers PHP 8.0 through 8.5.

```bash
composer require lumnd/platophp
```

The runtime core has no mandatory third-party Composer dependency. Optional capabilities declare
their dependency when first used:

| Capability | Dependency |
| --- | --- |
| Smarty templates | `smarty/smarty:^5.5` |
| MySQL / MariaDB | `ext-pdo_mysql` |
| MongoDB | `ext-mongodb` |
| Redis cache, queues, and distributed locks | `ext-redis` |
| Memcached | `ext-memcached` |
| Kafka | `ext-rdkafka` |
| HTTP client and ClickHouse | `ext-curl` |
| Process supervision | `ext-pcntl`, `ext-posix` |
| XML request bodies | `ext-simplexml` |
| Workerman resident server | `lumnd/plato-workerman` |
| PSR-3 / PSR-16 adapters | `psr/log`, `psr/simple-cache` |

## Quick Start

The host project owns its entry point, configuration, controllers, templates, and writable paths.
Register application namespaces through the host's Composer configuration:

```json
{
  "autoload": {
    "psr-4": {
      "control\\": "app/control/",
      "middleware\\": "app/middleware/",
      "command\\": "app/command/"
    }
  }
}
```

Create an HTTP entry point:

```php
<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use plato\plato;

plato::registry([
    'app_path'  => dirname(__DIR__) . '/app',
    'env_path'  => dirname(__DIR__) . '/.env',
    'data_path' => dirname(__DIR__) . '/data',
    'debug'     => false,
]);

plato::run();
```

Add a controller at `app/control/ctl_index.php`:

```php
<?php

namespace control;

use plato\http\resp;

class ctl_index
{
    public static array $actions = ['index' => ['GET']];

    public function index()
    {
        return resp::json(['framework' => 'PlatoPHP']);
    }
}
```

The default route `/index/index` resolves to that action. Controllers are plain host classes;
PlatoPHP deliberately defines no controller or model base class.

## Framework Modules

- HTTP routing, middleware, typed input, uploads, immutable replies, cookies, and sessions
- MySQL, ClickHouse, and MongoDB drivers with a bound query builder
- Migrations, schema builder, and repeatable seeders
- Redis, file, Memcached, and process-local caches; Redis distributed locks
- Redis list, Redis stream, and Kafka queues with multi-worker consumers
- Protocol-neutral resident server contracts for WebSocket, TCP, and custom framed transports
- Console commands, code generators, cron scheduling, and foreground process supervision
- CSRF, CORS, throttling, validation, request signing, and AES-256-GCM envelopes
- File storage contracts, optional Smarty rendering, logs, profiling, events, and data helpers
- Thin PSR-3 and PSR-16 adapters

## Runtime Model

One process handles one request at a time. Supported modes are php-fpm, forked CLI workers, and
resident workers that process requests serially. Request state uses static facades, so concurrent
coroutines or fibers running multiple requests in one process are not supported.

`plato\runtime` owns process-private resources and invalidates inherited connections after a fork.
`plato\pool` supervises a fixed number of foreground workers. Daemonization, startup, restart policy,
pid files, and log rotation belong to systemd, supervisord, or the container runtime.

## Resident Server Boundary

This package provides `plato\server\driver`, connection values, named server instances, and a dispatcher
that maps one complete message to the HTTP controller pipeline. It does not implement socket
listening, handshake, framing, keepalive, TLS, or an event loop. A separate adapter implements the
driver and owns those responsibilities.

Which protocol a listener speaks is the adapter's choice: websocket is what `config/server.php`
defaults to, but the same contract serves TCP, a line protocol, or a custom binary one. The single
requirement is that the adapter hands the dispatcher one whole application message rather than a
byte stream.

## Configuration

Configuration overlays framework `config/` with host `config/` recursively. Environment-specific
values and secrets come from `.env` through `$_ENV`; there are no environment-suffixed configuration
files. Connections and optional services are opened lazily on first real use.

## Console

```bash
php vendor/bin/plato --help
php vendor/bin/plato migrate
php vendor/bin/plato make:controller user
php vendor/bin/plato queue:work --queue=emails --workers=4
php vendor/bin/plato schedule:run
```

## Documentation

- [English documentation](docs/source/en/index.md)
- [简体中文文档](docs/source/zh-CN/index.md)

The generated site provides matching English and Chinese navigation and per-page language switches.

## Verification

Repository tests and checks run in the configured Docker PHP container:

```bash
docker compose exec -T -e REDIS_HOST=redis6 php82 sh -lc \
  'cd /data/web/platophp && composer test'

docker compose exec -T php82 sh -lc \
  'cd /data/web/platophp && composer check:architecture && composer style && composer analyse'
```

## Versioning

Release tags follow Semantic Versioning. During the `0.x` series, public API changes are documented
in [CHANGELOG.md](CHANGELOG.md). The public API snapshot prevents signatures from changing silently.

See [CONTRIBUTING.md](CONTRIBUTING.md), [SECURITY.md](SECURITY.md), and
[CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) before contributing.

## License

[MIT](LICENSE)
