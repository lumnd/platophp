<?php

/**
 * PSR-16 adapter: hands plato\cache to anything that asks for a simple cache
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\psr;

use DateInterval;
use DateTime;
use plato\cache\cache as store;
use Psr\SimpleCache\CacheInterface;

/**
 * plato\cache behind the PSR-16 interface.
 *
 * Same purpose as plato\psr\logger: a library that asks for a `Psr\SimpleCache\CacheInterface` can
 * be given the cache the application already configured, rather than a second one with its own
 * connection and its own prefix.
 *
 *     $client = new SomeLibrary(new plato\psr\cache());
 *
 * The camelCase methods are the interface's, not this package's convention, and are the one place
 * the naming rule is suspended -- renaming them would mean not implementing PSR-16.
 *
 * Two differences from a purpose built PSR-16 store, both inherited from what a plato cache store
 * promises and both worth knowing before relying on this:
 *
 *  - **clear() empties the whole store.** Not just what this adapter wrote: on a shared redis or
 *    memcached that is every project on the server, which is why plato\cache calls the same thing
 *    free_mem(true) and asks for the flag explicitly;
 *  - **keys are namespaced and hashed.** plato\cache digests every key with the configured prefix
 *    before the store sees it, so the key in redis is not the key passed here.
 *
 * A stored false, 0, '' or null is a hit and comes back as itself, and has() reports presence
 * rather than truth -- plato\cache\store carries a $default through get() and a separate has() for
 * exactly this, so the adapter needs no wrapper of its own.
 *
 * Key validation is PSR-16's, not plato's: the reserved characters are refused with an exception
 * rather than quietly encoded, because a caller passing `user:1` needs to hear about it once rather
 * than wonder why two caches disagree.
 */
class cache implements CacheInterface
{
    /**
     * Characters PSR-16 reserves for future extensions
     */
    private const RESERVED = '{}()/\\@:';

    /**
     * Read a value.
     *
     * @param string $key
     * @param mixed  $default Returned when the key is missing, and only then
     *
     * @return mixed
     * @throws invalid_argument When the key is not a legal PSR-16 key
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->_guard($key);

        return store::get($key, $default);
    }

    /**
     * Write a value.
     *
     * @param string                 $key
     * @param mixed                  $value
     * @param int|DateInterval|null  $ttl Null leaves the lifetime to config/cache.php; zero or less
     *                                    deletes the key, which is what an already expired item is
     *
     * @return bool
     * @throws invalid_argument When the key is not a legal PSR-16 key
     */
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->_guard($key);

        if ( $ttl === null )
        {
            return store::set($key, $value);
        }

        $seconds = $this->_seconds($ttl);

        if ( $seconds <= 0 )
        {
            store::del($key);

            return true;
        }

        return store::set($key, $value, $seconds);
    }

    /**
     * Delete a value. Deleting a key that is not there is not an error.
     *
     * @param string $key
     *
     * @return bool
     * @throws invalid_argument When the key is not a legal PSR-16 key
     */
    public function delete(string $key): bool
    {
        $this->_guard($key);

        store::del($key);

        return true;
    }

    /**
     * Empty the store. See the class docblock: this is not limited to what this adapter wrote.
     *
     * @return bool
     */
    public function clear(): bool
    {
        return (bool) store::free_mem(true);
    }

    /**
     * Read several values.
     *
     * @param iterable<string> $keys
     * @param mixed            $default
     *
     * @return iterable<string, mixed>
     * @throws invalid_argument When one of the keys is not a legal PSR-16 key
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];

        foreach ( $keys as $key )
        {
            $values[(string) $key] = $this->get((string) $key, $default);
        }

        return $values;
    }

    /**
     * Write several values.
     *
     * Answers false when any one of them failed, having still tried the rest: a partial write is
     * what a cache does anyway, and stopping halfway would leave the caller with no way to know how
     * far it got.
     *
     * @param iterable<string, mixed> $values
     * @param int|DateInterval|null   $ttl
     *
     * @return bool
     * @throws invalid_argument When one of the keys is not a legal PSR-16 key
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $ok = true;

        foreach ( $values as $key => $value )
        {
            $ok = $this->set((string) $key, $value, $ttl) && $ok;
        }

        return $ok;
    }

    /**
     * Delete several values.
     *
     * @param iterable<string> $keys
     *
     * @return bool
     * @throws invalid_argument When one of the keys is not a legal PSR-16 key
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $ok = true;

        foreach ( $keys as $key )
        {
            $ok = $this->delete((string) $key) && $ok;
        }

        return $ok;
    }

    /**
     * Whether the store holds a value for a key. Presence, not truth: a stored 0 is present.
     *
     * @param string $key
     *
     * @return bool
     * @throws invalid_argument When the key is not a legal PSR-16 key
     */
    public function has(string $key): bool
    {
        $this->_guard($key);

        return store::has($key);
    }

    /**
     * Refuse a key PSR-16 does not allow.
     *
     * @param string $key
     *
     * @return void
     * @throws invalid_argument
     */
    private function _guard(string $key): void
    {
        if ( $key === '' )
        {
            throw new invalid_argument('A cache key cannot be empty');
        }

        if ( strpbrk($key, self::RESERVED) !== false )
        {
            throw new invalid_argument(sprintf(
                'The cache key "%s" holds one of the characters PSR-16 reserves: %s',
                $key,
                self::RESERVED
            ));
        }
    }

    /**
     * A lifetime in seconds.
     *
     * @param int|DateInterval $ttl
     *
     * @return int
     */
    private function _seconds($ttl): int
    {
        if ( $ttl instanceof DateInterval )
        {
            // Through a date rather than by adding up the fields: months and years have no fixed
            // length, and only the calendar knows which ones this interval landed on
            $now = new DateTime('@' . time());

            return (int) ((clone $now)->add($ttl)->getTimestamp() - $now->getTimestamp());
        }

        return (int) $ttl;
    }
}
