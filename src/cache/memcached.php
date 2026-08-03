<?php

/**
 * Memcached cache driver: store contract on top of the memcached extension
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\cache;

use plato\runtime;

/**
 * Cache driver backed by the memcached extension (ext/memcached, libmemcached).
 *
 * Only the extension named memcached is supported; the older ext/memcache is not. Note that
 * inside this namespace the extension class has to be written \Memcached with the leading
 * backslash, or it resolves to this class instead.
 *
 * The server list is registered on first use, not when the class is built, so an application
 * that never touches the cache never opens a socket. Values are serialized by the extension.
 *
 * Commands this class does not wrap are reached through client(), which hands over the extension
 * handler itself rather than forwarding unknown method names through a `__call()`.
 *
 * What memcached cannot do, and what that means here:
 *
 *  - it does not report the remaining lifetime of an item, so ttl() can only answer -1 or -2;
 *  - its counters are unsigned, so decrementing below zero stops at zero;
 *  - it has no lock command, which is why plato\lock needs redis.
 */
class memcached implements store
{
    /**
     * @var \Memcached|null
     */
    private $_handler = null;

    /**
     * Runtime epoch the servers were registered in, null while there is no handler
     *
     * @var int|null
     */
    private $_epoch = null;

    /**
     * Driver settings, the memcached section of the cache configuration
     *
     * @var array<string, mixed>
     */
    private $_config;

    /**
     * @param array<string, mixed> $config Driver settings
     */
    public function __construct(array $config = [])
    {
        $this->_config = $config;
    }

    /**
     * Write a value.
     *
     * @param string $key    Key
     * @param mixed  $value  Value; anything the extension serializes, null and false included
     * @param int    $expire Lifetime in seconds, 0 or less to keep the value forever
     *
     * @return bool
     */
    public function set($key, $value, $expire = 0): bool
    {
        // A lifetime above 30 days is read as a unix timestamp by the server, so turn it into one
        $expire = (int) $expire;
        if ( $expire > 2592000 )
        {
            $expire += time();
        }

        return $this->_handle()->set($key, $value, $expire > 0 ? $expire : 0);
    }

    /**
     * Read a value.
     *
     * The extension answers false both for a missing key and for a stored false; the result code
     * of the last command is what tells the two apart, and it is set by the same call, so this
     * costs no extra round trip.
     *
     * @param string $key     Key
     * @param mixed  $default Returned when the key is missing or expired
     *
     * @return mixed
     */
    public function get($key, $default = false)
    {
        $handler = $this->_handle();
        $value   = $handler->get($key);

        if ( $value === false && $handler->getResultCode() === \Memcached::RES_NOTFOUND )
        {
            return $default;
        }

        return $value;
    }

    /**
     * Whether the servers hold a value under a key.
     *
     * @param string $key Key
     *
     * @return bool
     */
    public function has($key): bool
    {
        $handler = $this->_handle();
        $handler->get($key);

        return $handler->getResultCode() !== \Memcached::RES_NOTFOUND;
    }

    /**
     * Delete a value.
     *
     * @param string $key Key
     *
     * @return int  1 when a value was deleted, 0 when there was nothing to delete
     */
    public function del($key): int
    {
        return $this->_handle()->delete($key) ? 1 : 0;
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
        return (bool) $this->_handle()->touch($key, $expire > 0 ? (int) $expire : 0);
    }

    /**
     * Remaining lifetime of a key.
     *
     * memcached does not hand out the lifetime of an item, so this only tells existence apart
     * from absence.
     *
     * @param string $key Key
     *
     * @return int  -1 when the key exists, -2 when it does not
     */
    public function ttl($key): int
    {
        return $this->has($key) ? -1 : -2;
    }

    /**
     * Add to a counter, creating it at zero when it does not exist yet.
     *
     * The counter is unsigned on the server, so subtracting past zero returns zero rather than
     * a negative number.
     *
     * @param string $key  Key
     * @param int    $step Step, negative to subtract
     *
     * @return int|false  The new value
     */
    public function inc($key, $step = 1)
    {
        $handler = $this->_handle();
        $step    = (int) $step;

        // increment() fails on a missing key unless the binary protocol is on, so seed it here
        if ( !$this->has($key) )
        {
            $handler->set($key, 0, 0);
        }

        return $step >= 0 ? $handler->increment($key, $step) : $handler->decrement($key, -$step);
    }

    /**
     * Drop every value held by the servers.
     *
     * @return bool
     */
    public function flush(): bool
    {
        return $this->_handle()->flush();
    }

    /**
     * Close the connections.
     *
     * @return bool
     */
    public function close(): bool
    {
        if ( $this->_handler !== null )
        {
            $this->_handler->quit();
            $this->_handler = null;
        }

        return true;
    }

    /**
     * The extension handler itself, with the servers registered.
     *
     * The escape hatch for everything this class does not wrap -- getMulti, cas, the statistics.
     * It replaced a `__call()` that forwarded any method name to the extension: that left the real
     * surface of the class invisible to phpstan, to an IDE and to this package's own API listing.
     *
     * Do not keep the returned handler across a fork or past close(); ask for it again instead.
     *
     * @return \Memcached
     */
    public function client()
    {
        return $this->_handle();
    }

    /**
     * Register the servers on first use.
     *
     * The epoch check is what makes a fork safe: a child inherits the parent's open sockets along
     * with the object holding them, and two processes taking turns on one connection read each
     * other's replies. Dropping the inherited handler rather than quitting it closes only this
     * process' copy of the descriptors.
     *
     * @return \Memcached
     *
     * @throws \RuntimeException When the extension is missing or no server is configured
     */
    private function _handle()
    {
        $epoch = runtime::epoch();

        if ( $this->_handler !== null && $this->_epoch === $epoch )
        {
            return $this->_handler;
        }

        $this->_handler = null;

        if ( !class_exists('\Memcached') )
        {
            throw new \RuntimeException('The memcached extension is required to use plato\cache\memcached');
        }

        $servers = [];
        foreach ( $this->_config['servers'] ?? [] as $server )
        {
            $servers[] = [$server['host'], (int) $server['port'], (int) ($server['weight'] ?? 1)];
        }

        if ( $servers === [] )
        {
            throw new \RuntimeException('No memcached server is configured, see the memcached section of config/cache.php');
        }

        $handler = new \Memcached();

        // In milliseconds, unlike every other timeout of this configuration file
        if ( !empty($this->_config['connect_timeout']) )
        {
            $handler->setOption(\Memcached::OPT_CONNECT_TIMEOUT, (int) $this->_config['connect_timeout']);
        }

        if ( isset($this->_config['compression']) )
        {
            $handler->setOption(\Memcached::OPT_COMPRESSION, (bool) $this->_config['compression']);
        }

        $handler->addServers($servers);
        $this->_handler = $handler;
        $this->_epoch   = $epoch;

        return $handler;
    }
}
