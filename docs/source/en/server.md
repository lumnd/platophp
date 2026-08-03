# Resident servers

The PlatoPHP server module supplies framework contracts, not an event loop or a protocol stack.

Install an adapter before starting a listener. The default `workerman` driver is provided separately:

```bash
composer require lumnd/plato-workerman
```

Another adapter may instead register a short driver name or provide its driver FQCN directly.

## Responsibilities

| Component | Responsibility |
| --- | --- |
| Adapter | sockets, protocol, handshake, framing, keepalive, TLS, worker lifecycle |
| `plato\server\driver` | seven methods through which the framework controls the loop, plus one call the adapter has to make |
| `plato\server\connection` | identity, attributes, send, and close |
| `plato\server\dispatcher` | one complete message to one ct/ac dispatch |
| `plato\server\server` | default and named driver facade |

The driver in `config/server.php` may be an adapter FQCN. Instance configuration does not bind sockets. The adapter creates resources on first real use and follows the `plato\runtime` fork epoch.

## Protocol

Which protocol a listener speaks is the adapter's choice, declared in its own `listen` value. WebSocket is what `config/server.php` defaults to because it is what most listeners speak, but the same contract serves TCP, a line protocol, or a custom binary one — nothing above `plato\server\driver` knows the difference.

The one requirement is framing. `dispatcher::handle()` takes one whole application message, so an adapter listening on a raw `tcp://` socket owns the framing and calls the dispatcher only once it holds a complete message. Workerman protocol classes and the Swoole `open_length_check` option are the usual way to do this. An adapter that skips it delivers partial messages and the dispatcher cannot detect that.

## Message boundary

An adapter passes one complete message and its connection to `dispatcher::handle()`. The dispatcher calls `plato::reset_request()` — which owns the list of request state a resident process has to clear — then sets `plato::$auth` from the connection, and runs the same controller pipeline as HTTP. A non-null reply is written to the same connection.

Do not dispatch concurrently with coroutines or fibers inside one worker. Cross-worker broadcasts cannot use an in-process connection array; the application or a channel adapter must use Redis pub/sub or another external bus.

## Process model

Worker processes, privilege dropping, pid files, and port reuse are adapter keys in `config/server.php`; the framework reads only `driver` and `dispatch` and passes the rest through untouched. `plato\pool` does not supervise a listener — it supervises processes that run a loop of their own, such as a queue worker — because the restart and hot-reload semantics of an event loop belong to the adapter.

Each adapter worker must call `plato\worker::enter($index, $count)` after the fork and before dispatching messages. Application code can then use `worker::index()`, `worker::count()`, and `worker::owns($key)` without depending on which adapter started the process.
