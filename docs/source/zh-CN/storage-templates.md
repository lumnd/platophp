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

## Smarty 模板

模板是可选能力：

```bash
composer require smarty/smarty:^5.5
```

```php
use plato\http\resp;
use plato\tpl;

tpl::assign('user', $user);
return resp::html(tpl::fetch('user/profile.tpl'));
```

模板目录、编译目录、缓存和分隔符由 `template` 配置段控制。框架内置插件直接注册；应用插件从配置的 plugin 目录加载。默认开启 HTML escaping，可信 HTML 必须显式标记。

`plato\tpl` 是 Smarty 的轻量门面，不是通用模板引擎抽象。只提供 JSON 或 CLI 的应用无需安装 Smarty。
