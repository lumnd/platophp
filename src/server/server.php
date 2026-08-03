<?php

/**
 * Server facade: static shortcuts for the configured default listener
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\server;

use plato\config;
use plato\exception\server_exception;
use plato\runtime;

/**
 * This package ships the contract and dispatcher; adapter packages provide driver objects.
 */
class server
{
    public const ADAPTERS = [
        'workerman' => 'lumnd/plato-workerman',
    ];

    private const SHARE_PREFIX = 'server.';

    /** @var array<string, mixed>|null */
    private static $_config = null;

    /** @var array<string, string> */
    private static $_drivers = [];

    /** @var array<string, true> */
    private static $_built = [];

    public static function configure(array $config): void
    {
        self::$_config = $config + (array) self::config();

        self::_flush_drivers();
    }

    public static function reset(): void
    {
        self::_flush_drivers();

        self::$_config = null;
    }

    public static function config(?string $key = null)
    {
        if ( self::$_config === null )
        {
            self::$_config = (array) config::instance('server')->get();
        }

        return $key === null ? self::$_config : (self::$_config[$key] ?? null);
    }

    public static function register_driver(string $name, string $class): void
    {
        if ( !is_a($class, driver::class, true) )
        {
            throw new server_exception(sprintf(
                'server driver "%s" must implement %s, %s does not',
                $name,
                driver::class,
                $class
            ));
        }

        self::$_drivers[$name] = $class;
    }

    /**
     * Return one independently configured listener driver.
     */
    public static function driver(?string $name = null): driver
    {
        $name   = self::_name($name);
        $config = self::settings($name);

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
                $driver->stop();
            }
        );
    }

    /**
     * Settings of one listener without resolving its adapter.
     *
     * @return array<string, mixed>
     */
    public static function settings(?string $name = null): array
    {
        $name   = self::_name($name);
        $config = self::config('servers')[$name] ?? null;

        if ( !is_array($config) )
        {
            throw new server_exception(sprintf('server "%s" is not configured', $name));
        }

        return $config;
    }

    public static function start(?string $name = null): void
    {
        $name = self::_name($name);
        self::_apply_dispatch($name);
        self::driver($name)->start();
    }

    public static function stop(?string $name = null): void
    {
        $name   = self::_name($name);
        $driver = runtime::get(self::SHARE_PREFIX . $name);

        if ( $driver instanceof driver )
        {
            $driver->stop();
        }
    }

    public static function send(string $id, $payload): bool
    {
        self::_apply_dispatch(self::_name(null));

        return self::driver()->send($id, is_string($payload) ? $payload : dispatcher::encode($payload));
    }

    public static function close(string $id, int $code = 1000, string $reason = ''): bool
    {
        return self::driver()->close($id, $code, $reason);
    }

    public static function connection(string $id)
    {
        return self::driver()->connection($id);
    }

    /** @return array<int, string> */
    public static function connections(): array
    {
        return self::driver()->connections();
    }

    private static function _name(?string $name): string
    {
        $name = $name ?? (string) (self::config('default') ?? '');

        if ( $name === '' )
        {
            throw new server_exception('no default server is configured');
        }

        return $name;
    }

    private static function _apply_dispatch(string $name): void
    {
        $dispatch = self::settings($name)['dispatch'] ?? null;

        if ( is_array($dispatch) )
        {
            dispatcher::configure($dispatch);
        }
    }

    private static function _flush_drivers(): void
    {
        foreach ( array_keys(self::$_built) as $name )
        {
            runtime::forget(self::SHARE_PREFIX . $name);
        }

        self::$_built = [];
    }

    private static function _resolve(string $driver, string $server): string
    {
        $class = self::$_drivers[$driver] ?? $driver;

        if ( !class_exists($class) )
        {
            throw new server_exception(sprintf(
                'server "%s" names driver "%s", which does not exist.%s',
                $server,
                $driver,
                isset(self::ADAPTERS[$driver])
                    ? ' This package ships no driver: run `composer require ' . self::ADAPTERS[$driver]
                        . '`, or name a class implementing ' . driver::class . ' in config/server.php'
                    : ''
            ));
        }

        if ( !is_a($class, driver::class, true) )
        {
            throw new server_exception(sprintf(
                'server "%s" names driver "%s", which does not implement %s',
                $server,
                $driver,
                driver::class
            ));
        }

        return $class;
    }
}
