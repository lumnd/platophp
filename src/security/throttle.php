<?php

/**
 * Request rate limiting: a fixed window counter, and the middleware that applies it
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\security;

use plato\cache\cache;
use plato\config;
use plato\http\req;
use plato\http\resp;
use plato\http\route;

/**
 * How many times something may happen in a window of time.
 *
 * Two ways in. As a library:
 *
 *     if ( !throttle::attempt('login:' . $email, 5, 300) )
 *     {
 *         // five attempts per five minutes is used up
 *     }
 *
 * and as a middleware, named in the `middleware` map of config/config.php with its limits in the
 * `throttle` section:
 *
 *     'middleware' => ['*' => ['plato\security\throttle']],
 *     'throttle'   => ['limit' => 60, 'window' => 60, 'by' => 'ip'],
 *
 * **This is a fixed window counter, not a token bucket.** The window is a slot of `window`
 * seconds cut from the epoch, so the counter of a key is `<key>|<floor(now / window)>`: a new slot
 * starts at a key nobody has written to yet, which is what makes the count reset without anything
 * having to clear it. The slot key still gets a lifetime on its first hit, because a key nothing
 * reads again is not a key anything deletes.
 *
 * The cost of that simplicity is the boundary burst: a caller can spend its whole allowance at the
 * end of one slot and the whole of the next one at the start of the following, so up to `2 * limit`
 * requests can land inside one window's worth of time. A sliding window would need either a sorted
 * set per key (redis only) or a Lua script (redis only), and this package's cache facade has four
 * drivers. Halve the window if the burst matters more than the extra keys.
 *
 * **Atomic on redis only.** The counter goes through cache::inc(), which is a single INCR on the
 * redis driver and a read-modify-write on file, memcached and memory. Two processes racing on a
 * non redis store can both read the same value and let one request through over the limit; for a
 * limiter that is a fair trade against a lock on every request, but a limit that must hold exactly
 * -- money, not politeness -- wants redis.
 */
class throttle
{
    /**
     * Prefix of the counter keys
     */
    private const PREFIX = 'throttle|';

    /**
     * Settings, null until config() reads the `throttle` section of config/config.php
     *
     * @var array<string, mixed>|null
     */
    private static $_config = null;

    /**
     * Defaults, replaced key by key from the configuration
     *
     * @var array<string, mixed>
     */
    private const DEFAULTS = [
        // Whether the middleware limits anything at all; the static calls ignore this
        'enable' => true,
        // Requests allowed per window
        'limit'  => 60,
        // Length of the window in seconds
        'window' => 60,
        // What counts as one caller: ip, route (one counter for everybody on this route),
        // ip_route (per caller per route), or any callable answering a string
        'by'     => 'ip_route',
        // Body of the 429. A callable is called with the seconds left and answers on its own
        'message' => 'Too many requests',
    ];

    /**
     * The effective settings, read from the `throttle` section on the first call that needs them.
     *
     * @param string|null $key One setting, or null for all of them
     *
     * @return mixed
     */
    public static function config(?string $key = null)
    {
        if ( self::$_config === null )
        {
            self::$_config = (array) config::instance('config')->get('throttle', []) + self::DEFAULTS;
        }

        return $key === null ? self::$_config : (self::$_config[$key] ?? null);
    }

    /**
     * Hand the limiter its settings instead of letting it read config/config.php.
     *
     * Merges on top of the file settings, so an override names only what it changes.
     *
     * @param array<string, mixed> $config Same shape as the `throttle` section
     *
     * @return void
     */
    public static function configure(array $config)
    {
        self::$_config = $config + (array) self::config();
    }

    /**
     * Drop the overrides, so the next read comes from the file again.
     *
     * @return void
     */
    public static function reset()
    {
        self::$_config = null;
    }

