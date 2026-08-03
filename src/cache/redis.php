<?php

/**
 * Redis client wrapper: standalone and cluster connection management
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\cache;

use plato\config;
use plato\runtime;

/**
 * Thin wrapper around the phpredis extension.
 *
 * The wrapper only owns what the extension does not give for free:
 *
 *  - lazy connecting, so loading the class never opens a socket;
 *  - one shared instance per logical name, registered with plato\runtime so a forked child
 *    connects for itself instead of writing into the socket it inherited from its parent;
 *  - the handful of helpers that have to behave differently on a cluster (scan, infos, flush);
 *  - the store contract, so the cache facade can drive it like any other driver.
 *
 * Everything this class does not wrap is reached through client(), which hands over the phpredis
 * handler itself -- lists, streams, sorted sets, MULTI and Lua all go through there. That is
 * deliberately not a `__call()` forwarder: a magic passthrough leaves the real surface of the class
 * invisible to phpstan, to an IDE and to this package's own API listing, while an object of a type
 * those three already know does not.
 *
 * Values are stored with Redis::SERIALIZER_JSON: the extension serializes on write and restores
 * on read, and other languages can still read what PHP wrote. This needs phpredis >= 5.0.
 */
class redis implements store
{
    /**
     * Cache configuration, null until config() reads it
     *
     * @var array<string, mixed>|null
     */
    private static $_config = null;

    /**
     * Prefix of the runtime keys every instance is registered under
     */
    private const SHARE_PREFIX = 'cache.redis.';

    /**
     * @var \Redis|\RedisCluster|null
     */
    private $_handler = null;

    /**
     * Runtime epoch the open connection was made in, null while there is none.
     *
     * Only ever different from the current epoch on an instance a caller kept a reference to
     * across a fork; one reached through instance() is rebuilt by the registry instead.
     *
     * @var int|null
     */
    private $_epoch = null;

    /**
     * Logical connection name
     *
     * @var string
     */
    private $_name;

    /**
     * Connection settings of this instance -- host, port, auth, prefix.
     *
     * Not $_config: that name is taken by the static cache configuration above, and PHP will not
     * let a static and an instance property share one.
     *
     * @var array<string, mixed>
     */
    private $_server;

    /**
     * Whether the open connection is a cluster
     *
     * @var bool
     */
    private $_is_cluster = false;

    /**
     * The cache configuration, read on the first call that needs it.
     *
     * Connection free by design: instance() only builds the object, and the socket is opened by
     * _connect() when the first command runs.
     *
     * @param string|null $key One setting, or null for all of them
     *
     * @return mixed
     */
    public static function config(?string $key = null)
    {
        if ( self::$_config === null )
        {
            self::$_config = (array) config::instance('cache')->get();
        }

        return $key === null ? self::$_config : (self::$_config[$key] ?? null);
    }

    /**
     * Hand the connection settings over instead of letting them be read from config/cache.php.
     *
     * Merges on top of the file settings, so an override names only what it changes.
     *
     * @param array<string, mixed> $config Same shape as config/cache.php
     *
     * @return void
     */
    public static function configure(array $config): void
    {
        self::$_config = $config + (array) self::config();
    }

    /**
     * Drop the overrides, so the next read comes from the file again.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$_config = null;
    }

    /**
     * Shared instance of a logical connection.
     *
     * @param string                    $name   Connection name; separate names get separate
     *                                          sockets, which is what blocking commands need
     * @param array<string, mixed>|null $config Connection settings, null to read them from
     *                                          the cache configuration
     *
     * @return self
     */
    public static function instance(string $name = 'redis', ?array $config = null): self
    {
        return runtime::share(
            self::SHARE_PREFIX . $name,
            function () use ($name, $config)
            {
                if ( $config === null )
                {
                    $settings = (array) self::config();

                    $config   = $settings['redis']['server'] ?? [];
                    $prefix   = $settings['prefix'] ?? '';
                    // Cache entries get their own prefix so Redis UIs list them in a separate folder
                    $config['prefix'] = ($name === 'cache') ? $prefix . ':cache' : $prefix;
                }

                return new self($name, $config);
            },
            function (self $instance)
            {
                $instance->close();
            }
        );
    }

