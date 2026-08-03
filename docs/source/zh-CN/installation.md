# 安装

## 环境要求

PlatoPHP 要求 PHP 8.0 或更高版本。必需扩展为 `json`、`mbstring`、`openssl` 和 `zlib`。

```bash
composer require lumnd/platophp
```

框架的运行期核心不强制安装其他 Composer 包。按能力选择依赖：

| 能力 | 依赖 |
| --- | --- |
| Smarty 模板 | `smarty/smarty:^5.5` |
| MySQL / MariaDB | `ext-pdo_mysql` |
| MongoDB | `ext-mongodb` |
| Redis 缓存、队列、锁 | `ext-redis` |
| Memcached | `ext-memcached` |
| Kafka | `ext-rdkafka` |
| HTTP 客户端、ClickHouse | `ext-curl` |
| 多进程监督 | `ext-pcntl`、`ext-posix` |
| XML 请求体 | `ext-simplexml` |
| Workerman 常驻服务 | `lumnd/plato-workerman` |
| PSR-3 / PSR-16 适配 | `psr/log`、`psr/simple-cache` |

缺少可选依赖时，只有对应能力会在首次使用时明确报错，其余框架功能仍可运行。

## 宿主项目

PlatoPHP 是框架包，不创建应用目录。宿主项目负责入口文件、配置、控制器、模板和运行期目录，并通过 Composer 注册自己的命名空间：

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

修改宿主项目的映射后运行 `composer dump-autoload`。框架不会修改宿主的 `composer.json`，也不会猜测类文件位置。

上面的前缀和目录名只是单应用骨架的约定，不是框架要求。框架只对控制器有约束：类名必须是 `ctl_{ct}`，命名空间由 [`controller_namespace`](bootstrap-configuration.md) 决定，默认 `control`。中间件和命令都以完整类名写在配置里，前缀任取。

## 一个仓库多个应用

一个仓库放多个应用（例如 admin 与 api）时，不要把 `control\` 同时映射到两个目录。Composer 的 PSR-4 映射是进程级的，同一前缀下第一个目录会赢下全部查找，于是 api 的请求会静默拿到 admin 的 `ctl_index`。给每个应用自己的前缀：

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

每个入口引导自己的 `app_path` 与 `controller_namespace`：

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

`app_path` 同时决定该应用的配置覆盖目录 `app_path/config`、模板目录 `app_path/template` 和默认的 `data_path`。多个应用要共用一份运行期目录时，显式传同一个 `data_path`。

`bin/plato` 从项目根的 `plato.config.php` 取引导配置，其中的 `controller_namespace` 只有一个值。`make:controller` 用它生成命名空间，文件则落在 `--app-path` 解析出的 `app_path/control` 下，两者在多应用布局里不会一起切换，生成后按目标应用改一下命名空间。`make:middleware` 与 `make:command` 的 stub 命名空间固定为 `middleware` 与 `command`，同样需要手工调整。

下一步配置最小[引导入口](bootstrap-configuration.md)。
