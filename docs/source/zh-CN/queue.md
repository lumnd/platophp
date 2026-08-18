# 队列

队列门面支持 Redis list、Redis stream 和 Kafka。一个进程可同时持有多个命名连接，每个 driver 实例拥有独立配置和惰性资源。

```php
use plato\queue\queue;

$id = queue::push('emails', [
    'to' => 'user@example.com',
    'template' => 'welcome',
]);

queue::push('emails', $payload, ['delay' => 30]);
$size = queue::size('emails');
```

## 消费

```bash
php vendor/bin/plato queue:work --queue=emails --workers=4 --tries=3
php vendor/bin/plato queue:retry --queue=emails
```

消息 handler 可以是 callable、应用命令或配置解析出的处理器。处理成功后 `ack()`；可重试错误用 `release()` 延迟重投；超过次数或不可恢复错误用 `fail()` 进入死信。

Redis list 提供简单竞争消费；Redis stream 使用 consumer group 和 pending recovery；Kafka 使用 consumer group、partition offset 与独立 dead-letter topic。三种后端的投递保证不同，业务 handler 都必须按至少一次投递设计成幂等。

接管死亡消费者遗留的 pending 条目，Redis 7.0 及以上走 `XAUTOCLAIM`，更低版本走 `XPENDING` + `XCLAIM`。两条路径行为一致，后者每次轮询多一次往返。

`queue:work --workers=N` 使用 `plato\pool` 启动固定数量的前台 worker。进程拉起、退出与日志轮转仍交给 systemd、supervisord 或容器运行时。
