<?php

/**
 * Queue driver: redis streams with a consumer group, at-least-once
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\queue;

use plato\cache\redis as client;
use plato\exception\queue_exception;
use RedisException;
use Throwable;

/**
 * Queue on redis streams.
 *
 * The same key layout as the list driver, so the two are recognisable side by side:
 *
 *     <prefix><queue>            stream, the ready messages; XADD to push, XREADGROUP to take
 *     <prefix><queue>:delayed    sorted set, score = the unix time the message comes due
 *     <prefix><queue>:failed     list, what fail() moved aside for a human
 *
 * **At-least-once**, which is the whole reason this driver exists next to `redis`. XREADGROUP hands
 * a message over and *keeps* it, in the group's pending list, until XACK says it was dealt with. A
 * consumer that dies mid-message leaves its entry pending; the next consumer to look, after
 * `claim_idle_ms`, takes it over and tries again. The cost of never losing a message is delivering
 * one twice, so **a handler has to be idempotent** -- there is no third option, and a queue that
 * claims to be exactly-once is a queue that has not thought about it.
 *
 * Two consequences worth knowing before choosing this over the list driver:
 *
 *  - **`claim_idle_ms` has to be longer than the slowest job.** It is the definition of "that
 *    consumer is dead": set it to 60s with a job that takes 90, and the job is handed to a second
 *    consumer while the first is still working on it. That is not a redelivery after a crash, it is
 *    two live consumers on one message.
 *  - **an acknowledged entry is deleted**, not only acknowledged. XACK alone takes the message out
 *    of the pending list but leaves it in the stream, so the stream grows without bound; this
 *    driver follows every XACK with an XDEL. A deployment that wants to keep the log for replay
 *    sets `maxlen` and can remove the XDEL by subclassing -- but the default has to be the one that
 *    does not fill up a server.
 *
 * The consumer name is `<hostname>:<pid>`, so XPENDING names a real process, and so a restarted
 * worker does not inherit its own previous name and immediately reclaim entries that another live
 * worker is holding.
 *
 * Nothing connects until the first command, and the connection is the same one the list driver
 * uses -- a process talks to one queue backend at a time.
 */
class stream implements driver, delayable
{
    /**
     * Field name every entry stores its envelope under.
     *
     * One field and not several: the envelope is already a self describing JSON document, and
     * spreading it across stream fields would give two places where the wire format is defined.
     */
    private const FIELD = 'm';

    /**
     * Lua that moves due messages from the sorted set into the stream.
     *
     * The list driver's script with RPUSH swapped for XADD; the ownership rule is the same, and it
     * is the one that matters: only the caller whose ZREM returned 1 writes the entry, so two
     * workers migrating at the same moment cannot both deliver the message.
     *
     * KEYS[1] delayed set, KEYS[2] stream, ARGV[1] now, ARGV[2] limit.
     * Returns {moved, unix time the next message comes due or 0}.
     */
    private const MIGRATE_LUA = <<<'LUA'
local due = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', ARGV[1], 'LIMIT', 0, ARGV[2])
local moved = 0
for i = 1, #due do
    if redis.call('ZREM', KEYS[1], due[i]) == 1 then
        redis.call('XADD', KEYS[2], '*', ARGV[3], due[i])
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

    /**
     * Consumer group every consumer of this connection joins
     *
     * @var string
     */
    private $_group = 'default';

    /**
     * Streams this process has already created the group on, so the attempt is made once per key
     *
     * @var array<string, bool>
     */
    private $_groups = [];

    /**
     * This consumer's name inside the group
     *
     * @var string
     */
    private $_consumer = '';

    /**
     * Whether this connection may send XAUTOCLAIM; null until the server has been asked its version
     *
     * @var bool|null
     */
    private $_autoclaim = null;

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
        $this->_group  = (string) ($config['group'] ?? 'default');

