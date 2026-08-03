<?php

/**
 * Queue driver: kafka topics through the rdkafka extension, at-least-once
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\queue;

use plato\exception\queue_exception;
use plato\runtime;
use RdKafka\Conf;
use RdKafka\KafkaConsumer;
use RdKafka\Message as kafka_message;
use RdKafka\Producer;
use RdKafka\TopicConf;
use Throwable;

/**
 * Queue on kafka.
 *
 * A queue name is a topic name. There is no prefix and no `:delayed` or `:failed` sibling: the
 * other two drivers build keys, kafka has topics, and pretending otherwise would produce topics
 * called `platophp:queue:emails` that no other tool in a kafka deployment expects.
 *
 * **At-least-once, with the offset committed by ack().** `enable.auto.commit` is forced to false in
 * config/queue.php and again here, because the default -- committing on a timer -- acknowledges a
 * message while the handler is still working on it, and a crash then loses it. So a handler has to
 * be idempotent, exactly as with the stream driver.
 *
 * What kafka does **not** do, and what this driver therefore does not pretend to:
 *
 *  - **no delay.** A log is not a schedule. The class does not implement `delayable`, so
 *    `queue::push($queue, $data, ['delay' => 60])` is refused by the facade rather than delivered
 *    immediately. A deployment that needs delays on kafka runs a per-delay topic and a forwarder,
 *    which is an application's design decision and not a driver's.
 *  - **no per message acknowledgement.** An offset is a position, not a set: committing message 7
 *    commits 1 to 6 as well. That is why release() **re-produces** the message rather than
 *    declining to commit it -- declining would replay everything after it too.
 *  - **no queue depth.** size() answers the consumer lag of the group, which is the closest thing
 *    kafka has, and it costs a metadata call plus one watermark query per partition. It is for a
 *    status page, not for a hot loop.
 *
 * Ordering is per partition, and messages are produced with no key, so they are spread across
 * partitions and ordering across the topic is not guaranteed. A payload that has to stay in order
 * relative to another one is a partitioning decision the application makes, not a queue setting;
 * `push()` takes a `key` option for it.
 *
 * The producer and the consumer are separate objects with separate connections, both registered
 * with plato\runtime so a forked child builds its own rather than writing into the socket it
 * inherited. Nothing is built until the first push or the first pop: a process that only produces
 * must not join a consumer group.
 */
class kafka implements driver
{
    /**
     * Runtime key of the producer
     */
    private const PRODUCER = 'queue.kafka.producer';

    /**
     * Runtime key of the consumer
     */
    private const CONSUMER = 'queue.kafka.consumer';

    /**
     * Connection settings, one entry of config/queue.php `connections`
     *
     * @var array<string, mixed>
     */
    private $config = [];

    /**
     * Topics the consumer is currently subscribed to, so a pop() over the same list does not
     * re-subscribe on every call
     *
     * @var array<int, string>
     */
    private $_subscribed = [];

    /**
     * Whether a delivery report received during the current flush contains an error
     *
     * @var bool
     */
    private $_delivery_failed = false;

    /**
     * @param array<string, mixed> $config
     *
     * @return void
     */
    public function configure(array $config): void
    {
        $this->config = $config;

        // The connection may now point at a different cluster or group
        runtime::forget($this->_runtime_key(self::PRODUCER));
        runtime::forget($this->_runtime_key(self::CONSUMER));
        $this->_subscribed = [];
    }

    /**
     * Produce a message.
     *
     * @param string               $queue   Topic name
     * @param mixed                $data    Payload
     * @param array<string, mixed> $options `key` to pin the message to a partition
     *
     * @return string|null  The message id, null when the broker refused it
     */
    public function push(string $queue, $data, array $options = [])
    {
        $message = new message($queue, $data);
        $topic   = $this->_producer()->newTopic($queue, $this->_topic_conf());

        $topic->produce(
            RD_KAFKA_PARTITION_UA,
            0,
            $message->encode(),
            isset($options['key']) ? (string) $options['key'] : null
        );

        return $this->_flush() ? $message->id() : null;
    }

