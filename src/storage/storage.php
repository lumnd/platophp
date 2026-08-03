<?php

/**
 * Storage facade: dispatches to the disk named by the configuration
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\storage;

use plato\config;
use plato\exception\storage_exception;
use plato\runtime;

/**
 * Files, wherever they are kept.
 *
 *     storage::put('avatars/7.png', $bytes);
 *     storage::get('avatars/7.png');            // null when it is not there
 *     storage::disk('archive')->put('backups/db.sql.gz', $handle);
 *
 * `src/file.php` stays what it always was -- a set of local filesystem helpers, working on absolute
 * paths. This is the other thing: one relative path space, one set of calls, and a backend chosen
 * by configuration rather than by the call site. Code written against it does not know or care
 * whether the bytes end up on a disk or in a bucket.
 *
 * Paths are relative, forward slashed and validated: see plato\storage\path for exactly what is
 * refused and why nothing is normalised.
 *
 * Disks live in plato\runtime rather than in a static property here. A custom disk may hold remote
 * connections, so a forked child must get its own instance, and the runtime registry is the one
 * place that knows what a fork invalidates.
 */
class storage
{
    /**
     * Prefix of the runtime keys disks are registered under
     */
    private const SHARE_KEY = 'storage.disk.';

    /**
     * Configuration, null until config() reads it
     *
     * @var array<string, mixed>|null
     */
    private static $_config = null;

    /**
     * Disk name => class, for the drivers the package ships and the ones an application registers
     *
     * @var array<string, class-string<disk>>
     */
    private static $_drivers = [
        'local' => local::class,
    ];

    /**
     * Hand the facade its configuration instead of letting it read config/storage.php.
     *
     * @param array<string, mixed> $config Same shape as config/storage.php
     *
     * @return void
     */
    public static function configure(array $config): void
    {
        $current = (array) self::config();

        // Whatever is live was built for the settings that were in force before this call
        self::flush();

        self::$_config = $config + $current;
    }

    /**
     * Drop the overrides and every built disk, so the next read comes from the file again.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::flush();

        self::$_config = null;
    }

    /**
     * Register a driver under a short name, so `'driver' => 'archive'` resolves.
     *
     * @param string $name
     * @param string $class Class implementing disk
     *
     * @return void
     * @throws storage_exception When the class is not a disk
     */
    public static function extend(string $name, string $class): void
    {
        if ( !is_a($class, disk::class, true) )
        {
            throw new storage_exception($class . ' is not a ' . disk::class);
        }

        self::$_drivers[$name] = $class;
    }

    /**
     * One disk by name, or the configured default.
     *
     * @param string|null $name
     *
     * @return disk
     * @throws storage_exception When there is no such disk, or its driver cannot be resolved
     */
    public static function disk(?string $name = null): disk
    {
        $name = $name ?? (string) (self::config('default') ?? 'local');

        return runtime::share(self::SHARE_KEY . $name, static function () use ($name): disk
        {
            return self::_build($name);
        });
    }

    /**
     * Drop every built disk.
     *
     * @return void
     */
    public static function flush(): void
    {
        foreach ( array_keys((array) (self::config('disks') ?? [])) as $name )
        {
            runtime::forget(self::SHARE_KEY . $name);
        }
    }

    /**
     * @param string $path
     *
     * @return string|null
     */
    public static function get(string $path)
    {
        return self::disk()->get($path);
    }

    /**
     * @param string               $path
     * @param string|resource      $contents
     * @param array<string, mixed> $options
     *
     * @return bool
     */
    public static function put(string $path, $contents, array $options = []): bool
    {
        return self::disk()->put($path, $contents, $options);
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    public static function exists(string $path): bool
    {
        return self::disk()->exists($path);
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    public static function delete(string $path): bool
    {
        return self::disk()->delete($path);
    }

    /**
     * @param string $from
     * @param string $to
     *
     * @return bool
     */
    public static function copy(string $from, string $to): bool
    {
        return self::disk()->copy($from, $to);
    }

    /**
     * @param string $from
     * @param string $to
     *
     * @return bool
     */
    public static function move(string $from, string $to): bool
    {
        return self::disk()->move($from, $to);
    }

    /**
     * @param string $path
     *
     * @return int|null
     */
    public static function size(string $path)
    {
        return self::disk()->size($path);
    }

    /**
     * @param string $path
     *
     * @return int|null
     */
    public static function modified(string $path)
    {
        return self::disk()->modified($path);
    }

    /**
     * @param string $prefix
     * @param bool   $recursive
     *
     * @return array<int, string>
     */
    public static function files(string $prefix = '', bool $recursive = false): array
    {
        return self::disk()->files($prefix, $recursive);
    }

    /**
     * @param string $path
     *
     * @return string|null
     */
    public static function url(string $path)
    {
        return self::disk()->url($path);
    }

    /**
     * @param string $path
     * @param int    $seconds
     *
     * @return string|null
     */
    public static function temporary_url(string $path, int $seconds = 3600)
    {
        return self::disk()->temporary_url($path, $seconds);
    }

    /**
     * The configuration, read on the first call that needs it.
     *
     * @param string|null $key One setting, or null for all of them
     *
     * @return mixed
     */
    public static function config(?string $key = null)
    {
        if ( self::$_config === null )
        {
            self::$_config = (array) config::instance('storage')->get();
        }

        return $key === null ? self::$_config : (self::$_config[$key] ?? null);
    }

    /**
     * Build one disk from its configuration block.
     *
     * @param  string $name
     * @return disk
     * @throws storage_exception
     */
    private static function _build(string $name): disk
    {
        $settings = (array) (self::config('disks')[$name] ?? []);

        if ( $settings === [] )
        {
            throw new storage_exception('no storage disk called ' . $name . ' in config/storage.php');
        }

        // A short name, or a class name outright, so an application's own disk needs no registration
        $driver = (string) ($settings['driver'] ?? $name);
        $class  = self::$_drivers[$driver] ?? $driver;

        if ( !is_a($class, disk::class, true) )
        {
            throw new storage_exception(
                'storage disk ' . $name . ' names ' . $driver . ', which is not a ' . disk::class
            );
        }

        $disk = new $class();
        $disk->configure($settings);

        return $disk;
    }
}