    /**
     * @param string                    $name   Connection name
     * @param array<string, mixed>|null $config Connection settings
     */
    public function __construct(string $name, ?array $config = null)
    {
        $this->_name   = $name;
        $this->_server = $config ?? [];
    }

    /**
     * Open the connection described by $this->_server.
     *
     * @return void
     *
     * @throws \RedisException        When the server refuses the connection
     * @throws \RuntimeException      When the phpredis extension is missing
     */
    private function _connect()
    {
        if ( !class_exists('\Redis') )
        {
            throw new \RuntimeException('The phpredis extension is required to use plato\cache\redis');
        }

        $config = $this->_server;
        $prefix = empty($config['prefix']) ? '' : $config['prefix'] . ':';

        try
        {
            if ( !empty($config['cluster']) && class_exists('\RedisCluster') )
            {
                $this->_handler = new \RedisCluster(
                    null,
                    $config['cluster']['host'],
                    $config['cluster']['timeout'] ?? 5,
                    $config['cluster']['read_timeout'] ?? 5,
                    true,
                    empty($config['cluster']['pass']) ? null : $config['cluster']['pass']
                );
                $this->_is_cluster = true;

                $this->_handler->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
            }
            else
            {
                $this->_handler = new \Redis();
                // Short connections by default: persistent ones are collected at random on PHP 7+
                if ( !empty($config['keep-alive']) )
                {
                    $this->_handler->pconnect($config['host'], (int) $config['port'], $config['timeout'] ?? 5);
                }
                else
                {
                    $this->_handler->connect($config['host'], (int) $config['port'], $config['timeout'] ?? 5);
                }

                if ( !empty($config['pass']) )
                {
                    $this->_handler->auth($config['pass']);
                }

                if ( !empty($config['dbindex']) )
                {
                    $this->_handler->select((int) $config['dbindex']);
                }
            }

            // A cluster has no databases, so the prefix is the only isolation between projects
            if ( $prefix !== '' )
            {
                $this->_handler->setOption(\Redis::OPT_PREFIX, $prefix);
            }
            $this->_handler->setOption(\Redis::OPT_SERIALIZER, self::_serializer($config['serializer'] ?? 'json'));
        }
        catch ( \Throwable $e )
        {
            // Keeping the half open handler would answer every later command with "Redis server
            // went away", which hides why the connection failed in the first place
            $this->_handler = null;

            throw $e;
        }
    }

    /**
     * The phpredis serializer constant a configured name stands for.
     *
     * JSON is the default, and what the cache stores with: the extension serializes on write and
     * restores on read, and a consumer in another language can still read what PHP wrote.
     *
     * `none` is what a caller that owns its own wire format needs -- the queue driver writes a JSON
     * envelope of its own, and letting the extension encode that string again would wrap it in a
     * second layer of JSON that no other consumer can read.
     *
     * @param mixed $name Configured name: none | json | php | igbinary
     *
     * @return int
     */
    private static function _serializer($name): int
    {
        switch ( strtolower((string) $name) )
        {
            case 'none':
                return \Redis::SERIALIZER_NONE;

            case 'php':
                return \Redis::SERIALIZER_PHP;

            case 'igbinary':
                // The constant is only defined when phpredis was built against libigbinary
                return defined('\Redis::SERIALIZER_IGBINARY')
                    ? \Redis::SERIALIZER_IGBINARY
                    : \Redis::SERIALIZER_JSON;

            default:
                return \Redis::SERIALIZER_JSON;
        }
    }

