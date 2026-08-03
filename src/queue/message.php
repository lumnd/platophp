<?php

/**
 * Queue message: the envelope every driver reads and writes
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\queue;

use plato\exception\queue_exception;

/**
 * One message on its way through a queue.
 *
 * The class owns two things: the wire format, so a message written by the redis driver can be
 * read by the stream driver -- or by a consumer written in another language -- and the ack
 * handle, which is the opposite, a value only the driver that produced it understands.
 *
 * The wire format is JSON and deliberately flat:
 *
 *     {"id":"...","queue":"emails","data":{...},"attempts":0,"time":1690000000,"delay":60}
 *
 * The field names match what the workerman redis queue and the redis stream implementations this
 * was modelled on already write, so a queue can be drained by the old consumer while the new one
 * fills it. `error` is added once a message has failed, and nothing else is ever added: a driver
 * that needs to carry more state keeps it in its own handle, not in the envelope.
 *
 * The handle is whatever the driver needs to acknowledge this exact delivery -- a stream key plus
 * a message id, an RdKafka\Message, nothing at all for a driver that pops destructively. It never
 * goes on the wire, so a message that comes back from a backend has one and a message that has
 * just been built does not.
 *
 * Instances are mutable: the worker increments the attempt count and records an error on the way
 * to a retry, and copying the payload for each of those would be pointless. Treat one as owned by
 * whoever popped it.
 */
class message
{
    /**
     * Unique id, generated on construction and preserved across retries
     *
     * @var string
     */
    private $_id = '';

    /**
     * Queue this message belongs to
     *
     * @var string
     */
    private $_queue = '';

    /**
     * Application payload; anything json_encode() accepts
     *
     * @var mixed
     */
    private $_payload = null;

    /**
     * Deliveries so far, zero until the first failure
     *
     * @var int
     */
    private $_attempts = 0;

    /**
     * Unix time the message was first pushed, kept across retries
     *
     * @var int
     */
    private $_created_at = 0;

    /**
     * Seconds the message was held back before its first delivery
     *
     * @var int
     */
    private $_delay = 0;

    /**
     * Why the last delivery failed, null while it has not
     *
     * @var string|null
     */
    private $_error = null;

    /**
     * Driver private acknowledgement handle; never serialized
     *
     * @var mixed
     */
    private $_handle = null;

    /**
     * @param string               $queue   Queue name
     * @param mixed                $payload Application payload
     * @param array<string, mixed> $meta    Envelope fields to restore; omit when building a new
     *                                      message, from_array() is what fills it
     */
    public function __construct(string $queue, $payload, array $meta = [])
    {
        $this->_queue      = $queue;
        $this->_payload    = $payload;
        $this->_id         = isset($meta['id']) ? (string) $meta['id'] : self::new_id();
        $this->_attempts   = isset($meta['attempts']) ? (int) $meta['attempts'] : 0;
        $this->_created_at = isset($meta['time']) ? (int) $meta['time'] : time();
        $this->_delay      = isset($meta['delay']) ? (int) $meta['delay'] : 0;
        $this->_error      = isset($meta['error']) ? (string) $meta['error'] : null;
    }

    /**
     * Rebuild a message from a decoded envelope.
     *
     * Returns null rather than throwing when the array is not an envelope, because the caller is
     * always a driver holding something it just read off a queue: garbage belongs in the failed
     * queue, not in an exception that stops the consumer.
     *
     * @param  mixed $row    Decoded envelope
     * @param  mixed $handle Driver private acknowledgement handle
     * @return self|null
     */
    public static function from_array($row, $handle = null)
    {
        if ( !is_array($row) || !isset($row['queue']) || !array_key_exists('data', $row) )
        {
            return null;
        }

        $message = new self((string) $row['queue'], $row['data'], $row);
        $message->set_handle($handle);

        return $message;
    }