        // The group has to be created again against whatever server this connection points at, and
        // a different server may answer XAUTOCLAIM differently
        $this->_groups    = [];
        $this->_autoclaim = null;
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
        $key     = $this->_key($queue);

        $entry = $this->_add($key, $message->encode());

        return $entry === null ? null : $message->id();
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

        $added = $this->_redis()->zAdd($this->_key($queue, 'delayed'), time() + $delay, $message->encode());

        return $added ? $message->id() : null;
    }

    /**
     * Take the next message off one of the queues.
     *
     * Three things happen here, in this order, and the order is the point: entries abandoned by a
     * dead consumer are reclaimed first, so a crash does not leave a message behind while new ones
     * are served ahead of it; then new entries are read; and only if neither produced anything does
     * the call block.
     *
     * @param string|array<int, string> $queues     Queue name, or several, in priority order
     * @param int                       $timeout_ms How long to block; 0 or less does not block
     *
     * @return message|null
     */
    public function pop($queues, int $timeout_ms = 1000)
    {
        $names = $this->_queues($queues);

        if ( !$names )
        {
            return null;
        }

        foreach ( $names as $queue )
        {
            $this->_ensure_group($this->_key($queue));
        }

        // Abandoned first: an entry whose consumer died has already waited longer than anything new
        foreach ( $names as $queue )
        {
            $message = $this->_claim($queue);

            if ( $message !== null )
            {
                return $message;
            }
        }

        $message = $this->_read($names, 0);

        if ( $message !== null || $timeout_ms <= 0 )
        {
            return $message;
        }

        return $this->_read($names, $timeout_ms);
    }

    /**
     * Acknowledge a message and take it out of the stream.
     *
     * @param message $msg
     *
     * @return bool
     */
    public function ack(message $msg): bool
    {
        $handle = $this->_handle($msg);

        if ( $handle === null )
        {
            return false;
        }

        $client = $this->_redis();

        $acked = $client->xAck($handle['key'], $this->_group, [$handle['id']]);

        // XACK alone only clears the pending entry; without the XDEL the stream keeps every message
        // it has ever carried
        $client->xDel($handle['key'], [$handle['id']]);

        return (bool) $acked;
    }

    /**
     * Put a message back, as a new entry at the end of the stream or into the delayed set.
     *
     * A new entry rather than the pending one: an entry that is left pending comes back only after
     * claim_idle_ms, which would turn "retry in one second" into "retry in a minute". The old entry
     * is acknowledged and deleted, so the message exists exactly once either way.
     *
     * @param message $msg   Message as returned by pop()
     * @param int     $delay Seconds to hold it back
     *
     * @return bool
     */
    public function release(message $msg, int $delay = 0): bool
    {
        $client  = $this->_redis();
        $payload = $msg->encode();

        if ( $delay > 0 )
        {
            $ok = (bool) $client->zAdd($this->_key($msg->queue(), 'delayed'), time() + $delay, $payload);
        }
        else
        {
            $ok = $this->_add($this->_key($msg->queue()), $payload) !== null;
        }

        // Only once the copy is safely somewhere: a crash between the two leaves the message
        // pending, which is a redelivery, not a loss
        $ok && $this->ack($msg);

        return $ok;
    }

    /**
     * Move a message to the failed list of its queue.
     *
     * @param message     $msg   Message as returned by pop()
     * @param string|null $error Why it failed
     *
     * @return bool
     */
    public function fail(message $msg, ?string $error = null): bool
    {
        $msg->set_error($error);

        $pushed = (bool) $this->_redis()->rPush($this->_key($msg->queue(), 'failed'), $msg->encode());

        $pushed && $this->ack($msg);

        return $pushed;
    }

    /**
     * How many messages a consumer would still find on a queue.
     *
     * The length of the stream: acknowledged entries are deleted, so what is left is the sum of
     * what has not been delivered and what has been delivered and not yet acknowledged. Delayed
     * messages are not in the stream yet and are not counted.
     *
     * @param string $queue Queue name
     *
     * @return int
     */
    public function size(string $queue): int
    {
        return (int) $this->_redis()->xLen($this->_key($queue));
    }

    /**
     * How many messages a queue is holding back, how many are in flight, and how many failed.
     *
     * Not part of the driver contract; `queue:status` reports it.
     *
     * @param string $queue Queue name
     *
     * @return array{delayed: int, failed: int, pending: int}
     */
    public function pending(string $queue): array
    {
        $client = $this->_redis();
        $key    = $this->_key($queue);

        $this->_ensure_group($key);

        $summary = $client->xPending($key, $this->_group);

        return [
            'delayed' => (int) $client->zCard($this->_key($queue, 'delayed')),
            'failed'  => (int) $client->lLen($this->_key($queue, 'failed')),
            // [count, first id, last id, consumers]
            'pending' => is_array($summary) ? (int) ($summary[0] ?? 0) : 0,
        ];
    }

    /**
     * Put messages from the failed list back into the stream.
     *
     * Not part of the driver contract; `queue:retry` calls it and checks for it first. XADD before
     * LPOP would be the safer order here -- this driver does not lose messages elsewhere -- but the
     * failed list is a list, and a crash between the two would duplicate rather than lose. Losing a
     * message a human has already been asked to look at is the worse of the two, so the write comes
     * first and the removal second.
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
            $payload = $client->lIndex($failed, 0);

            if ( !is_string($payload) || $payload === '' )
            {
                break;
            }

            $message = message::decode($payload);

            if ( $message === null )
            {
                break;
            }

            if ( $this->_add($this->_key($message->queue()), $message->reset()->encode()) === null )
            {
                break;
            }

            // Removed by value, not by position: another process may have pushed onto the head
            // while the entry was being written
            $client->lRem($failed, $payload, 1);
            $moved++;
        }

        return $moved;
    }

    /**
     * Move every message that has come due into the stream a consumer reads.
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
                [
                    $this->_key($queue, 'delayed'),
                    $this->_key($queue),
                    $now,
                    max(1, $limit),
                    self::FIELD,
                ],
                2
            );

            if ( !is_array($reply) )
            {
                continue;
            }

            $moved += (int) ($reply[0] ?? 0);
            $due    = (int) ($reply[1] ?? 0);

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
     * Write one entry, trimming the stream when the configuration asks for it.
     *
     * @param  string $key     Stream key
     * @param  string $payload Encoded envelope
     * @return string|null  The entry id, null when the write failed
     */
    private function _add(string $key, string $payload)
    {
        $maxlen = (int) ($this->_config['maxlen'] ?? 0);
        $client = $this->_redis();

        // Approximate trimming: exact trimming makes XADD walk the stream, and the point of a cap
        // is to keep memory bounded, not to hold a precise number of entries
        $id = $maxlen > 0
            ? $client->xAdd($key, '*', [self::FIELD => $payload], $maxlen, true)
            : $client->xAdd($key, '*', [self::FIELD => $payload]);

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * One XREADGROUP across the named queues.
     *
     * @param  array<int, string> $queues
     * @param  int                $timeout_ms 0 does not block
     * @return message|null
     */
    private function _read(array $queues, int $timeout_ms)
    {
        $streams = [];

        foreach ( $queues as $queue )
        {
            // '>' means "entries never delivered to this group"
            $streams[$this->_key($queue)] = '>';
        }

        $reply = $this->_redis()->xReadGroup(
            $this->_group,
            $this->_consumer(),
            $streams,
            1,
            $timeout_ms > 0 ? $timeout_ms : null
        );

        if ( !is_array($reply) )
        {
            return null;
        }

        foreach ( $reply as $key => $entries )
        {
            foreach ( (array) $entries as $id => $fields )
            {
                return $this->_message((string) $key, (string) $id, (array) $fields);
            }
        }

        return null;
    }

    /**
     * Take over one entry another consumer stopped working on.
     *
     * XAUTOCLAIM in one round trip where it can be used, XPENDING plus XCLAIM everywhere else. Both
     * ask the same question -- what has been pending longer than claim_idle_ms.
     *
     * @param  string $queue
     * @return message|null
     */
    private function _claim(string $queue)
    {
        $idle = (int) ($this->_config['claim_idle_ms'] ?? 60000);

        if ( $idle <= 0 )
        {
            return null;
        }

        $client = $this->_redis();
        $key    = $this->_key($queue);

        if ( $this->_can_autoclaim() )
        {
            $reply = null;

            try
            {
                $reply = $client->xAutoClaim($key, $this->_group, $this->_consumer(), $idle, '0-0', 1);
            }
            catch ( RedisException $e )
            {
                $reply = false;
            }

            if ( $reply !== false )
            {
                // [cursor, entries, deleted]
                $entries = is_array($reply) ? (array) ($reply[1] ?? []) : [];

                foreach ( $entries as $id => $fields )
                {
                    return $this->_message($key, (string) $id, (array) $fields);
                }

                return null;
            }

            $this->_autoclaim = false;
        }

        $pending = $client->xPending($key, $this->_group, '-', '+', 1);

        if ( !is_array($pending) || !isset($pending[0][0]) )
        {
            return null;
        }

        $id = (string) $pending[0][0];

        // [id, consumer, idle ms, delivery count]
        if ( (int) ($pending[0][2] ?? 0) < $idle )
        {
            return null;
        }

        $claimed = $client->xClaim($key, $this->_group, $this->_consumer(), $idle, [$id]);

        foreach ( (array) $claimed as $claimed_id => $fields )
        {
            return $this->_message($key, (string) $claimed_id, (array) $fields);
        }

        return null;
    }

    /**
     * Whether this connection may send XAUTOCLAIM, decided once and remembered.
     *
     * "Does the server have the command" is the wrong question, and sending one to find out is how
     * a consumer stalls. XAUTOCLAIM exists from redis 6.2, but its reply grew a third element in
     * 7.0 -- the ids it dropped -- and phpredis 6 reads a fixed three. Against 6.2 the extension
     * therefore waits for the rest of a reply the server has already finished sending: every pop()
     * blocks until default_socket_timeout, a minute by default and never where it is disabled,
     * before the read error hands control back. Redis 5 is safe only by accident, answering ERR
     * unknown command before any of that can happen.
     *
     * So the gate is the server version rather than the command, and the answer is remembered for
     * the life of the connection -- configure() clears it, because the next server may differ.
     *
     * @return bool
     */
    private function _can_autoclaim(): bool
    {
        if ( $this->_autoclaim !== null )
        {
            return $this->_autoclaim;
        }

        // get_class_methods() rather than method_exists(), so a static analyser looking at one
        // particular phpredis build does not fold the check away
        $client_can = in_array('xautoclaim', array_map('strtolower', get_class_methods('\Redis')), true);
        $version    = $client_can ? $this->_server_version() : '';

        $this->_autoclaim = $version !== '' && version_compare($version, '7.0', '>=');

        return $this->_autoclaim;
    }

    /**
     * The oldest redis version this connection talks to, '' when it cannot be established.
     *
     * A cluster answers INFO once per master, and infos() suffixes each key with the node it came
     * from; the oldest node is the one that decides what may be sent, since any of them may serve
     * the next command.
     *
     * @return string
     */
    private function _server_version(): string
    {
        try
        {
            $infos = $this->_client()->infos();
        }
        catch ( Throwable $e )
        {
            return '';
        }

        $oldest = '';

        foreach ( $infos as $key => $value )
        {
            // 'redis_version' from a single server, 'redis_version(host:port)' from a cluster node
            if ( strpos((string) $key, 'redis_version') !== 0 )
            {
                continue;
            }

            $version = (string) $value;

            if ( $version !== '' && ($oldest === '' || version_compare($version, $oldest, '<')) )
            {
                $oldest = $version;
            }
        }

        return $oldest;
    }

    /**
     * Turn a stream entry into a message, or set it aside if it is not one.
     *
     * An entry that is not an envelope is acknowledged, deleted and moved to the failed list rather
     * than thrown over: something else wrote into this stream, and stopping the consumer would let
     * one bad entry hold up everything behind it. It would also come straight back on the next
     * claim, forever.
     *
     * @param  string              $key    Stream key
     * @param  string              $id     Entry id
     * @param  array<string,mixed> $fields Entry fields
     * @return message|null
     */
    private function _message(string $key, string $id, array $fields)
    {
        $payload = (string) ($fields[self::FIELD] ?? '');
        $message = message::decode($payload, ['key' => $key, 'id' => $id]);

        if ( $message !== null )
        {
            return $message;
        }

        $client = $this->_redis();
        $client->rPush($key . ':failed', $payload === '' ? json_encode($fields) : $payload);
        $client->xAck($key, $this->_group, [$id]);
        $client->xDel($key, [$id]);

        return null;
    }

    /**
     * The stream key and entry id a message was delivered as.
     *
     * @param  message $msg
     * @return array{key: string, id: string}|null  Null for a message this driver did not deliver
     */
    private function _handle(message $msg)
    {
        $handle = $msg->handle();

        if ( !is_array($handle) || !isset($handle['key'], $handle['id']) )
        {
            return null;
        }

        return ['key' => (string) $handle['key'], 'id' => (string) $handle['id']];
    }

    /**
     * Create the consumer group on a stream, once per key per process.
     *
     * MKSTREAM so the first consumer does not have to wait for the first producer. BUSYGROUP means
     * somebody else got there first, which is the expected answer rather than an error.
     *
     * @param  string $key Stream key
     * @return void
     */
    private function _ensure_group(string $key): void
    {
        if ( isset($this->_groups[$key]) )
        {
            return;
        }

        $this->_groups[$key] = true;

        try
        {
            // '$' would skip everything already in the stream: a group created after a producer
            // started would silently never see the backlog
            $this->_redis()->xGroup('CREATE', $key, $this->_group, '0', true);
        }
        catch ( RedisException $e )
        {
            if ( strpos($e->getMessage(), 'BUSYGROUP') === false )
            {
                throw $e;
            }
        }
    }

    /**
     * This process's name inside the consumer group.
     *
     * @return string
     */
    private function _consumer(): string
    {
        if ( $this->_consumer === '' )
        {
            $this->_consumer = gethostname() . ':' . getmypid();
        }

        return $this->_consumer;
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
                'the phpredis extension is required by the stream queue driver; install ext-redis or'
                . ' point the queue connection at another driver'
            );
        }

        if ( $this->_client === null )
        {
            $this->_client = new client('queue.' . spl_object_id($this), $this->_client_config());
        }

        return $this->_client;
    }

    /**
     * The phpredis handler this driver sends its commands to.
     *
     * The wrapper covers the cache store contract and nothing else; streams, consumer groups and
     * EVAL are the extension's own API, reached through client(). Asked for again on every call
     * rather than kept in a property, because that call is what notices a fork.
     *
     * @return \Redis|\RedisCluster
     * @throws queue_exception When the phpredis extension is missing
     */
    private function _redis()
    {
        return $this->_client()->client();
    }

    /**
     * Connection settings for the client.
     *
     * @return array<string, mixed>
     */
    private function _client_config(): array
    {
        $server = (array) ($this->_config['server'] ?? []);

        $server['prefix']     = '';
        $server['serializer'] = $server['serializer'] ?? 'none';

        return $server;
    }

    /**
     * Full redis key of a queue.
     *
     * @param string $queue  Queue name
     * @param string $suffix 'delayed', 'failed', or '' for the stream itself
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
}
