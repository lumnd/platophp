<?php

/**
 * Queue facade: dispatches to the redis / stream / kafka driver named by the configuration
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\queue;

use plato\config;
use plato\exception\queue_exception;
use plato\runtime;

/**
 * Queue facade.
 *
 * The static methods are shortcuts for the configured default connection. Named connections are
 * independent driver objects returned by connection(), so selecting one cannot alter later calls
 * elsewhere in the process.
 *
 * The three drivers do not offer the same guarantees, and the facade does not paper over it:
 *
 *  - queue\redis   list plus sorted set. At most once: a popped message is gone, and a worker
 *                  that dies mid job takes it with it. Delayed messages, no acknowledgement.
 *  - queue\stream  redis streams with a consumer group. At least once: a message stays pending
 *                  until it is acknowledged, and one left behind by a dead worker is claimed back.
 *                  Delayed messages.
 *  - queue\kafka   at least once through manual offset commits, partitioned, replayable. No
 *                  delayed messages -- a delayed push against it raises queue_exception rather
 *                  than arriving early.
 *
 * Pick per queue, not per project: connections are declared side by side in config/queue.php.
 *
 *     queue::push('emails', ['to' => $address]);
 *     queue::push('emails', $payload, ['delay' => 60]);
 *
 *     queue::connection('kafka')->push('events', $payload);
 *
 * Consumption is a blocking read, and worker wraps it in a loop with retries and a process pool:
 *
 *     while ( $msg = queue::pop('emails', 1000) )
 *     {
 *         handle($msg->payload());
 *         queue::ack($msg);
 *     }
 */
class queue
{
    private const SHARE_PREFIX = 'queue.connection.';

    /**
     * Queue configuration, null until config() reads it or configure() hands it over
     *
     * @var array<string, mixed>|null
     */
    private static $_config = null;

    /**
     * Short driver name to class, extended by register_driver()
     *
     * @var array<string, string>
     */
    private static $_drivers = [
        'redis'  => __NAMESPACE__ . '\redis',
        'stream' => __NAMESPACE__ . '\stream',
        'kafka'  => __NAMESPACE__ . '\kafka',
    ];

    /** @var array<string, true> */
    private static $_built = [];

    /**
     * Hand the facade its configuration, instead of letting it read config/queue.php.
     *
     * Merges on top of the file settings, so an override names only what it changes. A named
     * `connections` key replaces the whole block, which is what a test wanting exactly one
     * connection is after.
     *
     * @param  array<string, mixed> $config Same shape as config/queue.php
     * @return void
     */
    public static function configure(array $config): void
    {
        self::$_config = $config + (array) self::config();

        self::_flush_connections();
    }

    /**
     * Drop the overrides, so the next read comes from the file again.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::_flush_connections();

        self::$_config = null;
    }

    /**
     * The configuration, read from config/queue.php on the first call that needs it.
     *
     * Everything about this module is deferred to first use: the configuration is not read, the
     * driver class is not resolved, and no connection is opened until something actually asks for
     * a queue. A project that has this package installed but never touches a queue pays nothing,
     * and a misconfigured connection is reported by the call that used it rather than by whatever
     * happened to mention queue::class first.
     *
     * @param string|null $key One setting, or null for all of them
     *
     * @return mixed
     */
    public static function config(?string $key = null)
    {
        if ( self::$_config === null )
        {
            self::$_config = (array) config::instance('queue')->get();
        }

        return $key === null ? self::$_config : (self::$_config[$key] ?? null);
    }

    /**
     * Return one independently configured connection.
     *
     * @param string|null $name Key of the `connections` config, or the configured default
     * @return driver
     * @throws queue_exception When the connection is not configured or its driver cannot be found
     */
    public static function connection(?string $name = null): driver
    {
        $name = $name ?? (string) (self::config('default') ?? '');

        if ( $name === '' )
        {
            throw new queue_exception('no default queue connection is configured');
        }

        $config = self::config('connections')[$name] ?? null;

        if ( !is_array($config) )
        {
            throw new queue_exception(sprintf('queue connection "%s" is not configured', $name));
        }

        self::$_built[$name] = true;

        return runtime::share(
            self::SHARE_PREFIX . $name,
            static function () use ($config, $name): driver
            {
                $class  = self::_resolve((string) ($config['driver'] ?? $name), $name);
                $driver = new $class();
                $driver->configure($config);

                return $driver;
            },
            static function (driver $driver): void
            {
                $driver->close();
            }
        );
    }

    /**
     * Register an application driver, or replace one of the three built in ones.
     *
     * @param  string $name  Short name, as used by the `driver` key of a connection
     * @param  string $class Class implementing plato\queue\driver
     * @return void
     * @throws queue_exception When the class does not implement the driver interface
     */
    public static function register_driver(string $name, string $class): void
    {
        if ( !is_a($class, driver::class, true) )
        {
            throw new queue_exception(sprintf(
                'queue driver "%s" must implement %s, %s does not',
                $name,
                driver::class,
                $class
            ));
        }

        self::$_drivers[$name] = $class;
    }

