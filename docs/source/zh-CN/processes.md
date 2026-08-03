# 多进程运行

`plato\pool` 是前台进程监督器：fork 固定数量的 worker，回收退出的子进程并补位，收到终止信号后转发，宽限期结束仍未退出时发送 SIGKILL。

```php
use plato\pool;

pool::supervise(function (int $slot): void {
    consume_partition($slot);
}, 4);
```

生产环境应由 systemd、supervisord 或容器运行时启动这个前台进程。框架不 daemonize、不写 pid 文件，也不实现 `start/stop/restart/status` 进程管理命令。

常驻 socket 服务的进程由适配器自己监管。`config/server.php` 里的 `processes`、`user`、`group`、`pid_file`、`reuse_port` 都是适配器的键，框架只读 `driver` 和 `dispatch`，其余原样透传。event loop 的重启与热重载语义属于持有循环的一方，所以 `plato\pool` 不去接管。

## 进程身份

`plato\worker` 回答"我是这组进程里的第几个"，不关心这组是谁拉起来的。`plato\pool` 在子进程 fork 后立即登记；server 适配器在自己启动的每个 worker 进程里登记。

```php
use plato\worker;

// 这组里恰好一个 worker 会执行，不需要加锁
if ( worker::owns($account_id) )
{
    sweep($account_id);
}

worker::index();     // 从 0 开始，不在 group 里时为 -1
worker::count();     // group 大小，不在 group 里时为 0
worker::in_group();  // 这个进程是否属于某个 group
```

不在任何 group 里时——php-fpm 请求、普通 CLI 脚本、单独跑的消费者——`owns()` 返回 true：单进程拥有自己的全部工作，带守卫的代码单独跑也必须照常执行。代价是：fork 了 worker 却从不调用 `worker::enter()` 的适配器，会让每个 worker 都以为自己是唯一的那个，本该只跑一次的工作在所有进程里都跑。

## Fork 安全

MySQL、Redis、Memcached、Kafka、文件句柄和其他进程私有资源由 `plato\runtime` 登记。pool 在第一次 fork 前 `runtime::flush()`；裸 `pcntl_fork()` 之后，runtime 会通过 pid 变化丢弃继承状态并按需重建。

继承来的网络资源不在子进程里主动 close，因为某些客户端 teardown 会影响父进程会话。持有句柄的自定义 driver 也应接入 runtime，并用 `runtime::epoch()` 判断对象是否跨过 fork。

框架只保证一个进程同一时刻处理一个请求。常驻 worker 每次循环需要清理请求状态；不要在同一 pid 内并发执行两个请求。
