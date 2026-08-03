# 引导与配置

## 最小入口

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

`app_path` 必填。`env_path` 默认是项目根的 `.env`，`data_path` 默认是 `app_path/data`。日志和缓存目录从 `data_path` 派生。

## Registry 选项

常用选项包括：

| 选项 | 用途 |
| --- | --- |
| `env` | 显式环境名；否则读取 `APP_ENV`，最后使用 `pub` |
| `debug` | 控制框架调试输出；不传时不修改宿主设置 |
| `bootstrap` | 核心引导前调用的应用回调 |
| `controller_namespace` | 控制器命名空间，默认 `control` |
| `session_start` | 是否由框架启动 PHP session |
| `check_purview_handle` | action 前的应用鉴权回调 |
| `error_handle` | 渲染中间件够不到的失败（未知控制器、不可路由的 action）的应用回调 |
| `reset_handle` | 常驻进程中，框架请求状态清空后调用的应用回调 |
| `cli_auth` / `cli_csrf` | CLI 与常驻服务入口是否执行鉴权和 CSRF |

`reset_handle` 不接收参数，用于清理框架无法知晓、由应用持有的静态请求态。调用它时，框架的请求
输入、路由、响应、模板、profiler、缓存 memoization、查询日志、鉴权身份、controller 和 action
都已清空。回调抛出的异常会继续上抛，因为带着未清理的旧状态接收下一次请求并不安全。

## 配置叠加

配置按“框架 `config/` → 应用 `config/`”递归合并。应用只需放同名 PHP 文件并返回数组。敏感值和环境差异放在 `.env`，配置文件通过 `$_ENV` 读取。

```php
use plato\config;

$database = config::instance('database')->get('connections.mysql');
$exists = config::instance('config')->has('middleware');
```

列表按数组下标合并，不适合在框架默认配置中放不可缩短的列表。各框架门面拥有自己的配置段，并提供 `configure()` 做进程内覆盖以及 `reset()` 或 `reset_config()` 恢复文件配置。