    /**
     * Count one attempt, and say whether it is within the limit.
     *
     * The attempt is counted whether or not it is allowed: a caller hammering a closed door keeps
     * the door closed, which is the point.
     *
     * @param string $key    What is being limited -- an ip, a user id, a route, a combination
     * @param int    $limit  Attempts allowed per window; 0 or less means no limit
     * @param int    $window Window in seconds
     *
     * @return bool  False when this attempt is over the limit
     */
    public static function attempt(string $key, int $limit, int $window = 60): bool
    {
        if ( $limit <= 0 )
        {
            return true;
        }

        $slot = self::_slot_key($key, $window);
        $hits = cache::inc($slot);

        // The store is unreachable or caching is off: a limiter that cannot count must not turn
        // into a limiter that refuses everything
        if ( $hits === false )
        {
            return true;
        }

        // inc() leaves the lifetime alone, so the first hit of a slot is the one that has to give
        // the counter one. Without it every window of every caller would stay in the store for
        // good -- the slot is never read again, but nothing deletes it either.
        if ( $hits <= 1 )
        {
            cache::expire($slot, $window);
        }

        return $hits <= $limit;
    }

    /**
     * Attempts counted for a key in the current window, without counting another one.
     *
     * @param string $key
     * @param int    $window Window in seconds
     *
     * @return int
     */
    public static function hits(string $key, int $window = 60): int
    {
        $hits = cache::get(self::_slot_key($key, $window));

        return is_numeric($hits) ? (int) $hits : 0;
    }

    /**
     * Attempts left in the current window.
     *
     * @param string $key
     * @param int    $limit
     * @param int    $window Window in seconds
     *
     * @return int  Never negative
     */
    public static function remaining(string $key, int $limit, int $window = 60): int
    {
        return (int) max(0, $limit - self::hits($key, $window));
    }

    /**
     * Seconds until the current window ends and the count starts again.
     *
     * @param int $window Window in seconds
     *
     * @return int  At least 1, so a Retry-After of 0 never tells a client to retry immediately
     */
    public static function retry_after(int $window = 60): int
    {
        $window = max(1, $window);

        return (int) max(1, $window - (time() % $window));
    }

    /**
     * Drop the count of a key in the current window.
     *
     * The usual caller is a successful sign in clearing the failed attempts of an account.
     *
     * @param string $key
     * @param int    $window Window in seconds
     *
     * @return void
     */
    public static function clear(string $key, int $window = 60)
    {
        cache::del(self::_slot_key($key, $window));
    }

    /**
     * Middleware entry point: limit this request, or answer 429 without reaching the action.
     *
     * @param callable $next
     *
     * @return mixed
     */
    public function handle(callable $next)
    {
        if ( empty(self::config('enable')) )
        {
            return $next();
        }

        $limit  = (int) self::config('limit');
        $window = max(1, (int) self::config('window'));
        $key    = self::_caller_key();

        if ( self::attempt($key, $limit, $window) )
        {
            resp::headers([
                'X-RateLimit-Limit'     => (string) $limit,
                'X-RateLimit-Remaining' => (string) self::remaining($key, $limit, $window),
            ]);

            return $next();
        }

        $retry_after = self::retry_after($window);
        $message     = self::config('message');

        if ( is_callable($message) )
        {
            return $message($retry_after);
        }

        // No $next(): the request stops here.
        return resp::header('Retry-After', (string) $retry_after)
            ->header('X-RateLimit-Limit', (string) $limit)
            ->header('X-RateLimit-Remaining', '0')
            ->json(['code' => 429, 'msg' => $message, 'retry_after' => $retry_after], 429);
    }

    /**
     * What counts as one caller for this request.
     *
     * @return string
     */
    private static function _caller_key()
    {
        $by = self::config('by') ?? 'ip_route';

        if ( is_callable($by) )
        {
            return (string) $by();
        }

        $route = route::ct() . ':' . route::ac();

        switch ( $by )
        {
            case 'ip':
                return req::ip();

            case 'route':
                return $route;

            default:
                return req::ip() . '|' . $route;
        }
    }

    /**
     * Cache key of a counter: the caller key plus the index of the current window.
     *
     * @param string $key
     * @param int    $window
     *
     * @return string
     */
    private static function _slot_key(string $key, int $window)
    {
        $window = max(1, $window);

        // time() and not plato::timestamp(): a window is wall clock, and the request timestamp is
        // frozen for the whole request -- in a resident worker that would pin every request of a
        // frame to one slot
        return self::PREFIX . $key . '|' . (int) floor(time() / $window);
    }
}
