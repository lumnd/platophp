<?php
// Queue settings. Connections are declared side by side under `connections`, `default` picks the
// one used by facade shortcuts; queue::connection() returns any named connection independently.
//
// The three drivers do not give the same delivery guarantee -- read this before picking one:
//   redis   list + zset, at-most-once, a popped message is gone, delays supported
//   stream  redis stream + consumer group, at-least-once, unacked messages can be reclaimed,
//           delays supported
//   kafka   offsets committed by hand, at-least-once, ordered per partition and replayable,
//           no delays
return [
    'default' => $_ENV['QUEUE_CONNECTION'] ?? 'redis',

    'connections' => [
        'redis' => [
            'driver' => 'redis',
            // The queue does not share the cache connection: the cache redis instance has JSON
            // serialization on, which would wrap the message envelope in another layer of JSON
            // that an outside consumer cannot read
            'server' => [
                'host'       => $_ENV['QUEUE_REDIS_HOST'] ?? $_ENV['REDIS_HOST'] ?? '127.0.0.1',
                'port'       => $_ENV['QUEUE_REDIS_PORT'] ?? $_ENV['REDIS_PORT'] ?? 6379,
                'pass'       => $_ENV['QUEUE_REDIS_PASSWORD'] ?? $_ENV['REDIS_PASSWORD'] ?? '',
                'keep-alive' => false,
                'timeout'    => 5,
                'dbindex'    => $_ENV['QUEUE_REDIS_DB'] ?? 4,
                'serializer' => 'none',
            ],
            // Prefix of every queue key, kept apart from the cache prefix so the two group
            // separately in a redis client
            'prefix' => ($_ENV['CACHE_PREFIX'] ?? 'platophp') . ':queue:',
        ],

        'stream' => [
            'driver' => 'stream',
            'server' => [
                'host'       => $_ENV['QUEUE_REDIS_HOST'] ?? $_ENV['REDIS_HOST'] ?? '127.0.0.1',
                'port'       => $_ENV['QUEUE_REDIS_PORT'] ?? $_ENV['REDIS_PORT'] ?? 6379,
                'pass'       => $_ENV['QUEUE_REDIS_PASSWORD'] ?? $_ENV['REDIS_PASSWORD'] ?? '',
                'keep-alive' => false,
                'timeout'    => 5,
                'dbindex'    => $_ENV['QUEUE_REDIS_DB'] ?? 4,
                'serializer' => 'none',
            ],
            'prefix'   => ($_ENV['CACHE_PREFIX'] ?? 'platophp') . ':queue:',
            'group'    => $_ENV['QUEUE_STREAM_GROUP'] ?? 'default',
            // A message read but not acked for this many milliseconds counts as belonging to a
            // dead consumer and may be taken over by another. Set it longer than the slowest job,
            // or a job still being worked on gets delivered a second time
            'claim_idle_ms' => 60000,
            // Longest a single stream may get before the oldest messages are dropped (approximate
            // trimming). 0 for no limit
            'maxlen'        => 0,
        ],

        'kafka' => [
            'driver'  => 'kafka',
            'brokers' => $_ENV['KAFKA_BROKERS'] ?? '',
            // Consumer group; consumers in one group split the partitions between them
            'group_id' => $_ENV['KAFKA_GROUP_ID'] ?? 'platophp',
            // sync: every message waits for the broker to acknowledge it before push() returns,
            //       which is what a message that must not be lost needs
            // async: buffered and flushed before the process exits -- higher throughput, but a
            //       kill -9 loses whatever is still buffered
            'flush_mode'      => $_ENV['KAFKA_FLUSH_MODE'] ?? 'async',
            'flush_timeout_ms' => 10000,
            // Where a failed message ends up, %s replaced by the original topic name
            'dead_letter_topic' => '%s.dlq',
            'conf' => [
                'security.protocol' => $_ENV['KAFKA_SECURITY_PROTOCOL'] ?? 'PLAINTEXT',
                'sasl.mechanisms'   => $_ENV['KAFKA_SASL_MECHANISMS'] ?? 'PLAIN',
                'sasl.username'     => $_ENV['KAFKA_SASL_USERNAME'] ?? '',
                'sasl.password'     => $_ENV['KAFKA_SASL_PASSWORD'] ?? '',
            ],
            // Settings that only librdkafka's consumer understands. Keep role-specific options
            // out of `conf`, because that block is also applied to the producer
            'consumer_conf' => [
                // Offsets are committed by hand, from ack(). Committing automatically would
                // confirm a message while the job is still running
                'enable.auto.commit' => 'false',
                'auto.offset.reset'   => 'earliest',
            ],
            'producer_conf' => [],
            'topic_conf' => [
                // -1 waits for every replica, 1 for the leader only, 0 does not wait
                'request.required.acks' => '-1',
            ],
        ],
    ],

    // Retry policy. The worker is the only place that applies it; where the message is kept while
    // it waits is the driver's business
    'retry' => [
        // Deliveries in total, the first one included. Past this the message goes to the dead
        // letter destination
        'max_attempts' => 3,
        // Seconds to wait before each retry. The last entry is reused once the list runs out
        'backoff' => [1, 5, 30],
        // When the driver has no delay of its own (kafka), the backoff is a sleep in the consuming
        // process and blocks that consumer -- so it is capped separately and the rest of the wait
        // is given up
        'inline_backoff_max' => 5,
    ],

    // plato\queue\worker, which `php plato queue:work` runs. One process, in the foreground:
    // running several of them, restarting one that exits and forwarding signals is a process
    // manager's job -- a systemd unit with Restart=always, a supervisor program, a container
    // replica count -- and every deployment already has one.
    'worker' => [
        // What runs a message. Any callable, handed the plato\queue\message. Left null, a payload
        // shaped ['ct' => ..., 'ac' => ...] is dispatched to that controller action instead, and a
        // payload that is not gets moved aside as unhandled
        'handler' => null,
        // How long one blocking read waits. Which queues are read is not configured here: it is
        // per process, and `queue:work --queue=emails,default` is where it belongs
        'timeout_ms' => 1000,
        // How often due messages are moved onto the queue a consumer reads, which is what the
        // delay accuracy comes down to. 100 works, at one more redis round trip per interval
        'migrate_interval_ms' => 1000,
        // Most messages one move handles. The move is a single lua script, so it needs no lock:
        // only the caller whose ZREM returned 1 delivers the message
        'migrate_limit' => 128,
        // Exit after this many messages, so that whatever leaks in the application goes with the
        // process and the supervisor starts a fresh one. 0 for no limit
        'max_jobs' => 1000,
        // Exit after this many seconds, same reasoning. 0 for no limit
        'max_lifetime' => 3600,
    ],
];
