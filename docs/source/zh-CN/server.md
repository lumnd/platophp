# 常驻服务

PlatoPHP 的 server 模块提供框架契约，不实现事件循环或协议栈。

启动 listener 前需要安装适配器。默认的 `workerman` driver 由独立包提供：

```bash
composer require lumnd/plato-workerman
```

其他适配器也可以注册 driver 短名称，或直接提供 driver FQCN。

## 组件职责

| 组件 | 职责 |
| --- | --- |
| 适配器 | socket、协议、握手、分帧、心跳、TLS、worker 生命周期 |
| `plato\server\driver` | 框架调用事件循环的七个方法，外加适配器必须回调的一个 |
| `plato\server\connection` | 连接标识、属性、发送与关闭 |
| `plato\server\dispatcher` | 一条完整 message 到一次 ct/ac 调度 |
| `plato\server\server` | 默认与命名 driver 门面 |

`config/server.php` 的 driver 可直接写适配器 FQCN。实例配置不连接 socket；适配器在首次实际操作时建立资源，并接入 `plato\runtime` 的 fork epoch。

## 协议

listener 说什么协议由适配器决定，在它自己的 `listen` 值里声明。`config/server.php` 默认给 WebSocket，因为那是绝大多数 listener 会说的协议，但同一套契约同样服务 TCP、行协议或自定义二进制协议——`plato\server\driver` 之上没有任何东西知道区别。

唯一的硬约束是分帧。`dispatcher::handle()` 接收的是一条完整的应用层消息，所以监听裸 `tcp://` 的适配器要自己负责组包，攒齐一条完整消息再调用 dispatcher。Workerman 的 protocol 类和 Swoole 的 `open_length_check` 是常规做法。跳过这一步的适配器会投递半条消息，而 dispatcher 分辨不出来。

## 消息边界

适配器把一条完整 message 与 connection 交给 `dispatcher::handle()`。dispatcher 调用 `plato::reset_request()`——常驻进程需要清理的请求状态清单由它维护——再从连接上取回身份写入 `plato::$auth`，然后运行与 HTTP 相同的控制器管线。非 null 响应写回同一连接。

不允许在一个 worker 中用 coroutine/fiber 并发 dispatch。跨 worker 广播不能依赖进程内连接数组，应由应用或独立 channel 适配器使用 Redis pub/sub 等外部总线完成。

## 进程模型

worker 进程数、降权、pid 文件和端口复用都是 `config/server.php` 里的适配器键；框架只读 `driver` 和 `dispatch`，其余原样透传。`plato\pool` 不监管 listener——它监管的是自己跑循环的进程，比如 queue worker——因为 event loop 的重启与热重载语义属于适配器。

适配器的每个 worker 必须在 fork 后、开始调度消息前调用 `plato\worker::enter($index, $count)`。应用代码随后可统一使用 `worker::index()`、`worker::count()` 与 `worker::owns($key)`，不需要知道进程由哪个适配器启动。
