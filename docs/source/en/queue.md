# Queue

The queue facade supports Redis lists, Redis streams, and Kafka. A process may hold several named connections, each with independent driver configuration and lazy resources.

```php
use plato\queue\queue;

$id = queue::push('emails', [
    'to' => 'user@example.com',
    'template' => 'welcome',
]);

queue::push('emails', $payload, ['delay' => 30]);
$size = queue::size('emails');
```

## Consuming

```bash
php vendor/bin/plato queue:work --queue=emails --workers=4 --tries=3
php vendor/bin/plato queue:retry --queue=emails
```

A message handler may be a callable, application command, or configured resolver target. Successful work calls `ack()`; retryable failure uses `release()` for delayed redelivery; exhausted or permanent failure uses `fail()` to dead-letter the message.

Redis lists provide simple competing consumers. Redis streams use consumer groups and pending recovery. Kafka uses consumer groups, partition offsets, and a separate dead-letter topic. Delivery guarantees differ, but application handlers must always be idempotent for at-least-once delivery.

`queue:work --workers=N` uses `plato\pool` for a fixed number of foreground workers. Startup, restart policy, and log rotation remain the responsibility of systemd, supervisord, or the container runtime.
