# Multiprocess Runtime

`plato\pool` is a foreground process supervisor. It forks a fixed number of workers, reaps and replaces children, forwards termination signals, and sends SIGKILL after the grace period.

```php
use plato\pool;

pool::supervise(function (int $slot): void {
    consume_partition($slot);
}, 4);
```

Production deployments should start the foreground process with systemd, supervisord, or a container runtime. The framework does not daemonize, write pid files, or implement process-manager `start/stop/restart/status` commands.

A resident socket server is supervised by its adapter instead. `processes`, `user`, `group`, `pid_file`, and `reuse_port` in `config/server.php` are adapter keys; the framework reads only `driver` and `dispatch` and passes the rest through. The restart and hot-reload semantics of an event loop belong to whoever owns the loop, so `plato\pool` does not try to supervise one.

## Process identity

`plato\worker` answers which of a group's processes this one is, whoever started the group. `plato\pool` claims it in the child right after the fork; a server adapter claims it in each worker process it starts.

```php
use plato\worker;

// Exactly one worker of the group runs this, without taking a lock
if ( worker::owns($account_id) )
{
    sweep($account_id);
}

worker::index();     // 0-based, -1 outside a group
worker::count();     // group size, 0 outside a group
worker::in_group();  // whether this process belongs to a group at all
```

Outside a group — a php-fpm request, a plain CLI script, a lone consumer — `owns()` returns true, because a process that is alone owns all of its own work and a guarded block has to keep working when it is run singly. The consequence is that an adapter which forks workers and never calls `worker::enter()` leaves every one of them believing it is alone, and work meant for one runs in all of them.

## Fork safety

MySQL, Redis, Memcached, Kafka, file handles, and other process-owned resources are registered with `plato\runtime`. The pool calls `runtime::flush()` before its first fork. After a direct `pcntl_fork()`, runtime detects the pid change, discards inherited state, and rebuilds resources when needed.

Inherited network resources are not actively closed in the child because some client teardowns affect the parent's session. Custom drivers holding handles must integrate with runtime and compare `runtime::epoch()` to detect that an object crossed a fork.

The framework guarantees only one active request per process. Resident workers clear request state on every loop and must not execute two requests concurrently in one pid.
