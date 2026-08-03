<?php

/**
 * Queue driver contract: what the queue facade needs from a backend
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\queue;

/**
 * The eight calls a queue driver has to answer.
 *
 * Unlike the cache stores, the backends behind this interface are not equivalent: a redis list
 * forgets a message the moment it is popped, a redis stream keeps it pending until it is
 * acknowledged, and kafka does not know what a delayed message is. Rather than pretend otherwise,
 * the interface holds only what all three do the same way, and everything else is an interface of
 * its own -- delayable so far. The facade checks with is_a() before it calls one.
 *
 * A configured connection is one driver object. Two connections using the same implementation
 * therefore keep independent prefixes, consumer groups and sockets without subclasses or global
 * switching state.
 *
 * Nothing in configure() may connect. The backing extension may not even be loaded -- neither
 * redis nor rdkafka is a hard requirement of this package -- so a driver checks for it on its
 * first real call and throws queue_exception there, where the message can say which queue
 * connection asked for it.
 */
interface driver
{
    /**
     * Hand the driver its connection settings, replacing whatever it held.
     *
     * It has to be cheap and repeatable, and it must not open anything.
     *
     * @param array<string, mixed> $config One entry of config/queue.php `connections`
     *
     * @return void
     */
    public function configure(array $config): void;

    /**
     * Push a message for immediate delivery.
     *
     * @param string               $queue   Queue name
     * @param mixed                $data    Payload; anything json_encode() accepts
     * @param array<string, mixed> $options Driver specific extras, ignored where meaningless
     *
     * @return string|null  The message id, null when the backend refused it
     */
    public function push(string $queue, $data, array $options = []);

    /**
     * Take the next message, waiting up to $timeout_ms for one to arrive.
     *
     * Blocking is the only consumption primitive in this package: it is the one shape all three
     * backends offer natively, and it needs no event loop, so a plain CLI while() is a complete
     * consumer. Waiting happens in the backend, not in usleep().
     *
     * @param string|array<int, string> $queues     Queue name, or several to read from
     * @param int                       $timeout_ms How long to block; 0 returns immediately
     *
     * @return message|null  Null when nothing arrived before the timeout
     */
    public function pop($queues, int $timeout_ms = 1000);

    /**
     * Acknowledge a message, so the backend stops holding it against this consumer.
     *
     * A driver that pops destructively has nothing to do here and returns true; the call is still
     * made, because the worker cannot know which kind of driver it is talking to.
     *
     * @param message $msg Message as returned by pop()
     *
     * @return bool
     */
    public function ack(message $msg): bool;

    /**
     * Put a message back for another delivery, after $delay seconds.
     *
     * A driver that cannot delay ignores $delay and says so in its own docblock; the worker
     * checks for delayable before it relies on the wait actually happening.
     *
     * @param message $msg   Message as returned by pop()
     * @param int     $delay Seconds to hold it back
     *
     * @return bool
     */
    public function release(message $msg, int $delay = 0): bool;

    /**
     * Move a message out of the way for good: it has failed more often than the retry policy
     * allows and a human has to look at it.
     *
     * Where it lands is the driver's business -- a failed stream, a dead letter topic, a list --
     * but it is never silently dropped.
     *
     * @param message     $msg   Message as returned by pop()
     * @param string|null $error Why it failed, recorded on the envelope
     *
     * @return bool
     */
    public function fail(message $msg, ?string $error = null): bool;

    /**
     * How many messages are waiting on a queue.
     *
     * Messages that are delayed, or delivered and not yet acknowledged, are not counted: this is
     * what a consumer would find, not everything the backend is holding.
     *
     * @param string $queue Queue name
     *
     * @return int
     */
    public function size(string $queue): int;

    /**
     * Release the connection.
     *
     * @return bool
     */
    public function close(): bool;
}
