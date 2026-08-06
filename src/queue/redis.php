<?php

/**
 * Queue driver: redis lists for the ready messages, a sorted set for the delayed ones
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\queue;

use plato\cache\redis as client;
use plato\exception\queue_exception;

/**
 * Queue on redis lists.
 *
 * Four keys per queue, all built from the configured prefix so that a redis UI groups them and two
 * projects on one server stay apart:
 *
 *     <prefix><queue>            list, ready messages; RPUSH to push, BLPOP to take
 *     <prefix><queue>:delayed    sorted set, score = the unix time the message comes due
 *     <prefix><queue>:failed     list, what fail() moved aside for a human
 *     <prefix><queue>:reserved   unused here, see the delivery guarantee below
 *
 * **At most once.** BLPOP removes the message as it hands it over, so a consumer that dies with a
 * message in hand loses it: there is nothing left on the server to give to anybody else. That is
 * the trade this driver makes for being three commands deep and needing no bookkeeping, and it is
 * the right one for work that can be missed -- a cache warm, a nudge, a metric. Anything that must
 * not be lost belongs on a driver that keeps the message until it is acknowledged, which is what
 * the `stream` connection in config/queue.php is for.
 *
 * ack() therefore has nothing to do and says so by answering true; release() puts the message back
 * on the list -- at the tail, behind whatever arrived while it was being tried, so one poisonous
 * message cannot starve the rest of the queue.
 *
 * Two details that are load bearing rather than incidental:
 *
 *  - **the connection is its own.** Not the cache's: BLPOP holds the socket for as long as it
 *    blocks, and a cache read on the same connection would sit behind it. It also runs with the
 *    serializer turned off, because the envelope is already JSON and letting phpredis encode that
 *    string again would wrap it in a second layer no other consumer can read;
 *  - **keys are built here, not by phpredis.** The prefix option is left unset and every key is
 *    spelled out, so the names in redis are exactly the ones config/queue.php describes, and the
 *    KEYS a lua script is handed are the real ones -- a prefix the extension adds does not reach
 *    inside EVAL.
 *
 * Nothing connects until the first command: configure() is called for every connection the process
 * selects, and a process that only pushes to one of them must not open sockets to the others.
 */
class redis implements driver, delayable
{
    /**
     * Lua that moves due messages from the sorted set onto the list.
     *
     * One EVAL rather than a read, a write and a delete: three round trips lose whatever was in
     * flight when the process died, and a queue that drops messages on a worker restart is not a
     * queue. ZREM is what decides ownership -- only the caller whose ZREM returned 1 pushes, so two
     * workers migrating at the same moment cannot both deliver the same message.
     *
     * KEYS[1] delayed set, KEYS[2] ready list, ARGV[1] now, ARGV[2] limit.
     * Returns {moved, unix time the next message comes due or 0}.
     */
    private const MIGRATE_LUA = <<<'LUA'
local due = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', ARGV[1], 'LIMIT', 0, ARGV[2])
local moved = 0
for i = 1, #due do
    if redis.call('ZREM', KEYS[1], due[i]) == 1 then
        redis.call('RPUSH', KEYS[2], due[i])
        moved = moved + 1
    end
end
local next_due = 0
local head = redis.call('ZRANGE', KEYS[1], 0, 0, 'WITHSCORES')
if head[2] then
    next_due = tonumber(head[2])
end
return {moved, next_due}
LUA;

    /**
     * Connection settings, one entry of config/queue.php `connections`
     *
     * @var array<string, mixed>
     */
    private $_config = [];

    /**
     * Key prefix, from the configuration
     *
     * @var string
     */
    private $_prefix = '';

    /** @var client|null */
    private $_client = null;

    /**
     * @param array<string, mixed> $config
     *
     * @return void
     */
    public function configure(array $config): void
    {
        $this->close();

        $this->_config  = $config;
        $this->_prefix = (string) ($config['prefix'] ?? '');
    }

    /**
     * Push a message for immediate delivery.
     *
     * @param string               $queue   Queue name
     * @param mixed                $data    Payload
     * @param array<string, mixed> $options Ignored; this driver has no extras
     *
     * @return string|null  The message id, null when redis refused the write
     */
    public function push(string $queue, $data, array $options = [])
    {
        $message = new message($queue, $data);

        $length = $this->_redis()->rPush($this->_key($queue), $message->encode());

        return $length ? $message->id() : null;
    }