    /**
     * Connect on first use.
     *
     * The second condition is what makes a fork safe. A child inherits the parent's socket along
     * with everything else, and two processes taking turns on one connection read each other's
     * replies -- "protocol error, got '0' as reply type byte". An instance reached through
     * instance() is rebuilt by the runtime registry and never gets here with a stale handler; one
     * a caller kept a reference to across the fork does, and reconnects on the spot.
     *
     * The inherited handler is dropped rather than closed. Closing it would run phpredis'
     * teardown against a descriptor the parent is still using; dropping the reference closes only
     * this process' copy of it.
     *
     * @return \Redis|\RedisCluster
     */
    private function _handle()
    {
        $epoch = runtime::epoch();

        if ( $this->_handler === null || $this->_epoch !== $epoch )
        {
            $this->_handler = null;
            $this->_connect();
            $this->_epoch   = $epoch;
        }

        return $this->_handler;
    }

    /**
     * The phpredis handler itself, connected if it is not up yet.
     *
     * The escape hatch for everything this class does not wrap -- lists, streams, sorted sets,
     * MULTI, Lua. It replaced a `__call()` that forwarded any method name to the extension: that
     * left the real surface of the class invisible to phpstan, to an IDE and to this package's own
     * API listing, while `client()` hands over an object those three already understand.
     *
     *     redis::instance('queue')->client()->rPush($key, $payload);
     *
     * Do not keep the returned handle across a fork or past close(): it is this process' socket,
     * and a later call to client() is what checks that. Ask for it again instead of storing it.
     *
     * @return \Redis|\RedisCluster
     */
    public function client()
    {
        return $this->_handle();
    }

    /**
     * Write a value.
     *
     * @param string $key    Key
     * @param mixed  $value  Value; anything the configured serializer accepts, null and false
     *                       included. With `serializer = none` the extension sends the value as a
     *                       string, so null arrives as '' -- raw mode is for callers that own
     *                       their own wire format, and they should send one
     * @param int    $expire Lifetime in seconds, 0 or less to keep the value forever
     *
     * @return bool
     */
    public function set($key, $value, $expire = 0): bool
    {
        $handler = $this->_handle();

        return $expire > 0
            ? (bool) $handler->setex($key, (int) $expire, $value)
            : (bool) $handler->set($key, $value);
    }

    /**
     * Write a value only if the key does not exist yet, in a single round trip.
     *
     * SETNX plus EXPIRE leaves a lock that never expires when the process dies in between, so
     * locks have to go through this one.
     *
     * @param string $key    Key
     * @param mixed  $value  Value
     * @param int    $expire Lifetime in seconds, 0 or less to keep the value forever
     *
     * @return bool  True when the key was written, false when it already existed
     */
    public function set_nx($key, $value, $expire = 0): bool
    {
        if ( $value === null )
        {
            return false;
        }

        $options = ['nx'];
        if ( $expire > 0 )
        {
            $options['ex'] = (int) $expire;
        }

        return (bool) $this->_handle()->set($key, $value, $options);
    }

    /**
     * Read a value.
     *
     * GET answers false both for a missing key and for a stored false, so a false is checked
     * against EXISTS before it is reported as a miss. That second round trip only happens on the
     * false path, which is the miss path in nearly every cache.
     *
     * @param string $key     Key
     * @param mixed  $default Returned when the key does not exist
     *
     * @return mixed
     */
    public function get($key, $default = false)
    {
        $value = $this->_handle()->get($key);

        if ( $value === false && !$this->has($key) )
        {
            return $default;
        }

        return $value;
    }

    /**
     * Whether the server holds a value under a key.
     *
     * @param string $key Key
     *
     * @return bool
     */
    public function has($key): bool
    {
        return (bool) $this->_handle()->exists($key);
    }

    /**
     * Delete one or more keys.
     *
     * @param string $key   Key
     * @param string ...$more Further keys; a cluster needs them to live on the same node
     *
     * @return int  Number of keys deleted
     */
    public function del($key, ...$more): int
    {
        return (int) $this->_handle()->del($key, ...$more);
    }

