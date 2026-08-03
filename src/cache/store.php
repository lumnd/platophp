<?php

/**
 * Cache store contract: what the cache facade needs from a driver
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\cache;

/**
 * The nine calls a cache driver has to answer.
 *
 * Every difference between the backing stores -- argument order, whether the flush command is
 * called flush or flushDB, whether counters are atomic, whether a lifetime can be read back --
 * is absorbed by the driver, so the facade holds no per driver branch.
 *
 * Keys arriving here are already namespaced and hashed by the facade; a driver must not add a
 * prefix of its own unless the server needs it for something else (the redis driver does, so
 * that a redis UI lists the cache under its own folder).
 *
 * **A stored value and a missing key are two different things.** Every driver has to keep them
 * apart, because `false`, `0`, `''`, `[]` and `null` are all values a caller may legitimately
 * cache. That is what the `$default` argument of get() and the separate has() are for: a driver
 * whose backend answers `false` for a missing key has to ask the backend a second question before
 * reporting a miss, rather than passing the ambiguity on. A caller that only wants "give me the
 * value or tell me it is not there" passes a sentinel of its own as $default -- which is exactly
 * what plato\cache\repository does.
 */
interface store
{
    /**
     * Write a value.
     *
     * @param string $key    Key
     * @param mixed  $value  Value; anything serialize() accepts, null, false and 0 included
     * @param int    $expire Lifetime in seconds, 0 or less to keep the value forever
     *
     * @return bool
     */
    public function set($key, $value, $expire = 0): bool;

    /**
     * Read a value.
     *
     * @param string $key     Key
     * @param mixed  $default Returned when the key is missing or expired, and only then
     *
     * @return mixed
     */
    public function get($key, $default = false);

    /**
     * Whether the store holds a value under a key.
     *
     * True for a key holding false, 0, '' or null: this reports presence, not truth.
     *
     * @param string $key Key
     *
     * @return bool
     */
    public function has($key): bool;

    /**
     * Delete a value.
     *
     * @param string $key Key
     *
     * @return int  Number of keys deleted
     */
    public function del($key): int;

    /**
     * Remaining lifetime of a key, following the redis convention.
     *
     * @param string $key Key
     *
     * @return int  Seconds left, -1 when the key never expires, -2 when it does not exist
     */
    public function ttl($key): int;

    /**
     * Set or replace the lifetime of a key that already exists.
     *
     * The counterpart of inc(), which deliberately does not touch the lifetime: a caller counting
     * into a fresh key has no other way to stop it living forever.
     *
     * @param string $key    Key
     * @param int    $expire Lifetime in seconds from now, 0 or less to make the key permanent
     *
     * @return bool  False when the key does not exist
     */
    public function expire($key, $expire): bool;

    /**
     * Add to a counter, creating it at zero when it does not exist yet.
     *
     * @param string $key  Key
     * @param int    $step Step, negative to subtract
     *
     * @return int|false  The new value
     */
    public function inc($key, $step = 1);

    /**
     * Drop everything the store holds.
     *
     * On a shared server this throws away what every other project stored as well.
     *
     * @return bool
     */
    public function flush(): bool;

    /**
     * Release the connection or file handle.
     *
     * @return bool
     */
    public function close(): bool;
}
