<?php

/**
 * Server driver contract: what the server facade needs from an event loop
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\server;

/**
 * The seven calls a server driver has to answer, and the one it has to make.
 *
 * **No driver ships with this package, and that is the design.** A resident socket server is an
 * event loop, a process manager and a protocol codec; none of the three is a framework's business,
 * and writing them here would mean maintaining a protocol parser -- the part of the stack where a
 * mistake is a remote memory exhaustion rather than a wrong page. The loop comes from outside, the
 * routing stays here, and this interface is the seam:
 *
 *   the driver owns   the listening socket, the protocol -- handshake, framing, keepalive -- the
 *                     worker processes, TLS, and its own configuration keys
 *   the framework owns   turning one inbound message into one ct / ac dispatch with clean request
 *                     state, which is server\dispatcher
 *
 * **Which protocol is the driver's business, not this package's.** Websocket is what most listeners
 * will speak and what config/server.php defaults to, but nothing above this interface knows that: a
 * driver is as free to serve a length prefixed binary protocol, a line protocol, or something an
 * application invented, and it says which in its own `listen` value. What it may not do is hand over
 * a byte stream. `dispatcher::handle()` takes one whole application message, so a driver listening
 * on a raw `tcp://` socket owns the framing and calls the dispatcher only once it holds a complete
 * one -- Workerman's protocol classes and Swoole's `open_length_check` are how that is normally
 * done. A driver that skips it delivers half messages, and the dispatcher cannot tell.
 *
 * So an adapter is thin. It answers these seven calls, and on the other side it hands every message
 * it receives to `dispatcher::handle()` and writes the returned payload -- when it is not null --
 * back onto the same connection. Opening and closing go to `dispatcher::open()` and
 * `dispatcher::close()`; a false from open() means the application refused the connection and the
 * driver has to close it.
 *
 * **The call it has to make is `plato\worker::enter()`**, in each worker process it forks, as soon
 * as that process knows its own index. That is how an action reached over this listener asks which
 * worker it is in -- the same way a queue consumer does, because `plato\pool` registers there too.
 * An adapter that skips it leaves every one of its workers believing it is alone, and a job meant
 * for one of them runs in all of them.
 *
 * Each configured listener is one driver object. Two listeners using the same adapter class keep
 * independent connection maps and settings without subclasses or global switching state.
 *
 * **A driver has to keep the connection objects it built.** `dispatcher` stores per connection
 * state on them -- the authenticated identity, above all -- and that state is only worth anything
 * if the same instance comes back on the next message. Rebuilding a connection object per message
 * silently logs every client out between messages.
 *
 * Nothing in configure() may bind a socket. The adapter package may not even be installed at the
 * point the configuration is read, so resolution failures are the facade's business and everything
 * else waits for start().
 *
 * The concurrency contract of the framework applies unchanged: one process handles one message at
 * a time. An adapter running a coroutine scheduler -- Swoole's, or Workerman 5 with fibers turned
 * on -- puts two dispatches inside one pid, and the request state in the static properties of
 * `req` and `plato` cannot survive that. Adapters keep coroutines off.
 */
interface driver
{
    /**
     * Hand the driver its settings.
     *
     * It has to be cheap and repeatable, and it must not bind or connect anything.
     *
     * @param array<string, mixed> $config One entry of config/server.php `servers`, driver specific
     *                                     keys included -- the framework passes the whole array
     *                                     through without reading past the keys it owns
     *
     * @return void
     */
    public function configure(array $config): void;

    /**
     * Bind the listening socket, start the workers, and run the loop.
     *
     * Blocks until stop() or a signal ends it, which is why bin/plato calls it last and nothing
     * else in the framework calls it at all.
     *
     * @return void
     */
    public function start(): void;

    /**
     * Stop the loop and let start() return.
     *
     * @return void
     */
    public function stop(): void;

    /**
     * Send a payload on one connection.
     *
     * @param string $id      Connection id, as carried by server\connection
     * @param string $payload Message payload, already encoded
     *
     * @return bool  False when the connection is not held by this process, or the write failed
     */
    public function send(string $id, string $payload): bool;

    /**
     * Close one connection.
     *
     * A protocol that has no notion of a reasoned close -- a plain tcp listener has none -- ignores
     * both arguments and closes the socket; the driver says so in its own docblock rather than
     * leaving the caller to find out.
     *
     * @param string $id     Connection id
     * @param int    $code   Close code as the driver's protocol defines it. The default is the
     *                       websocket vocabulary, since that is what most listeners speak: 1000
     *                       normal, 1008 policy violation, 1011 internal
     * @param string $reason Short text, never anything the client did not already know
     *
     * @return bool  False when the connection is not held by this process
     */
    public function close(string $id, int $code = 1000, string $reason = ''): bool;

    /**
     * The connection object registered under $id, or null when this process does not hold it.
     *
     * @param string $id Connection id
     *
     * @return connection|null
     */
    public function connection(string $id);

    /**
     * Ids of the connections **this process** holds.
     *
     * Never the ids of the whole server: workers do not share memory, and a driver that answered
     * otherwise would be guessing. Fanning out beyond one process needs a backend both processes
     * can see, which is server\channel, not this call.
     *
     * @return array<int, string>
     */
    public function connections(): array;
}