    /**
     * Push a message to be delivered later.
     *
     * @param string               $queue   Queue name
     * @param mixed                $data    Payload
     * @param int                  $delay   Seconds to hold it back
     * @param array<string, mixed> $options Ignored
     *
     * @return string|null
     */
    public function push_delay(string $queue, $data, int $delay, array $options = [])
    {
        if ( $delay <= 0 )
        {
            return $this->push($queue, $data, $options);
        }

        $message = new message($queue, $data, ['delay' => $delay]);

        // The envelope is the member, so two messages with the same payload have to differ
        // somewhere: message ids carry eight random bytes for exactly this reason
        $added = $this->_redis()->zAdd($this->_key($queue, 'delayed'), time() + $delay, $message->encode());

        return $added ? $message->id() : null;
    }

    /**
     * Take the next message off one of the queues.
     *
     * Waiting happens in redis, not in usleep(): BLPOP over every queue named, which also gives
     * the queues a priority -- the first one that has anything wins.
     *
     * @param string|array<int, string> $queues     Queue name, or several
     * @param int                       $timeout_ms How long to block; 0 or less does not block.
     *                                              Rounded up to whole seconds, the granularity
     *                                              BLPOP promises on every redis version
     *
     * @return message|null
     */
    public function pop($queues, int $timeout_ms = 1000)
    {
        $keys = [];

        foreach ( $this->_queues($queues) as $queue )
        {
            $keys[] = $this->_key($queue);
        }

        if ( !$keys )
        {
            return null;
        }

        $client = $this->_redis();

        if ( $timeout_ms <= 0 )
        {
            foreach ( $keys as $key )
            {
                $payload = $client->lPop($key);

                if ( is_string($payload) && $payload !== '' )
                {
                    return $this->_decode($payload, $key);
                }
            }

            return null;
        }

        $reply = $client->blPop($keys, (int) max(1, ceil($timeout_ms / 1000)));

        // [key, payload] when something arrived; an empty array or false on timeout
        if ( !is_array($reply) || count($reply) < 2 )
        {
            return null;
        }

        return $this->_decode((string) $reply[1], (string) $reply[0]);
    }

    /**
     * Nothing to acknowledge: BLPOP already removed the message.
     *
     * @param message $msg
     *
     * @return bool
     */
    public function ack(message $msg): bool
    {
        return true;
    }

    /**
     * Put a message back, at the tail of the queue or into the delayed set.
     *
     * @param message $msg   Message as returned by pop()
     * @param int     $delay Seconds to hold it back
     *
     * @return bool
     */
    public function release(message $msg, int $delay = 0): bool
    {
        $client = $this->_redis();

        if ( $delay > 0 )
        {
            return (bool) $client->zAdd(
                $this->_key($msg->queue(), 'delayed'),
                time() + $delay,
                $msg->encode()
            );
        }

        return (bool) $client->rPush($this->_key($msg->queue()), $msg->encode());
    }

    /**
     * Move a message to the failed list of its queue.
     *
     * The envelope keeps saying which queue it came from rather than being retargeted at the failed
     * list: whoever looks at it later needs to know where to put it back.
     *
     * @param message     $msg   Message as returned by pop()
     * @param string|null $error Why it failed
     *
     * @return bool
     */
    public function fail(message $msg, ?string $error = null): bool
    {
        $msg->set_error($error);

        return (bool) $this->_redis()->rPush($this->_key($msg->queue(), 'failed'), $msg->encode());
    }

    /**
     * How many messages are waiting on a queue, delayed ones not counted.
     *
     * @param string $queue Queue name
     *
     * @return int
     */
    public function size(string $queue): int
    {
        return (int) $this->_redis()->lLen($this->_key($queue));
    }

    /**
     * How many messages a queue is holding back, and how many it has given up on.
     *
     * Not part of the driver contract; the console reports it and an application may want it on a
     * status page.
     *
     * @param string $queue Queue name
     *
     * @return array{delayed: int, failed: int}
     */
    public function pending(string $queue): array
    {
        $client = $this->_redis();

        return [
            'delayed' => (int) $client->zCard($this->_key($queue, 'delayed')),
            'failed'  => (int) $client->lLen($this->_key($queue, 'failed')),
        ];
    }

    /**
     * Put messages from the failed list back on the queue.
     *
     * Not part of the driver contract; `queue:retry` calls it and checks for it first, the way it
     * does for pending(). One message at a time and LPOP first, so a message is never on both lists
     * at once -- the crash window loses the one in hand rather than duplicating it, which is the
     * same trade this driver already makes on pop().
     *
     * @param string $queue Queue name
     * @param int    $limit Most messages to move, 0 for all of them
     *
     * @return int  How many were put back
     */
    public function retry_failed(string $queue, int $limit = 0): int
    {
        $client = $this->_redis();
        $failed = $this->_key($queue, 'failed');
        $moved  = 0;

        while ( $limit <= 0 || $moved < $limit )
        {
            $payload = $client->lPop($failed);

            if ( !is_string($payload) || $payload === '' )
            {
                break;
            }

            $message = message::decode($payload);

            if ( $message === null )
            {
                // Not an envelope: put it back where it was rather than dropping it on the floor
                $client->rPush($failed, $payload);

                break;
            }

            $client->rPush($this->_key($message->queue()), $message->reset()->encode());
            $moved++;
        }

        return $moved;
    }