    /**
     * Alias of connection(), retained as the concise way to inspect or extend a driver.
     *
     * @param string|null $name
     * @return driver
     */
    public static function driver(?string $name = null): driver
    {
        return self::connection($name);
    }

    /**
     * Whether the active driver can hold a message back until it is due.
     *
     * @return bool
     * @throws queue_exception When no connection has been selected
     */
    public static function can_delay(?string $name = null): bool
    {
        return self::connection($name) instanceof delayable;
    }

    /**
     * Push a message.
     *
     * @param  string               $queue   Queue name
     * @param  mixed                $data    Payload; anything json_encode() accepts
     * @param  array<string, mixed> $options `delay` in seconds, plus driver specific extras
     * @return string|null  The message id, null when the backend refused it
     * @throws queue_exception When a delay is asked of a driver that cannot delay
     */
    public static function push(string $queue, $data, array $options = [])
    {
        $driver = self::connection();
        $delay  = (int) ($options['delay'] ?? 0);

        if ( $delay <= 0 )
        {
            return $driver->push($queue, $data, $options);
        }

        if ( !$driver instanceof delayable )
        {
            throw new queue_exception(sprintf(
                'queue connection "%s" (%s) cannot delay a message; push without `delay`, or use a connection backed by redis',
                (string) self::config('default'),
                get_class($driver)
            ));
        }

        unset($options['delay']);

        return $driver->push_delay($queue, $data, $delay, $options);
    }

    /**
     * Take the next message, waiting up to $timeout_ms for one to arrive.
     *
     * @param  string|array<int, string> $queues     Queue name, or several to read from
     * @param  int                       $timeout_ms How long to block; 0 returns immediately
     * @return message|null
     */
    public static function pop($queues, int $timeout_ms = 1000)
    {
        return self::connection()->pop($queues, $timeout_ms);
    }

    /**
     * Acknowledge a message.
     *
     * @param  message $msg Message as returned by pop()
     * @return bool
     */
    public static function ack(message $msg): bool
    {
        return self::connection()->ack($msg);
    }

    /**
     * Put a message back for another delivery.
     *
     * @param  message $msg   Message as returned by pop()
     * @param  int     $delay Seconds to hold it back; ignored by a driver that cannot delay
     * @return bool
     */
    public static function release(message $msg, int $delay = 0): bool
    {
        return self::connection()->release($msg, $delay);
    }

    /**
     * Move a message aside for good.
     *
     * @param  message     $msg   Message as returned by pop()
     * @param  string|null $error Why it failed
     * @return bool
     */
    public static function fail(message $msg, ?string $error = null): bool
    {
        return self::connection()->fail($msg, $error);
    }

    /**
     * How many messages are waiting on a queue.
     *
     * @param  string $queue Queue name
     * @return int
     */
    public static function size(string $queue): int
    {
        return self::connection()->size($queue);
    }

    /**
     * Move messages that have come due onto the queue a consumer reads.
     *
     * Answers [0, 0] on a driver that cannot delay, so a worker loop can call it unconditionally.
     *
     * @param  string|array<int, string> $queues Queue name, or several
     * @param  int                       $limit  Most messages to move in one call
     * @return array{0:int,1:int}  Messages moved, and the unix time the next one comes due
     */
    public static function migrate_delayed($queues, int $limit = 128): array
    {
        $driver = self::connection();

        if ( !$driver instanceof delayable )
        {
            return [0, 0];
        }

        return $driver->migrate_delayed($queues, $limit);
    }

    /**
     * Release the active driver's connection.
     *
     * @return bool
     */
    public static function close(): bool
    {
        $name = (string) (self::config('default') ?? '');

        if ( $name === '' )
        {
            return true;
        }

        $driver = runtime::get(self::SHARE_PREFIX . $name);

        if ( !$driver instanceof driver )
        {
            return true;
        }

        $closed = runtime::forget(self::SHARE_PREFIX . $name);
        unset(self::$_built[$name]);

        return $closed;
    }

    /**
     * Forget every connection built from the previous configuration.
     */
    private static function _flush_connections(): void
    {
        foreach ( array_keys(self::$_built) as $name )
        {
            runtime::forget(self::SHARE_PREFIX . $name);
        }

        self::$_built = [];
    }

    /**
     * Turn the `driver` key of a connection into a class name.
     *
     * A short name is looked up in the registry; anything else is taken for a class name, so an
     * application driver works without registering it first.
     *
     * @param  string $driver     Short name or class name
     * @param  string $connection Connection being selected, for the error message
     * @return string
     * @throws queue_exception When nothing usable comes out
     */
    private static function _resolve(string $driver, string $connection): string
    {
        $class = self::$_drivers[$driver] ?? $driver;

        if ( !class_exists($class) )
        {
            throw new queue_exception(sprintf(
                'queue connection "%s" names driver "%s", which does not exist',
                $connection,
                $driver
            ));
        }

        if ( !is_a($class, driver::class, true) )
        {
            throw new queue_exception(sprintf(
                'queue connection "%s" names driver "%s", which does not implement %s',
                $connection,
                $driver,
                driver::class
            ));
        }

        return $class;
    }
}
