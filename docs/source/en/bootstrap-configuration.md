# Bootstrap and Configuration

## Minimal entry point

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

`app_path` is required. `env_path` defaults to `.env` in the project root and `data_path` defaults to `app_path/data`. Log and cache paths are derived from `data_path`.

## Registry options

Common options include:

| Option | Purpose |
| --- | --- |
| `env` | Explicit environment; otherwise `APP_ENV`, then `pub` |
| `debug` | Controls framework debug output; omitted means no host setting is changed |
| `bootstrap` | Application callback invoked before core bootstrap |
| `controller_namespace` | Controller namespace, default `control` |
| `session_start` | Whether the framework starts the PHP session |
| `check_purview_handle` | Application authorization callback before an action |
| `error_handle` | Application callback rendering a failure middleware cannot reach (unknown controller, non-routable action) |
| `reset_handle` | Application callback invoked after framework request state is cleared in a resident process |
| `cli_auth` / `cli_csrf` | Whether CLI and resident server entry points run authorization and CSRF |

`reset_handle` takes no arguments. Use it to clear application-owned static request state that the
framework cannot know about. Framework input, route, response, template, profiler, cache memoization,
query logs, authorization identity, controller, and action have already been reset when it runs. An
exception is allowed to propagate because accepting the next request with stale state is unsafe.

## Configuration overlay

Configuration is recursively merged from framework `config/` to application `config/`. The application only needs a PHP file with the same name that returns an array. Secrets and environment differences belong in `.env`; configuration files read them through `$_ENV`.

```php
use plato\config;

$database = config::instance('database')->get('connections.mysql');
$exists = config::instance('config')->has('middleware');
```

Lists merge by numeric index and are unsuitable for framework defaults that applications must be able to shorten. Each facade owns its configuration section and exposes `configure()` for process-local overrides plus `reset()` or `reset_config()` to return to file configuration.
