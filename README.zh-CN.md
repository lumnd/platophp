# PlatoPHP

[English](README.md) | **简体中文**

[![tests](https://github.com/lumnd/platophp/actions/workflows/tests.yml/badge.svg)](https://github.com/lumnd/platophp/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/lumnd/platophp/php?logo=php&logoColor=white)](https://packagist.org/packages/lumnd/platophp)
[![Latest version](https://img.shields.io/packagist/v/lumnd/platophp?logo=packagist&logoColor=white)](https://packagist.org/packages/lumnd/platophp)
[![License](https://img.shields.io/packagist/l/lumnd/platophp)](LICENSE)

PlatoPHP 是面向 PHP 8 的轻量级 HTTP、常驻 socket 与多进程 CLI 服务框架。它以 Composer library
安装，只提供框架能力，不附带后台 UI、领域模型、应用骨架、业务配置或示例站点。

## 环境要求

PHP 8.0 或更高版本，并安装 `json`、`mbstring`、`openssl`、`zlib`。CI 覆盖 PHP 8.0 至 8.5。

```bash
composer require lumnd/platophp
```

运行期核心没有强制第三方 Composer 依赖。可选能力在首次使用时检查对应依赖：

| 能力 | 依赖 |
| --- | --- |
| Smarty 模板 | `smarty/smarty:^5.5` |
| MySQL / MariaDB | `ext-pdo_mysql` |
| MongoDB | `ext-mongodb` |
| Redis 缓存、队列与分布式锁 | `ext-redis` |
| Memcached | `ext-memcached` |
| Kafka | `ext-rdkafka` |
| HTTP 客户端与 ClickHouse | `ext-curl` |
| 多进程监督 | `ext-pcntl`、`ext-posix` |
| XML 请求体 | `ext-simplexml` |
| Workerman 常驻服务 | `lumnd/plato-workerman` |
| PSR-3 / PSR-16 适配 | `psr/log`、`psr/simple-cache` |

## 快速开始

宿主项目负责入口文件、配置、控制器、模板和可写目录，并在自己的 Composer 配置里注册应用命名空间：

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

创建 HTTP 入口：

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

在 `app/control/ctl_index.php` 添加控制器：

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

默认路由 `/index/index` 会调用该 action。Controller 是宿主项目的普通类；PlatoPHP 不定义 Controller
或 Model 基类。

## 框架模块

- HTTP 路由、中间件、类型化输入、上传、不可变响应、Cookie 与 Session
- MySQL、ClickHouse、MongoDB driver 和绑定参数的查询构造器
- 迁移、schema builder 与可重复执行的 seeder
- Redis、文件、Memcached、进程内缓存与 Redis 分布式锁
- Redis list、Redis stream、Kafka 队列与多 worker 消费
- 面向 WebSocket、TCP 与自定义分帧传输的协议中立常驻服务契约
- CLI 命令、代码生成、cron 调度和前台多进程监督
- CSRF、CORS、限流、校验、请求签名与 AES-256-GCM 信封
- 文件存储契约、可选 Smarty 渲染、日志、性能分析、事件和数据工具
- PSR-3 与 PSR-16 薄适配层

## 运行模型

一个进程同一时刻处理一个请求。支持 php-fpm、fork 出的 CLI worker，以及串行处理请求的常驻
worker。请求状态使用静态门面，因此不支持在同一个进程内通过 coroutine/fiber 并发运行多个请求。

`plato\runtime` 管理进程私有资源，在 fork 后让继承的连接失效。`plato\pool` 监督固定数量的前台
worker。守护化、开机拉起、崩溃重启、pid 文件和日志轮转由 systemd、supervisord 或容器运行时负责。

## 常驻服务边界

本包提供 `plato\server\driver`、连接值对象、命名 server 实例，以及把一条完整消息交给 HTTP 控制器
管线的 dispatcher。它不实现 socket 监听、握手、分帧、心跳、TLS 或事件循环；独立适配器实现
driver 并负责这些能力。

listener 说什么协议由适配器决定：websocket 只是 `config/server.php` 的默认值，同一套契约同样可以
服务 TCP、行协议或自定义二进制协议。唯一的硬要求是适配器交给 dispatcher 的必须是一条完整的应用层
消息，而不是字节流。

## 配置

配置按框架 `config/` → 宿主 `config/` 递归叠加。环境差异和敏感值通过 `$_ENV` 从 `.env` 读取，
不使用环境后缀配置文件。连接与可选服务在首次实际使用时惰性创建。

## 命令行

```bash
php vendor/bin/plato --help
php vendor/bin/plato migrate
php vendor/bin/plato make:controller user
php vendor/bin/plato queue:work --queue=emails --workers=4
php vendor/bin/plato schedule:run
```

## 文档

- [简体中文文档](docs/source/zh-CN/index.md)
- [English documentation](docs/source/en/index.md)

生成站提供逐页对应的中英文导航和语言切换。

## 验证

仓库测试与门禁统一在配置好的 Docker PHP 容器内运行：

```bash
docker compose exec -T -e REDIS_HOST=redis6 php82 sh -lc \
  'cd /data/web/platophp && composer test'

docker compose exec -T php82 sh -lc \
  'cd /data/web/platophp && composer check:architecture && composer style && composer analyse'
```

## 版本

发布 tag 遵循 Semantic Versioning。`0.x` 阶段的公开 API 变更记录在 [CHANGELOG.md](CHANGELOG.md)，
公共 API 快照会阻止签名被静默修改。

参与开发前请阅读 [CONTRIBUTING.md](CONTRIBUTING.md)、[SECURITY.md](SECURITY.md) 与
[CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md)。

## 许可证

[MIT](LICENSE)