    /**
     * Take the next message off one of the topics.
     *
     * @param string|array<int, string> $queues     Topic name, or several
     * @param int                       $timeout_ms How long to block; 0 or less still costs one
     *                                              short poll, which is the smallest thing rdkafka
     *                                              can be asked to do
     *
     * @return message|null
     */
    public function pop($queues, int $timeout_ms = 1000)
    {
        $topics = $this->_queues($queues);

        if ( !$topics )
        {
            return null;
        }

        $consumer = $this->_consumer();

        if ( $topics !== $this->_subscribed )
        {
            $consumer->subscribe($topics);
            $this->_subscribed = $topics;
        }

        $reply = $consumer->consume(max(1, $timeout_ms));

        // Not errors: the broker says "nothing right now", "you have read to the end" and "no such
        // topic" through the same channel as a real failure.
        //
        // The last one is why a consumer may start before its producer: a topic nobody has produced
        // to does not exist yet, and answering that with an exception would kill a worker that was
        // merely early. It is the same answer the two redis drivers give for a queue key that is not
        // there -- nothing to read -- and on a broker with topic auto creation the first push makes
        // the topic appear without the consumer having to be restarted.
        $quiet = [
            RD_KAFKA_RESP_ERR__TIMED_OUT,
            RD_KAFKA_RESP_ERR__PARTITION_EOF,
            RD_KAFKA_RESP_ERR__UNKNOWN_TOPIC,
            RD_KAFKA_RESP_ERR_UNKNOWN_TOPIC_OR_PART,
        ];

        if ( in_array($reply->err, $quiet, true) )
        {
            return null;
        }

        if ( $reply->err !== RD_KAFKA_RESP_ERR_NO_ERROR )
        {
            throw new queue_exception('kafka consume failed: ' . (string) $reply->errstr());
        }

        return $this->_message($reply);
    }

    /**
     * Commit the offset of a message.
     *
     * An offset is a position: this commits everything up to and including this message on its
     * partition, which is what makes ordered, single consumer processing cheap and out of order
     * acknowledgement impossible.
     *
     * @param message $msg
     *
     * @return bool
     */
    public function ack(message $msg): bool
    {
        $handle = $msg->handle();

        if ( !$handle instanceof kafka_message )
        {
            return false;
        }

        try
        {
            $this->_consumer()->commit($handle);

            return true;
        }
        catch ( Throwable $e )
        {
            return false;
        }
    }

    /**
     * Put a message back by producing it again.
     *
     * Not by withholding the commit: an offset commits everything before it, so refusing to commit
     * this one would replay every message after it as well. Producing a copy costs one duplicate id
     * in the log and keeps the rest of the partition moving.
     *
     * `$delay` is **ignored**, and this class says so by not implementing `delayable`. The worker
     * checks for that interface and sleeps in the consumer instead, bounded by
     * `retry.inline_backoff_max` in config/queue.php.
     *
     * @param message $msg   Message as returned by pop()
     * @param int     $delay Ignored
     *
     * @return bool
     */
    public function release(message $msg, int $delay = 0): bool
    {
        $topic = $this->_producer()->newTopic($msg->queue(), $this->_topic_conf());
        $topic->produce(RD_KAFKA_PARTITION_UA, 0, $msg->encode());

        $ok = $this->_flush(true);

        // Only once the copy is on the broker: a crash between the two replays the message, which
        // is a redelivery rather than a loss
        return $ok && $this->ack($msg);
    }

    /**
     * Produce a message to the dead letter topic and commit the original.
     *
     * @param message     $msg   Message as returned by pop()
     * @param string|null $error Why it failed
     *
     * @return bool
     */
    public function fail(message $msg, ?string $error = null): bool
    {
        $msg->set_error($error);

        // The envelope keeps naming the topic it came from, so whoever looks at the dead letter
        // topic later knows where to put it back
        $topic = $this->_producer()->newTopic($this->dead_letter_topic($msg->queue()), $this->_topic_conf());
        $topic->produce(RD_KAFKA_PARTITION_UA, 0, $msg->encode());

        $ok = $this->_flush(true);

        return $ok && $this->ack($msg);
    }

