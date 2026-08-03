<?php

/**
 * Cache facade: static shortcuts for the configured default repository
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\cache;

use plato\config;
use plato\plato;
use plato\runtime;

/**
 * Static calls keep the common case short; repository() exposes the independently testable core.
 */
class cache
{
    private const SHARE_KEY = 'cache.repository';

    /** @var array<string, mixed>|null */
    private static $_config = null;

    private static bool $_enabled = false;

    private static string $_cache_type = 'file';

    private static bool $_need_mem = true;

    public static function configure(array $config): void
    {
        self::$_config = $config + (array) self::config();

        self::free();
        self::_apply();
    }

    public static function reset(): void
    {
        self::free();

        self::$_config = null;
    }

    public static function config(?string $key = null)
    {
        if ( self::$_config === null )
        {
            self::$_config = (array) config::instance('cache')->get();

            self::_apply();
        }

        return $key === null ? self::$_config : (self::$_config[$key] ?? null);
    }

    /**
     * The configured default repository, built without opening an optional service.
     */
    public static function repository(): repository
    {
        self::config();

        return runtime::share(
            self::SHARE_KEY,
            static function (): repository
            {
                $store = self::$_enabled ? self::_driver() : null;

                return new repository(
                    $store,
                    (string) (self::$_config['prefix'] ?? ''),
                    (int) (self::$_config['cache_time'] ?? 7200),
                    self::$_need_mem && PHP_SAPI !== 'cli'
                );
            },
            static function (repository $repository): void
            {
                $repository->close();
            }
        );
    }

    public static function set($key, $value, $cachetime = null): bool
    {
        return self::repository()->set($key, $value, $cachetime);
    }

    /**
     * Read a value.
     *
     * @param string $key     Key
     * @param mixed  $default Returned when the key is missing, and only then; a cached false, 0,
     *                        '' or null comes back as itself
     *
     * @return mixed
     */
    public static function get($key, $default = false)
    {
        return self::repository()->get($key, $default);
    }

    public static function del($key): int
    {
        return self::repository()->del($key);
    }

    public static function ttl($key): int
    {
        return self::repository()->ttl($key);
    }

    public static function expire($key, int $cachetime): bool
    {
        return self::repository()->expire($key, $cachetime);
    }

    /**
     * Whether the cache holds a value under a key. Presence, not truth: a cached 0 is present.
     *
     * @param string $key Key
     *
     * @return bool
     */
    public static function has($key): bool
    {
        return self::repository()->has($key);
    }

    public static function remember($key, $cachetime, callable $producer)
    {
        return self::repository()->remember($key, $cachetime, $producer);
    }

    public static function remember_forever($key, callable $producer)
    {
        return self::repository()->remember_forever($key, $producer);
    }

    public static function tags($tags): tags
    {
        return self::repository()->tags($tags);
    }

    public static function inc($key, int $step = 1)
    {
        return self::repository()->inc($key, $step);
    }

    public static function dec($key, int $step = 1)
    {
        return self::repository()->dec($key, $step);
    }

    public static function free_mem(bool $flush_driver = false): bool
    {
        if ( !$flush_driver )
        {
            $repository = runtime::get(self::SHARE_KEY);

            return !$repository instanceof repository || $repository->free_mem();
        }

        return self::repository()->free_mem(true);
    }

    public static function free(): bool
    {
        return runtime::forget(self::SHARE_KEY);
    }

    private static function _apply(): void
    {
        self::$_enabled    = !empty(self::$_config['enable']);
        self::$_cache_type = (string) (self::$_config['cache_type'] ?? 'file');
        self::$_need_mem   = (bool) (self::$_config['need_mem'] ?? true);
    }

    private static function _driver(): store
    {
        switch ( self::$_cache_type )
        {
            case 'redis':
                return redis::instance('cache');

            case 'memcached':
                return new memcached((array) (self::config('memcached') ?? []));

            case 'memory':
                return new memory();

            default:
                return file::factory(plato::cache_path(self::config('cache_name') ?? 'cache_data'));
        }
    }
}
