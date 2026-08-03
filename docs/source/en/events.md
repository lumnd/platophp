# Events

`plato\event` is an in-process synchronous event bus.

```php
use plato\event;

$listener = event::on('invoice.created', function (string $event, array $invoice): void {
    audit_invoice($invoice);
});

event::trigger('invoice.created', [$invoice]);
event::off('invoice.created', $listener);
```

`one()` registers a listener for one invocation. `bind()` may limit invocation count. Listeners run synchronously in registration order, and exceptions propagate through the caller.

Listeners receive the event itself first, followed by the argument list passed to `trigger()`. The
framework triggers `ON_REQUEST`, `ON_FILTER`, and `ON_SQL`. The remaining constants are stable names
available for an application to trigger itself:

- `event::ON_REQUEST`
- `event::ON_FILTER`
- `event::ON_RESPONSE`
- `event::ON_SQL`
- `event::ON_ERROR`
- `event::ON_EXCEPTION`

Use stable string names for application events. Events exist only in the current process; cross-worker or cross-host notifications require queues, streams, Kafka, or another external message system.
