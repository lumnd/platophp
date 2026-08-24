# Installation

## Requirements

PlatoPHP requires PHP 8.0 or later. The required extensions are `json`, `mbstring`, `openssl`, and `zlib`.

```bash
composer require lumnd/platophp
```

The runtime core requires no other Composer packages. Install optional dependencies only for the capabilities you use:

| Capability | Dependency |
| --- | --- |
| Smarty templates (the `native` driver renders plain PHP with no dependency) | `smarty/smarty:^5.5` |
| MySQL / MariaDB | `ext-pdo_mysql` |
| MongoDB | `ext-mongodb` |
| Redis cache, queues, and locks | `ext-redis` |
| Memcached | `ext-memcached` |
| Kafka | `ext-rdkafka` |
| HTTP client and ClickHouse | `ext-curl` |
| Process supervision | `ext-pcntl`, `ext-posix` |
| XML request bodies | `ext-simplexml` |
| Workerman resident server | `lumnd/plato-workerman` |
| PSR-3 / PSR-16 adapters | `psr/log`, `psr/simple-cache` |

When an optional dependency is missing, the related capability fails clearly on first use while the rest of the framework remains available.

## What the installed package contains

A dist installation -- what `composer require` performs by default -- contains the runtime tree only: `src/`, `config/`, `bin/`, `composer.json`, and the package-facing `README`, `CHANGELOG`, `LICENSE`, and `SECURITY` files. The documentation sources, the test suite, the CI workflows, and the development configuration are marked `export-ignore` and are never downloaded, so `vendor/lumnd/platophp/docs` and `vendor/lumnd/platophp/tests` do not exist in a normal install. Read the documentation at [platophp.com](https://platophp.com) instead.

A source installation -- `composer require lumnd/platophp --prefer-source`, `composer install --prefer-source`, or a plain `git clone` -- clones the complete repository. `export-ignore` does not apply to it: `docs/` and `tests/` are present, and the package directory is a git working copy. That is the form to install for running the suite or sending a patch.

## Host project

PlatoPHP is a framework package and does not create application directories. The host owns entry points, configuration, controllers, templates, and writable runtime paths. It also registers application namespaces with Composer:

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

Run `composer dump-autoload` after changing host mappings. The framework neither edits the host's `composer.json` nor guesses class locations.

Those prefixes and directory names are a single-application convention, not a framework requirement. Controllers are the only thing the framework constrains: the class must be named `ctl_{ct}`, in the namespace [`controller_namespace`](bootstrap-configuration.md) names, which defaults to `control`. Middleware and commands are named by fully qualified class name in configuration, so their prefixes are free.

## Several applications in one repository

When one repository holds several applications -- admin and api, say -- do not map `control\` at two directories. Composer's PSR-4 map is process wide, the first directory registered under a prefix wins every lookup, and an api request would silently get the admin `ctl_index`. Give each application a prefix of its own:

```json
{
  "autoload": {
    "psr-4": {
      "admin\\control\\": "app/admin/control/",
      "admin\\middleware\\": "app/admin/middleware/",
      "api\\control\\": "app/api/control/",
      "api\\middleware\\": "app/api/middleware/",
      "command\\": "app/command/",
      "shared\\": "app/shared/"
    }
  }
}
```

Each entry point then bootstraps its own `app_path` and `controller_namespace`:

```php
// public/admin/index.php
plato::registry([
    'app_path'             => dirname(__DIR__, 2) . '/app/admin',
    'controller_namespace' => 'admin\control',
]);

// public/api/index.php
plato::registry([
    'app_path'             => dirname(__DIR__, 2) . '/app/api',
    'controller_namespace' => 'api\control',
]);
```

`app_path` also decides that application's configuration overlay directory `app_path/config`, its template directory `app_path/template`, and its default `data_path`. Pass the same `data_path` explicitly when several applications should share one writable runtime directory.

`bin/plato` reads its bootstrap configuration from `plato.config.php` in the project root, where `controller_namespace` holds a single value. `make:controller` writes that namespace into the stub while the file lands under the `app_path/control` that `--app-path` resolved, so in this layout the two do not switch together and the generated namespace needs correcting for the target application. The `make:middleware` and `make:command` stubs are fixed at `middleware` and `command` and need the same adjustment.

Next, create the minimal [bootstrap entry point](bootstrap-configuration.md).
