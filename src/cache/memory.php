<?php

/**
 * In-process cache store: an array that lives and dies with the process
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\cache;

/**
 * Cache store backed by an array in this process.
 *
 * There is no server and no file, so nothing here can fail for a reason outside the process:
 * `cache_type = memory` is what a unit test, a one-shot script or a `composer test` on a machine
 * without redis runs against.
 *
 * Two consequences to know before reaching for it in production:
 *
 *  - the cache is per process. Two php-fpm workers do not see each other's entries, and a value
 *    written before a fork is visible in the child only because the child inherited a copy of it,
 *    which then drifts apart from the parent's;
 *  - nothing survives the request. A memoizing layer is all this is.
 *
 * Values are serialized on write, like the file store does, so a caller gets a copy back rather
 * than the object it put in. A store that handed the same instance back would let a mutation
 * after set() reach into the cache, and the same code would then behave differently the moment it
 * was pointed at redis.
 *
 * Expiry is evaluated on read: a value whose lifetime has passed reads as missing and is dropped
 * then, not on a timer. What no one asks for is cleaned up by the process exiting.
 */
class memory implements store
{
    /**
     * Serialized values, keyed by cache key
     *
     * @var array<string, string>
     */
    private $_values = [];

    /**
     * Unix time each key expires at, 0 for a key that never does
     *
     * @var array<string, int>
     */
    private $_expires = [];

    /**
     * Write a value.
     *
     * @param string $key    Key
     * @param mixed  $value  Value; anything serialize() accepts, null and false included
     * @param int    $expire Lifetime in seconds, 0 or less to keep the value forever
     *
     * @return bool
     */
    public function set($key, $value, $expire = 0): bool
    {
        if ( $key === '' )
        {
            return false;
        }

        $this->_values[$key]  = serialize($value);
        $this->_expires[$key] = $expire > 0 ? time() + (int) $expire : 0;

        return true;
    }

    /**
     * Read a value.
     *
     * @param string $key     Key
     * @param mixed  $default Returned when the key is missing or expired
     *
     * @return mixed
     */
    public function get($key, $default = false)
    {
        if ( !$this->_alive($key) )
        {
            return $default;
        }

        return unserialize($this->_values[$key]);
    }

    /**
     * Whether the store holds a value under a key.
     *
     * @param string $key Key
     *
     * @return bool
     */
    public function has($key): bool
    {
        return $this->_alive($key);
    }

    /**
     * Delete a value.
     *
     * @param string $key Key
     *
     * @return int  Number of keys deleted
     */
    public function del($key): int
    {
        $existed = $this->_alive($key);

        unset($this->_values[$key], $this->_expires[$key]);

        return $existed ? 1 : 0;
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
        if ( !$this->_alive($key) )
        {
            return false;
        }

        $this->_expires[$key] = $expire > 0 ? time() + (int) $expire : 0;

        return true;
    }

    /**
     * Remaining lifetime of a key, following the redis convention.
     *
     * @param string $key Key
     *
     * @return int  Seconds left, -1 when the key never expires, -2 when it does not exist
     */
    public function ttl($key): int
    {
        if ( !$this->_alive($key) )
        {
            return -2;
        }

        if ( $this->_expires[$key] === 0 )
        {
            return -1;
        }

        return max(0, $this->_expires[$key] - time());
    }

    /**
     * Add to a counter, creating it at zero when it does not exist yet.
     *
     * As atomic as it needs to be: one process, one request at a time, and nobody else can see
     * this store.
     *
     * @param string $key  Key
     * @param int    $step Step, negative to subtract
     *
     * @return int|false  The new value
     */
    public function inc($key, $step = 1)
    {
        if ( $key === '' )
        {
            return false;
        }

        $value = $this->_alive($key) ? unserialize($this->_values[$key]) : 0;

        if ( !is_numeric($value) )
        {
            return false;
        }

        $value = (int) $value + (int) $step;
        // The lifetime is left alone, which is what INCRBY does: counting does not renew a key
        $this->_values[$key] = serialize($value);
        if ( !isset($this->_expires[$key]) )
        {
            $this->_expires[$key] = 0;
        }

        return $value;
    }

    /**
     * Drop everything the store holds.
     *
     * @return bool
     */
    public function flush(): bool
    {
        $this->_values  = [];
        $this->_expires = [];

        return true;
    }

    /**
     * Nothing is held open, so there is nothing to release; the values go with it anyway, so that
     * a reopened store does not answer with what the closed one had.
     *
     * @return bool
     */
    public function close(): bool
    {
        return $this->flush();
    }

    /**
     * Whether a key is present and has not expired, dropping it when it has.
     *
     * @param string $key Key
     *
     * @return bool
     */
    private function _alive($key): bool
    {
        if ( !isset($this->_values[$key]) )
        {
            return false;
        }

        $expire = $this->_expires[$key] ?? 0;

        if ( $expire !== 0 && $expire <= time() )
        {
            unset($this->_values[$key], $this->_expires[$key]);

            return false;
        }

        return true;
    }
}
