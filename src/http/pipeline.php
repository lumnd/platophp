<?php

/**
 * Middleware pipeline: what runs around a controller action
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\http;

use Closure;
use plato\config;
use plato\exception\route_exception;

/**
 * The middleware pipeline plato::run() dispatches through.
 *
 * Before this, the only way to run something around every request was one of the three global event
 * hooks -- ON_REQUEST, BEFORE_ACTION, AFTER_ACTION -- which cannot be scoped to a route, cannot wrap
 * the request (a before and an after hook are two functions with nothing between them) and cannot
 * stop it. Those are the three things CORS, a rate limiter, a maintenance switch and a request log
 * all need, so they get a pipeline of their own rather than a fourth hook. BEFORE_ACTION and
 * AFTER_ACTION are gone since; this is what replaced them.
 *
 * A middleware is anything callable that takes the rest of the pipeline:
 *
 *     class throttle
 *     {
 *         public function handle(callable $next)
 *         {
 *             if ( over_limit() )
 *             {
 *                 resp::status(429)->json(['error' => 'slow down']);   // no $next: the request stops
 *                 return;
 *             }
 *
 *             return $next();                                          // through to the action
 *         }
 *     }
 *
 * Accepted forms: a class name whose objects have handle() or __invoke(), a Closure, and any other
 * callable -- ['model\auth', 'check'] included. A class name is instantiated per request, and its
 * constructor takes no arguments: this package has no container, and a middleware that needs
 * collaborators reaches for them the way the rest of an application does.
 *
 * Which routes a middleware applies to is configuration, keyed by pattern, in config/config.php:
 *
 *     'middleware' => [
 *         '*'          => ['middleware\cors', 'middleware\request_log'],
 *         'admin:*'    => ['middleware\admin_only'],
 *         'order:pay'  => ['middleware\idempotent'],
 *     ],
 *
 * Order is the order of the file: every entry of '*' first, then the more specific patterns, and
 * within a pattern the order it lists. A class named by two patterns runs once, at the first
 * position it appeared in -- listing a middleware globally and again for one route is a
 * configuration mistake, not a request to run it twice.
 *
 * Where it sits in a request: after routing and action-method validation, around the destination.
 * For an ordinary request that destination covers CSRF verification, authentication and the action;
 * a middleware may refuse the request before any of them runs. For an automatic CORS preflight the
 * destination is an empty 204, so route middleware can decorate or replace it without executing
 * CSRF, authentication or the action. A request that never resolved to a route does not reach the
 * pipeline at all: there is no ct:ac to match patterns against, and a 404 is not something to run
 * middleware around.
 */
class pipeline
{
    /**
     * Middleware by route pattern, filled on first use from config/config.php
     *
     * @var array<string, array<int, mixed>>|null
     */
    private static $_config = null;

    /**
     * Hand the pipeline its configuration instead of letting it read config/config.php.
     *
     * Meant for tests and for long running entry points; an application says it in config/config.php.
     *
     * Merges on top of the file settings, so an override names only the patterns it changes.
     *
     * @param array<string, array<int, mixed>> $map Route pattern => middleware list
     *
     * @return void
     */
    public static function configure(array $map): void
    {
        self::$_config = $map + self::config();
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
     * The configured map, read on the first call that needs it.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function config(): array
    {
        if ( self::$_config === null )
        {
            self::$_config = (array) config::instance('config')->get('middleware', []);
        }

        return self::$_config;
    }

    /**
     * The middleware that applies to one route, in the order it will run.
     *
     * @param string $ct Controller name
     * @param string $ac Action name
     *
     * @return array<int, mixed>
     */
    public static function for_route(string $ct, string $ac): array
    {
        $ct    = strtolower($ct);
        $ac    = strtolower($ac);
        $stack = [];

        foreach ( self::config() as $pattern => $middleware )
        {
            if ( !self::matches((string) $pattern, $ct, $ac) )
            {
                continue;
            }

            foreach ( (array) $middleware as $one )
            {
                // Same middleware from two patterns runs once, keeping its first position
                if ( is_string($one) && in_array($one, $stack, true) )
                {
                    continue;
                }

                $stack[] = $one;
            }
        }

        return $stack;
    }

    /**
     * Whether a pattern covers a route.
     *
     * Three forms and no regular expressions: '*' for every route, 'ct:*' for a controller, and
     * 'ct:ac' for one action. A pattern language here would be a second router, and the point of
     * matching on the resolved ct / ac rather than on the url is that there is only one.
     *
     * @param string $pattern
     * @param string $ct
     * @param string $ac
     *
     * @return bool
     */
    public static function matches(string $pattern, string $ct, string $ac): bool
    {
        $pattern = strtolower(trim($pattern));

        return $pattern === '*'
            || $pattern === $ct . ':*'
            || $pattern === $ct . ':' . $ac;
    }

    /**
     * Run a stack around a destination.
     *
     * The stack is folded from the end, so the first entry is the outermost: it is the one that
     * decides whether the rest runs at all.
     *
     * @param array<int, mixed> $middleware
     * @param callable          $destination What the innermost $next() reaches -- the action
     *
     * @return mixed  Whatever the outermost middleware returned, which is the destination's own
     *                return value when every one of them called $next
     * @throws route_exception When an entry is not something that can be run
     */
    public static function run(array $middleware, callable $destination)
    {
        $next = Closure::fromCallable($destination);

        foreach ( array_reverse($middleware) as $one )
        {
            $current = $next;
            $next    = static function () use ($one, $current)
            {
                return self::_call($one, $current);
            };
        }

        return $next();
    }

    /**
     * Run one middleware.
     *
     * @param mixed    $middleware Class name, Closure or callable
     * @param callable $next
     *
     * @return mixed
     * @throws route_exception When the entry is not something that can be run
     */
    private static function _call($middleware, callable $next)
    {
        if ( $middleware instanceof Closure )
        {
            return $middleware($next);
        }

        if ( is_string($middleware) && class_exists($middleware) )
        {
            $instance = new $middleware();

            if ( method_exists($instance, 'handle') )
            {
                return $instance->handle($next);
            }

            if ( is_callable($instance) )
            {
                return $instance($next);
            }

            throw new route_exception(
                [$middleware . ' has neither handle() nor __invoke()'],
                2010
            );
        }

        if ( is_callable($middleware) )
        {
            return call_user_func($middleware, $next);
        }

        throw new route_exception(
            [is_string($middleware) ? $middleware : gettype($middleware)],
            2010
        );
    }
}
