# 架构与目录

PlatoPHP 是一个 Composer framework package。仓库根目录就是包根目录，`src/` 只放框架代码，应用和业务能力属于依赖本包的宿主项目。

## 模块

```text
src/
├── plato.php runtime.php worker.php pool.php log.php cli.php
├── http/       路由、请求、响应、中间件、HTTP 客户端
├── database/   连接、查询、方言、迁移、schema、seeder
├── cache/      门面、仓库、store 契约与四个实现
├── queue/      门面、driver 契约、消息、worker 与三个实现
├── console/    命令内核、生成器、迁移、队列、调度、填充
├── security/   CSRF、校验、限流、签名、加密
├── storage/    disk 契约、路径校验、本地盘
├── server/     协议中立 driver、连接对象、dispatcher 与门面
├── debug/      benchmark、profiler、错误处理
├── psr/        PSR-3 与 PSR-16 薄适配
└── exception/  框架异常
```

`plato\` 命名空间与 `src/` 路径严格一一对应。例如 `src/security/validate.php` 声明 `plato\security\validate`。

## 框架边界

框架提供通用协议和运行机制，不定义业务表、业务状态、后台 UI、领域模型、支付或第三方 SDK。宿主应用通过公开接口组合这些能力。面向具体产品的目录、权限模型和业务文档应由对应项目维护。

## 两个边界

请求边界负责清理一次请求产生的静态状态；进程边界负责让 fork 后的连接与文件句柄重新建立。前者由 `plato::reset_request()` 处理，每个常驻入口都调用它——`plato\server\dispatcher` 在每条消息前、`plato\queue\worker` 在每个任务前。应用自己的请求态通过 registry 的 `reset_handle` 清理，该回调在框架状态清空后执行。进程边界由 `plato\runtime` 处理。

该设计适合 php-fpm、前台多进程 CLI 和串行常驻 worker，不支持一个进程内同时执行多个请求的协程。