    /**
     * The dead letter topic of a topic.
     *
     * `dead_letter_topic` in the connection settings, with %s replaced by the topic name.
     *
     * @param string $queue Topic name
     *
     * @return string
     */
    public function dead_letter_topic(string $queue): string
    {
        $pattern = (string) ($this->config['dead_letter_topic'] ?? '%s.dlq');

        return strpos($pattern, '%s') === false ? $pattern : sprintf($pattern, $queue);
    }

    /**
     * Consumer lag: how far the group is behind the end of the topic.
     *
     * The nearest thing kafka has to a queue depth, and not the same thing: it is per consumer
     * group, it counts what this group has not committed rather than what exists, and it costs a
     * metadata call plus a watermark query per partition. Fine for `queue:status`, not for a loop.
     *
     * @param string $queue Topic name
     *
     * @return int  0 when the topic is unknown or the lag cannot be read
     */
    public function size(string $queue): int
    {
        $consumer = $this->_consumer();

        try
        {
            $metadata   = $consumer->getMetadata(false, $consumer->newTopic($queue), 10000);
            $partitions = [];

            foreach ( $metadata->getTopics() as $topic )
            {
                if ( $topic->getTopic() !== $queue )
                {
                    continue;
                }

                foreach ( $topic->getPartitions() as $partition )
                {
                    $partitions[] = new \RdKafka\TopicPartition($queue, $partition->getId());
                }
            }

            if ( !$partitions )
            {
                return 0;
            }

            $lag = 0;

            foreach ( $consumer->getCommittedOffsets($partitions, 10000) as $committed )
            {
                $low  = 0;
                $high = 0;
                $consumer->queryWatermarkOffsets($queue, $committed->getPartition(), $low, $high, 10000);

                $offset = $committed->getOffset();
                // A partition this group has never read from reports an invalid offset; everything
                // on it is outstanding
                $offset = $offset < 0 ? $low : $offset;

                $lag += max(0, $high - $offset);
            }

            return $lag;
        }
        catch ( Throwable $e )
        {
            return 0;
        }
    }

    /**
     * Flush the producer and close the consumer.
     *
     * @return bool
     */
    public function close(): bool
    {
        runtime::forget($this->_runtime_key(self::PRODUCER));
        runtime::forget($this->_runtime_key(self::CONSUMER));
        $this->_subscribed = [];

        return true;
    }

    /**
     * Wait for the broker to confirm what has been produced, or do not.
     *
     * `sync` waits after every message: slower, and the only mode in which a `push()` that returned
     * an id means the broker has it. `async` returns at once and flushes when the process exits, so
     * a `kill -9` loses whatever was still buffered.
     *
     * @param bool $force Wait even when ordinary pushes use async mode
     *
     * @return bool
     */
    private function _flush(bool $force = false): bool
    {
        $producer = $this->_producer();
        $this->_delivery_failed = false;

        // Serves the delivery report callbacks either way, which is what stops the internal queue
        // from filling up in a long lived producer
        $producer->poll(0);

        if ( !$force && (string) ($this->config['flush_mode'] ?? 'async') !== 'sync' )
        {
            return true;
        }

        $result = $producer->flush((int) ($this->config['flush_timeout_ms'] ?? 10000));

        // flush() invokes the extension callback, which static analysis cannot observe mutating this property.
        // @phpstan-ignore-next-line
        return $result === RD_KAFKA_RESP_ERR_NO_ERROR && !$this->_delivery_failed;
    }

    /**
     * The producer, built on the first push.
     *
     * @return Producer
     * @throws queue_exception When the rdkafka extension is missing
     */
    private function _producer(): Producer
    {
        $this->_require_extension();

        return runtime::share($this->_runtime_key(self::PRODUCER), function (): Producer
        {
            $producer = new Producer($this->_conf('producer'));

            return $producer;
        }, function (Producer $producer): void
        {
            $producer->flush((int) ($this->config['flush_timeout_ms'] ?? 10000));
        });
    }

