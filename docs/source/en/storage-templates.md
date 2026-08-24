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

## Templates

```php
use plato\http\resp;
use plato\tpl;

tpl::assign('user', $user);
return resp::html(tpl::fetch('user/profile.tpl'));
```

`plato\tpl` is a facade over a driver implementing `plato\view\engine`. The contract is six calls -- `configure()`, `config()`, `assign()`, `exists()`, `fetch()`, `clear()` -- and rendering never echoes: `fetch()` answers with a string, so a resident worker can return a page as a reply.

Two drivers ship with the package, selected by `template.driver`:

| Driver | Templates | Dependency |
| --- | --- | --- |
| `plato\view\smarty` (default) | Smarty 5 | `composer require smarty/smarty:^5.5` |
| `plato\view\native` | Plain PHP files | none |

`plato\tpl` reads only `driver`; the rest of the `template` section is passed to the driver whole, so the available settings change with the driver. The Smarty driver takes template, compile and cache paths, delimiters, escaping and plugin directories; built-in plugins are registered directly and application plugins load from the configured plugin directories, with the application's definition of a name winning. HTML escaping is on by default there, and trusted HTML must be marked explicitly. `plato\view\smarty::raw()` returns the Smarty instance itself for the things the contract deliberately does not cover.

The `native` driver renders a `.php` file with the assigned variables extracted into its scope. It escapes nothing on your behalf -- PHP does not, and a driver that escaped on the way into `include` would corrupt every value a template emits as markup -- so templates call the helper:

```php
<h1><?= $this->e($title) ?></h1>
<?= $this->fetch('user/_badges') ?>
```

Both drivers assign `app_name`, `request` and `clear_cache` before each render, and leave a name the application already assigned alone. A template name that climbs out of the template directory is refused rather than included.

The engine is built on the first call that renders, so a JSON-only or CLI application never constructs one.
