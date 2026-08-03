# Storage and Templates

## File storage

```php
use plato\storage\storage;

storage::put('reports/2026-07.csv', $contents);
$contents = storage::get('reports/2026-07.csv');
$url = storage::url('reports/2026-07.csv');
$files = storage::files('reports', true);
```

The framework includes the `local` disk and the `plato\storage\disk` contract for remote implementations supplied by hosts or adapters. Logical paths must be relative. Absolute paths, `..`, NUL bytes, and escaping paths are rejected rather than silently normalized.

`storage::extend()` registers a driver class, and `storage::disk('name')` returns a named disk. A driver establishes remote resources on first operation, never while reading configuration or loading a class.

## Smarty templates

Templates are optional:

```bash
composer require smarty/smarty:^5.5
```

```php
use plato\http\resp;
use plato\tpl;

tpl::assign('user', $user);
return resp::html(tpl::fetch('user/profile.tpl'));
```

The `template` section controls template, compile, and cache paths plus delimiters. Built-in plugins are registered directly; application plugins load from configured plugin directories. HTML escaping is enabled by default, and trusted HTML must be marked explicitly.

`plato\tpl` is a small Smarty facade, not a generic template engine abstraction. JSON-only and CLI applications do not need Smarty.
