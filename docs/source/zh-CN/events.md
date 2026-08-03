# 事件

`plato\event` 是进程内同步事件总线。

```php
use plato\event;

$listener = event::on('invoice.created', function (string $event, array $invoice): void {
    audit_invoice($invoice);
});

event::trigger('invoice.created', [$invoice]);
event::off('invoice.created', $listener);
```

`one()` 注册只触发一次的 listener；`bind()` 可限制调用次数。listener 按注册顺序同步执行，异常沿调用栈抛出。

listener 先收到事件本身，再收到传给 `trigger()` 的参数列表。框架会触发 `ON_REQUEST`、`ON_FILTER`
和 `ON_SQL`；其余常量是留给应用主动触发的稳定名称：

- `event::ON_REQUEST`
- `event::ON_FILTER`
- `event::ON_RESPONSE`
- `event::ON_SQL`
- `event::ON_ERROR`
- `event::ON_EXCEPTION`

应用自定义事件使用稳定字符串名。事件只存在于当前进程；跨 worker 或跨机器通知应使用队列、stream、Kafka 或其他外部消息系统。