    /**
     * Set or replace the lifetime of a key that already exists.
     *
     * @param string $key    Key
     * @param int    $expire Lifetime in seconds from now, 0 or less to make the key permanent
     *
     * @return bool
     */
    public function expire($key, $expire): bool
    {
        $handler = $this->_handle();

        return $expire > 0
            ? (bool) $handler->expire($key, (int) $expire)
            : (bool) $handler->persist($key);
    }

    /**
     * Remaining lifetime of a key.
     *
     * @param string $key Key
     *
     * @return int  Seconds left, -1 when the key never expires, -2 when it does not exist
     */
    public function ttl($key): int
    {
        return (int) $this->_handle()->ttl($key);
    }

    /**
     * Add to a counter, creating it at zero when it does not exist yet.
     *
     * INCRBY is atomic, so this is the only driver that counts correctly under concurrency.
     *
     * @param string $key  Key
     * @param int    $step Step, negative to subtract
     *
     * @return int|false  The new value
     */
    public function inc($key, $step = 1)
    {
        $step    = (int) $step;
        $handler = $this->_handle();

        return $step >= 0 ? $handler->incrBy($key, $step) : $handler->decrBy($key, -$step);
    }

    /**
     * Drop every key of the current database, on every master when running on a cluster.
     *
     * @return bool
     */
    public function flush(): bool
    {
        $handler = $this->_handle();

        if ( !$this->_is_cluster )
        {
            return (bool) $handler->flushDB();
        }

        foreach ( $handler->_masters() as $master )
        {
            $handler->flushDB($master);
        }

        return true;
    }

    /**
     * Collect every key matching a pattern.
     *
     * Wraps the cursor loop, and on a cluster the loop over the masters. Prefer it over KEYS,
     * which blocks the server for the whole scan.
     *
     * @param string $pattern Match pattern, without the configured key prefix
     *
     * @return array<int, string>
     */
    public function scan($pattern)
    {
        $handler = $this->_handle();
        $keys    = [];

        if ( $this->_is_cluster )
        {
            // The cluster scan takes the pattern as is, so the key prefix has to be spelled out
            $pattern = (empty($this->_server['prefix']) ? '' : $this->_server['prefix'] . ':') . $pattern;
            foreach ( $handler->_masters() as $master )
            {
                $iterator = null;
                while ( $tmp = $handler->scan($iterator, $master, $pattern) )
                {
                    $keys = array_merge($keys, $tmp);
                }
            }
        }
        else
        {
            $iterator = null;
            while ( $tmp = $handler->scan($iterator, $pattern) )
            {
                $keys = array_merge($keys, $tmp);
            }
        }

        return $keys;
    }

    /**
     * Server statistics, merged over every master when running on a cluster.
     *
     * @return array<string, mixed>
     */
    public function infos()
    {
        $handler = $this->_handle();

        if ( !$this->_is_cluster )
        {
            return (array) $handler->info();
        }

        $infos = [];
        foreach ( $handler->_masters() as $master )
        {
            foreach ( (array) $handler->info($master) as $k => $v )
            {
                $infos[$k . '(' . implode(':', $master) . ')'] = $v;
            }
        }

        return $infos;
    }

    /**
     * Drop an instance and close its connection.
     *
     * @param string|null $name Connection name, null for this instance
     *
     * @return bool
     */
    public function close(?string $name = null): bool
    {
        $name = $name ?? $this->_name;
        // Reached from runtime::forget() as well, which has already dropped the key by then, so
        // this is a no-op on that path rather than a loop
        runtime::forget(self::SHARE_PREFIX . $name);

        if ( $name === $this->_name && $this->_handler !== null )
        {
            try
            {
                $this->_handler->close();
            }
            catch ( \Throwable )
            {
                // Closing an already dropped connection is not an error worth reporting
            }
            $this->_handler = null;
            $this->_epoch   = null;
        }

        return true;
    }

    public function __destruct()
    {
        $this->close();
    }
}
