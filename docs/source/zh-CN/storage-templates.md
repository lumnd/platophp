# 存储与模板

## 文件存储

```php
use plato\storage\storage;

storage::put('reports/2026-07.csv', $contents);
$contents = storage::get('reports/2026-07.csv');
$url = storage::url('reports/2026-07.csv');
$files = storage::files('reports', true);
```

框架内置 `local` disk，并提供 `plato\storage\disk` 契约供宿主或适配器实现远端盘。逻辑路径必须是相对路径；绝对路径、`..`、NUL byte 和越界路径会被拒绝，而不是静默规范化。

`storage::extend()` 注册 driver class，`storage::disk('name')` 取得命名盘。driver 在首次操作时建立远端连接，不在配置或类加载阶段发请求。

## 模板

```php
use plato\http\resp;
use plato\tpl;

tpl::assign('user', $user);
return resp::html(tpl::fetch('user/profile.tpl'));
```

`plato\tpl` 是 driver 的门面，driver 实现 `plato\view\engine`。契约共六个方法——`configure()`、`config()`、`assign()`、`exists()`、`fetch()`、`clear()`——渲染不输出任何内容：`fetch()` 返回字符串，常驻 worker 因此可以把页面作为 reply 返回。

包内自带两个 driver，由 `template.driver` 选择：

| Driver | 模板形式 | 依赖 |
| --- | --- | --- |
| `plato\view\smarty`（默认） | Smarty 5 | `composer require smarty/smarty:^5.5` |
| `plato\view\native` | 纯 PHP 文件 | 无 |

`plato\tpl` 只读取 `driver`，`template` 配置段的其余部分整体透传给 driver，因此可用的配置键随 driver 而变。Smarty driver 接收模板目录、编译目录、缓存目录、分隔符、转义开关和 plugin 目录；框架内置插件直接注册，应用插件从配置的 plugin 目录加载，同名时应用的定义优先。该 driver 默认开启 HTML escaping，可信 HTML 必须显式标记。契约刻意不覆盖的部分通过 `plato\view\smarty::raw()` 取回 Smarty 实例本身。

`native` driver 渲染一个 `.php` 文件，把 assign 的变量 extract 进它的作用域。它不替你转义任何东西——PHP 本身不转义，而在 `include` 入口处静默转义会破坏模板刻意输出为标记的每一个值——所以模板调用辅助方法：

```php
<h1><?= $this->e($title) ?></h1>
<?= $this->fetch('user/_badges') ?>
```

两个 driver 都在每次渲染前注入 `app_name`、`request` 和 `clear_cache`，且不覆盖应用已经 assign 的同名变量。爬出模板目录的模板名会被拒绝，而不是被 include。

引擎在第一次渲染时才构建，只提供 JSON 或 CLI 的应用永远不会构建它。
