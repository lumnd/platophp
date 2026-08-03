# PlatoPHP

PlatoPHP 是面向 PHP 8 的轻量级服务框架，覆盖 php-fpm HTTP、常驻单请求 worker、socket 服务调度和多进程 CLI。它以 Composer library 安装到宿主项目，只提供框架能力，不携带后台、业务模型、应用骨架或示例站点。

## 核心能力

- HTTP 路由、中间件、请求解析、不可变响应与上传处理
- MySQL、ClickHouse、MongoDB 连接以及查询构造器、迁移、schema builder、seeder
- Redis、文件、Memcached、进程内缓存和分布式锁
- Redis list、Redis stream、Kafka 队列与多 worker 消费
- CLI 命令、cron 调度和前台多进程监督
- 常驻服务 driver 契约、连接对象与消息到控制器的调度，websocket 与 tcp 同一套
- CSRF、CORS、限流、输入校验、请求签名与加密信封
- 文件存储、Smarty 模板、日志、错误处理、事件和常用数据工具

## 运行模型

一个进程同一时刻处理一个请求。支持 php-fpm、fork 出的 CLI worker，以及串行处理请求的常驻 worker。框架请求状态使用静态门面，因此不支持同一进程内并发执行多个请求的协程模型。

进程资源统一由 `plato\runtime` 管理；`plato\pool` 在 fork 前清理资源，并监督固定数量的子进程。常驻入口在每次请求前建立新的请求边界。

## 常驻服务边界

本包不内置事件循环、socket 监听、协议分帧、TLS 或 worker 管理。适配器实现 `plato\server\driver`，并把完整消息交给 `plato\server\dispatcher`；它说什么协议由它自己决定，websocket 是默认值而不是上限。这样框架负责路由与请求状态，事件循环由独立适配器包负责。

从[安装](installation.md)开始，或先阅读[架构与目录](architecture.md)。