    /**
     * The consumer, built on the first pop.
     *
     * @return KafkaConsumer
     * @throws queue_exception When the rdkafka extension is missing
     */
    private function _consumer(): KafkaConsumer
    {
        $this->_require_extension();

        return runtime::share($this->_runtime_key(self::CONSUMER), function (): KafkaConsumer
        {
            $conf = $this->_conf('consumer');
            $conf->set('group.id', (string) ($this->config['group_id'] ?? 'platophp'));
            // Again here and not only in config/queue.php: an application that replaced the
            // connection block wholesale must not end up committing offsets on a timer
            $conf->set('enable.auto.commit', 'false');

            return new KafkaConsumer($conf);
        }, function (KafkaConsumer $consumer): void
        {
            $consumer->close();
        });
    }

    /**
     * Client configuration: shared settings, then settings for one client role.
     *
     * @param string $role `producer` or `consumer`
     *
     * @return Conf
     */
    private function _conf(string $role): Conf
    {
        $conf    = new Conf();
        $brokers = trim((string) ($this->config['brokers'] ?? ''));

        if ( $brokers === '' )
        {
            throw new queue_exception('the kafka queue connection has no brokers configured');
        }

        $conf->set('metadata.broker.list', $brokers);

        $settings = (array) ($this->config['conf'] ?? []);
        $settings = array_replace($settings, (array) ($this->config[$role . '_conf'] ?? []));

        foreach ( $settings as $key => $value )
        {
            // An empty SASL username is not a setting, it is the absence of one; passing it makes
            // librdkafka refuse the whole configuration
            if ( $value === '' || $value === null )
            {
                continue;
            }

            $conf->set((string) $key, (string) $value);
        }

        if ( $role === 'producer' )
        {
            $conf->setDrMsgCb(function ($producer, kafka_message $message): void
            {
                if ( $message->err !== RD_KAFKA_RESP_ERR_NO_ERROR )
                {
                    $this->_delivery_failed = true;
                }
            });
        }

        return $conf;
    }

    /**
     * Per topic configuration, `topic_conf` in the connection settings.
     *
     * @return TopicConf
     */
    private function _topic_conf(): TopicConf
    {
        $conf = new TopicConf();

        foreach ( (array) ($this->config['topic_conf'] ?? []) as $key => $value )
        {
            if ( $value === '' || $value === null )
            {
                continue;
            }

            $conf->set((string) $key, (string) $value);
        }

        return $conf;
    }

    /**
     * @return void
     * @throws queue_exception When the rdkafka extension is missing
     */
    private function _require_extension(): void
    {
        if ( !class_exists('\RdKafka\Producer') )
        {
            throw new queue_exception(
                'the rdkafka extension is required by the kafka queue driver; install ext-rdkafka or'
                . ' point the queue connection at another driver'
            );
        }
    }

    /**
     * Turn a broker message into one of ours, or set it aside if it is not an envelope.
     *
     * A payload that is not an envelope goes to the dead letter topic and its offset is committed:
     * something else is producing into this topic, and stopping the consumer over it would hold up
     * everything behind it forever.
     *
     * @param  kafka_message $reply
     * @return message|null
     */
    private function _message(kafka_message $reply)
    {
        $message = message::decode((string) $reply->payload, $reply);

        if ( $message !== null )
        {
            return $message;
        }

        $topic = $this->_producer()->newTopic(
            $this->dead_letter_topic((string) $reply->topic_name),
            $this->_topic_conf()
        );
        $topic->produce(RD_KAFKA_PARTITION_UA, 0, (string) $reply->payload);

        if ( !$this->_flush(true) )
        {
            throw new queue_exception('could not confirm the kafka dead letter copy');
        }

        try
        {
            $this->_consumer()->commit($reply);
        }
        catch ( Throwable $e )
        {
            throw new queue_exception('could not commit the invalid kafka record', 0, $e);
        }

        return null;
    }

    /**
     * Normalise the queue argument to a list of topic names.
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
     * Give each configured driver object its own runtime resource slots.
     */
    private function _runtime_key(string $type): string
    {
        return $type . '.' . spl_object_id($this);
    }
}
