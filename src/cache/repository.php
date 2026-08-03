<?php

/**
 * Configured cache operations over one store
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\cache;

use stdClass;

/**
 * Owns one store's key namespace, default lifetime and request-local memo.
 *
 * **Hit and truth are two different questions.** get() answers with the stored value whatever it
 * is -- `false`, `0`, `''` and `null` included -- and reports a miss by returning the caller's
 * $default, which defaults to false only because that is what the shorter form of the question
 * usually wants. has() answers presence, so a key holding `0` is present. Code that has to tell a
 * cached false apart from a miss passes a sentinel:
 *
 *     $miss  = new stdClass();
 *     $value = cache::repository()->get('k', $miss);
 *     if ( $value === $miss ) { ... }
 *
 * A repository without a store (the cache is disabled) reports every key as a miss and refuses
 * every write, which is the same shape as a cold cache and needs no branch at the call site.
 */
class repository
{
    /**
     * Sentinel for "the store does not hold this key".
     *
     * An object, so no value a caller can store is ever identical to it.
     *
     * @var stdClass|null
     */
    private static ?stdClass $_miss = null;

    private ?store $_store;

    private string $_prefix;

    private int $_cache_time;

    private bool $_memoize;

    /** @var array<string, mixed> */
    private array $_memo = [];

    public function __construct(
        ?store $store,
        string $prefix = '',
        int $cache_time = 7200,
        bool $memoize = true
    ) {
        $this->_store      = $store;
        $this->_prefix     = $prefix;
        $this->_cache_time = $cache_time;
        $this->_memoize    = $memoize;
    }

    /**
     * The underlying store for driver-specific, explicitly unprefixed operations.
     */
    public function store(): ?store
    {
        return $this->_store;
    }

    /**
     * Write a value.
     *
     * @param string   $key       Key
     * @param mixed    $value     Value; anything serialize() accepts, null and false included
     * @param int|null $cachetime Lifetime in seconds, null for the configured default
     *
     * @return bool
     */
    public function set($key, $value, $cachetime = null): bool
    {
        if ( $this->_store === null )
        {
            return false;
        }

        $cachetime = $cachetime === null ? $this->_cache_time : (int) $cachetime;
        $cachekey  = $this->_key($key);
        $result    = $this->_store->set($cachekey, $value, $cachetime);

        if ( $result && $this->_memoize )
        {
            $this->_memo[$cachekey] = $value;
        }

        return $result;
    }

    /**
     * Read a value.
     *
     * @param string $key     Key
     * @param mixed  $default Returned when the key is missing or expired, and only then
     *
     * @return mixed
     */
    public function get($key, $default = false)
    {
        if ( $this->_store === null )
        {
            return $default;
        }

        $cachekey = $this->_key($key);

        // array_key_exists rather than isset(): a memoized null is a hit
        if ( array_key_exists($cachekey, $this->_memo) )
        {
            return $this->_memo[$cachekey];
        }

        $miss  = self::_miss();
        $value = $this->_store->get($cachekey, $miss);

        if ( $value === $miss )
        {
            return $default;
        }

        if ( $this->_memoize )
        {
            $this->_memo[$cachekey] = $value;
        }

        return $value;
    }

    public function del($key): int
    {
        if ( $this->_store === null )
        {
            return 0;
        }

        $cachekey = $this->_key($key);
        unset($this->_memo[$cachekey]);

        return $this->_store->del($cachekey);
    }

    public function ttl($key): int
    {
        return $this->_store === null ? -2 : $this->_store->ttl($this->_key($key));
    }

    public function expire($key, int $cachetime): bool
    {
        return $this->_store !== null && $this->_store->expire($this->_key($key), $cachetime);
    }

    /**
     * Whether the cache holds a value under a key.
     *
     * True for a key holding false, 0, '' or null: this is presence, not truth.
     *
     * @param string $key Key
     *
     * @return bool
     */
    public function has($key): bool
    {
        if ( $this->_store === null )
        {
            return false;
        }

        $cachekey = $this->_key($key);

        return array_key_exists($cachekey, $this->_memo) || $this->_store->has($cachekey);
    }

    /**
     * The cached value, or what $producer answers -- stored on the way out.
     *
     * A producer returning null or false is cached like any other value: the point of remember()
     * is that the producer runs once, and "there is no such user" is worth remembering too. Pass a
     * lifetime short enough for that to be safe, or check the answer and del() it.
     *
     * @param string   $key       Key
     * @param int|null $cachetime Lifetime in seconds, null for the configured default
     * @param callable $producer  Called on a miss
     *
     * @return mixed
     */
    public function remember($key, $cachetime, callable $producer)
    {
        $miss  = self::_miss();
        $value = $this->get($key, $miss);

        if ( $value !== $miss )
        {
            return $value;
        }

        $value = $producer();

        $this->set($key, $value, $cachetime);

        return $value;
    }

    public function remember_forever($key, callable $producer)
    {
        return $this->remember($key, 0, $producer);
    }

    public function tags($tags): tags
    {
        return new tags($this, (array) $tags);
    }

    public function inc($key, int $step = 1)
    {
        if ( $this->_store === null )
        {
            return false;
        }

        $cachekey = $this->_key($key);
        unset($this->_memo[$cachekey]);

        return $this->_store->inc($cachekey, $step);
    }

    public function dec($key, int $step = 1)
    {
        return $this->inc($key, -$step);
    }

    public function free_mem(bool $flush_store = false): bool
    {
        $this->_memo = [];

        return !$flush_store || $this->_store === null || $this->_store->flush();
    }

    public function close(): bool
    {
        $this->_memo = [];

        return $this->_store === null || $this->_store->close();
    }

    private function _key($key): string
    {
        return substr(md5($this->_prefix . '_' . $key), 8, 16);
    }

    /**
     * The shared miss sentinel.
     *
     * @return stdClass
     */
    private static function _miss(): stdClass
    {
        if ( self::$_miss === null )
        {
            self::$_miss = new stdClass();
        }

        return self::$_miss;
    }
}