    /**
     * Move every message that has come due onto the list a consumer reads.
     *
     * @param string|array<int, string> $queues Queue name, or several
     * @param int                       $limit  Most messages to move in one call, per queue
     *
     * @return array{0:int,1:int}  Moved, and the unix time the next message comes due
     */
    public function migrate_delayed($queues, int $limit = 128): array
    {
        $client  = $this->_redis();
        $now     = time();
        $moved   = 0;
        $next_at = 0;

        foreach ( $this->_queues($queues) as $queue )
        {
            $reply = $client->eval(
                self::MIGRATE_LUA,
                [$this->_key($queue, 'delayed'), $this->_key($queue), $now, max(1, $limit)],
                2
            );

            if ( !is_array($reply) )
            {
                continue;
            }

            $moved += (int) ($reply[0] ?? 0);
            $due    = (int) ($reply[1] ?? 0);

            // The earliest of the queues, so a caller can sleep until then rather than poll
            if ( $due > 0 && ($next_at === 0 || $due < $next_at) )
            {
                $next_at = $due;
            }
        }

        return [$moved, $next_at];
    }

    /**
     * Release the connection.
     *
     * @return bool
     */
    public function close(): bool
    {
        if ( $this->_client === null )
        {
            return true;
        }

        $closed        = $this->_client->close();
        $this->_client = null;

        return $closed;
    }

    /**
     * The client wrapper, built on first use. Nothing is dialled until a command runs.
     *
     * @return client
     * @throws queue_exception When the phpredis extension is missing
     */
    private function _client(): client
    {
        if ( !class_exists('\Redis') )
        {
            throw new queue_exception(
                'the phpredis extension is required by the redis queue driver; install ext-redis or'
                . ' point the queue connection at another driver'
            );
        }

        if ( $this->_client === null )
        {
            $this->_client = new client($this->_name(), $this->_client_config());
        }

        return $this->_client;
    }

    /**
     * The phpredis handler this driver sends its commands to.
     *
     * The wrapper covers the cache store contract and nothing else; lists, sorted sets and EVAL
     * are the extension's own API, reached through client(). Asked for again on every call rather
     * than kept in a property, because that call is what notices a fork.
     *
     * @return \Redis|\RedisCluster
     * @throws queue_exception When the phpredis extension is missing
     */
    private function _redis()
    {
        return $this->_client()->client();
    }

    /**
     * Runtime name of this driver's connection.
     *
     * Distinct from the cache's, so the two get separate sockets: BLPOP holds one for as long as it
     * blocks.
     *
     * @return string
     */
    private function _name(): string
    {
        return 'queue.' . spl_object_id($this);
    }

    /**
     * Connection settings for the client.
     *
     * @return array<string, mixed>
     */
    private function _client_config(): array
    {
        $server = (array) ($this->_config['server'] ?? []);

        // Keys are spelled out by _key(); an extension side prefix would double up on them and
        // would not reach the KEYS of the migrate script
        $server['prefix'] = '';
        // The envelope is JSON already
        $server['serializer'] = $server['serializer'] ?? 'none';

        return $server;
    }

    /**
     * Full redis key of a queue.
     *
     * @param string $queue  Queue name
     * @param string $suffix 'delayed', 'failed', or '' for the ready list
     *
     * @return string
     */
    private function _key(string $queue, string $suffix = ''): string
    {
        return $this->_prefix . $queue . ($suffix === '' ? '' : ':' . $suffix);
    }

    /**
     * Normalise the queue argument to a list of names.
     *
     * @param string|array<int, string> $queues
     *
     * @return array<int, string>
     */
    private function _queues($queues): array
    {
        $list = [];

        foreach ( is_array($queues) ? $queues : [$queues] as $queue )
        {
            $queue = trim((string) $queue);

            if ( $queue !== '' )
            {
                $list[] = $queue;
            }
        }

        return $list;
    }

    /**
     * Turn a payload off the wire into a message.
     *
     * A payload that is not an envelope is moved to the failed list of the queue it was found on
     * rather than thrown over: something else wrote into this list, and stopping the consumer over
     * it would let one bad entry hold up everything behind it.
     *
     * @param string $payload Raw envelope
     * @param string $key     Key it came off, for the log line
     *
     * @return message|null
     */
    private function _decode(string $payload, string $key)
    {
        $message = message::decode($payload);

        if ( $message !== null )
        {
            return $message;
        }

        $this->_redis()->rPush($key . ':failed', $payload);

        return null;
    }
}