    /**
     * Rebuild a message from its wire form.
     *
     * Takes mixed rather than string because the caller is a driver passing on whatever the
     * backend handed back, which is false on a failed read and an array on a malformed stream
     * entry as readily as it is the string that was written.
     *
     * @param  mixed $json   Encoded envelope
     * @param  mixed $handle Driver private acknowledgement handle
     * @return self|null  Null when the value is not a valid envelope
     */
    public static function decode($json, $handle = null)
    {
        if ( !is_string($json) || $json === '' )
        {
            return null;
        }

        return self::from_array(json_decode($json, true), $handle);
    }

    /**
     * The envelope as it goes on the wire.
     *
     * @return array<string, mixed>
     */
    public function to_array(): array
    {
        $row = [
            'id'       => $this->_id,
            'queue'    => $this->_queue,
            'data'     => $this->_payload,
            'attempts' => $this->_attempts,
            'time'     => $this->_created_at,
            'delay'    => $this->_delay,
        ];

        if ( $this->_error !== null )
        {
            $row['error'] = $this->_error;
        }

        return $row;
    }

    /**
     * The envelope encoded for a backend.
     *
     * Slashes and unicode are left alone: the result is read by humans often enough -- in redis
     * itself, in a failed queue, in a log line -- and escaping them only makes it longer.
     *
     * @return string
     * @throws queue_exception When the payload cannot be encoded, which is what a closure, a
     *                         resource or a non UTF-8 string ends up as
     */
    public function encode(): string
    {
        $json = json_encode($this->to_array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ( $json === false )
        {
            throw new queue_exception(sprintf(
                'queue message %s cannot be encoded: %s',
                $this->_id,
                json_last_error_msg()
            ));
        }

        return $json;
    }

    /**
     * @return string
     */
    public function id(): string
    {
        return $this->_id;
    }

    /**
     * @return string
     */
    public function queue(): string
    {
        return $this->_queue;
    }

    /**
     * @return mixed
     */
    public function payload()
    {
        return $this->_payload;
    }

    /**
     * @return int
     */
    public function attempts(): int
    {
        return $this->_attempts;
    }

    /**
     * @return int
     */
    public function created_at(): int
    {
        return $this->_created_at;
    }

    /**
     * @return int
     */
    public function delay(): int
    {
        return $this->_delay;
    }

    /**
     * @return string|null
     */
    public function error()
    {
        return $this->_error;
    }

    /**
     * The driver private acknowledgement handle, null for a message that has not been delivered.
     *
     * @return mixed
     */
    public function handle()
    {
        return $this->_handle;
    }

    /**
     * Records one more delivery. Called by the worker before a retry, so the count is what has
     * been tried rather than what is about to be.
     *
     * @return $this
     */
    public function attempted()
    {
        $this->_attempts++;

        return $this;
    }

    /**
     * Forget the attempt count and the error, so this counts as a first delivery again.
     *
     * What `queue:retry` does to a message it takes off the failed list: the id stays, so the
     * message can still be followed through the logs, but the retry policy starts over -- a message
     * put back with its attempt count intact would go straight back to the failed list.
     *
     * @return $this
     */
    public function reset()
    {
        $this->_attempts = 0;
        $this->_error    = null;

        return $this;
    }

    /**
     * @param  string|null $error
     * @return $this
     */
    public function set_error($error)
    {
        $this->_error = $error === null ? null : (string) $error;

        return $this;
    }

    /**
     * @param  mixed $handle
     * @return $this
     */
    public function set_handle($handle)
    {
        $this->_handle = $handle;

        return $this;
    }

    /**
     * Retargets the message, so a driver can move it to a failed or retry queue without the
     * envelope still claiming to belong to the queue it came from.
     *
     * @param  string $queue
     * @return $this
     */
    public function set_queue(string $queue)
    {
        $this->_queue = $queue;

        return $this;
    }

    /**
     * A new message id.
     *
     * Time first so ids sort roughly in creation order, which makes a queue dump readable, then
     * eight random bytes. uniqid() was not enough: it is derived from the clock alone, and two
     * workers pushing in the same microsecond got the same id -- which, on a sorted set keyed by
     * the encoded envelope, silently dropped one of the two messages.
     *
     * @return string
     */
    public static function new_id(): string
    {
        return dechex(time()) . bin2hex(random_bytes(8));
    }
}
