<?php

/**
 * Event hooks: binding, unbinding and dispatching
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato;

use plato\exception\event_exception;
use plato\http\req;
use plato\security\security;

/**
 * Event dispatcher.
 *
 * Handlers run synchronously, inside the current PHP process, in the order they were bound. The
 * first argument a handler receives is the event itself, the rest are the arguments trigger() was
 * called with.
 *
 * An event is any int or string; the built in ones are the class constants below. The default
 * handlers for those are installed by start(), which plato::registry() calls during boot.
 */
class event
{
    /**
     * Built-in framework events.
     *
     * ON_REQUEST, ON_FILTER and ON_SQL are triggered by the framework; ON_EXCEPTION, ON_ERROR
     * and ON_RESPONSE are left to the application to trigger.
     *
     * Values 1 and 2 are reserved. Keeping the remaining values stable allows applications to
     * persist event ids safely.
     */
    public const ON_EXCEPTION  = 3;
    public const ON_ERROR      = 4;
    public const ON_REQUEST    = 5;
    public const ON_RESPONSE   = 6;
    public const ON_FILTER     = 7;
    public const ON_SQL        = 8;

    /**
     * Handle of the last bound listener, incremented on every bind().
     *
     * @var int
     */
    private static $_fh = 0;

    /**
     * Bound listeners, event => handle => ['m' => callable, 't' => remaining calls or null].
     *
     * @var array<int|string, array<int, array{m: callable, t: int|null}>>
     */
    private static $_monitors = [];

    /**
     * Whether start() already installed the built in handlers.
     *
     * @var bool
     */
    private static $_started = false;

    /**
     * Bind a listener to an event.
     *
     * @param int|string $event
     * @param mixed      $method Anything is_callable() accepts
     * @param int|null   $times  How many times the listener may run, null for no limit
     * @return int Listener handle, pass it to off() to unbind
     * @throws event_exception When $method cannot be called
     */
    public static function bind($event, $method, $times = null)
    {
        if ( !is_callable($method) )
        {
            throw new event_exception([self::_callable_name($method)], 5003);
        }

        $fh = ++self::$_fh;
        self::$_monitors[$event][$fh] = ['m' => $method, 't' => $times === null ? null : (int) $times];

        return $fh;
    }

    /**
     * Bind a listener with no call limit.
     *
     * @param int|string $event
     * @param mixed      $method Anything is_callable() accepts
     * @return int Listener handle
     * @throws event_exception
     */
    public static function on($event, $method)
    {
        return self::bind($event, $method);
    }

    /**
     * Bind a listener that runs once.
     *
     * @param int|string $event
     * @param mixed      $method Anything is_callable() accepts
     * @return int Listener handle
     * @throws event_exception
     */
    public static function one($event, $method)
    {
        return self::bind($event, $method, 1);
    }

    /**
     * Unbind one listener, or every listener of an event when $fh is omitted.
     *
     * @param int|string $event
     * @param int|null   $fh Handle returned by bind() / on() / one()
     * @return bool False when there was nothing to unbind
     */
    public static function off($event, $fh = null)
    {
        if ( !isset(self::$_monitors[$event]) )
        {
            return false;
        }

        if ( $fh === null )
        {
            unset(self::$_monitors[$event]);
            return true;
        }

        if ( !isset(self::$_monitors[$event][$fh]) )
        {
            return false;
        }

        unset(self::$_monitors[$event][$fh]);

        return true;
    }

    /**
     * Trigger an event.
     *
     * @param int|string   $event
     * @param array<mixed> $params Arguments passed to the listeners after the event itself
     * @return bool False when the event has no listener
     */
    public static function trigger($event, array $params = [])
    {
        if ( empty(self::$_monitors[$event]) )
        {
            return false;
        }

        // The event goes in front of the arguments the listeners receive
        array_unshift($params, $event);

        // Iterate over a snapshot: a listener is free to bind or unbind while the event is being
        // dispatched, the live list is only consulted to skip what got unbound meanwhile
        $monitors = self::$_monitors[$event];

        foreach ( $monitors as $fh => $monitor )
        {
            if ( !isset(self::$_monitors[$event][$fh]) )
            {
                continue;
            }

            // Consume the quota before the call, so a listener that throws, or that triggers the
            // same event again, cannot run more often than it was bound for
            if ( $monitor['t'] !== null && --self::$_monitors[$event][$fh]['t'] <= 0 )
            {
                unset(self::$_monitors[$event][$fh]);
            }

            call_user_func_array($monitor['m'], $params);
        }

        if ( empty(self::$_monitors[$event]) )
        {
            unset(self::$_monitors[$event]);
        }

        return true;
    }

    /**
     * Install the built in listeners, called once by plato::registry().
     *
     * @return void
     */
    public static function start()
    {
        if ( self::$_started )
        {
            return;
        }
        self::$_started = true;

        self::on(self::ON_EXCEPTION, [self::class, 'on_exception']);
        self::on(self::ON_FILTER, [self::class, 'on_filter']);
        self::on(self::ON_REQUEST, [self::class, 'on_request']);
        self::on(self::ON_RESPONSE, [self::class, 'on_response']);
        self::on(self::ON_SQL, [self::class, 'on_sql']);
    }

    /**
     * Default ON_EXCEPTION listener: write the error code and its arguments to the log.
     *
     * @param int|string $event
     * @param int|string $code   Key of config/exception.php
     * @param mixed      $params Message template arguments
     * @return void
     */
    public static function on_exception($event, $code, $params = [])
    {
        if ( !is_array($params) )
        {
            $params = [$params];
        }

        $lines = [];
        foreach ( $params as $param )
        {
            $lines[] = is_scalar($param) ? (string) $param : json_encode($param, JSON_UNESCAPED_UNICODE);
        }

        log::error("ERROR CODE: {$code}\n" . implode("\n", $lines));
    }

    /**
     * Default ON_FILTER listener: run the request through the ip and country filters.
     *
     * @param int|string $event
     * @return void
     */
    public static function on_filter($event)
    {
        security::ip_filter();
        security::country_filter();
    }

    /**
     * Default ON_REQUEST listener: send the CORS headers and log the incoming request.
     *
     * @param int|string $event
     * @return void
     */
    public static function on_request($event)
    {
        if ( req::method() != 'CLI' )
        {
            self::_allow_origin();
        }

        if ( !self::_is_logged_request() )
        {
            return;
        }

        log::info('request: ' . json_encode(self::_request_forms(), JSON_UNESCAPED_UNICODE), req::method());
    }

    /**
     * Default ON_RESPONSE listener: log the request the response belongs to.
     *
     * The framework never triggers this one, an application that wants it has to call
     * event::trigger(event::ON_RESPONSE) where it writes its response.
     *
     * @param int|string $event
     * @return void
     */
    public static function on_response($event)
    {
        if ( !self::_is_logged_request() )
        {
            return;
        }

        log::info('response: ' . json_encode(self::_request_forms(), JSON_UNESCAPED_UNICODE));
    }

    /**
     * Default ON_SQL listener: log the statement when debug is on.
     *
     * @param int|string  $event
     * @param string|null $sql
     * @param string|null $db_name    Connection the statement ran on
     * @param float|int   $query_time Seconds the statement took
     * @return void
     */
    public static function on_sql($event, ?string $sql = null, ?string $db_name = null, $query_time = 0)
    {
        if ( plato::debug() )
        {
            log::info(sprintf('SQL Query [%s]: %s (%ss)', $db_name, $sql, $query_time));
        }
    }

    /**
     * Send the CORS headers when the request origin is allowed, see security.allow_origin in
     * config/config.php.
     *
     * A configured origin is echoed back with Access-Control-Allow-Credentials, so a cookie
     * authenticated request can be read by that site. The '*' fallback is answered with a literal
     * '*' and no credentials: reflecting any origin while allowing credentials would let every
     * site on the web read authenticated responses.
     *
     * @return void
     */
    private static function _allow_origin()
    {
        $allow_origin = security::config('allow_origin');
        $origin       = (string) req::server('HTTP_ORIGIN');

        if ( !is_array($allow_origin) || $allow_origin === [] || $origin === '' || headers_sent() )
        {
            return;
        }

        // A named origin wins over '*', so a list that names domains only answers those
        if ( in_array($origin, $allow_origin, true) )
        {
            header("Access-Control-Allow-Origin: {$origin}");
            header('Access-Control-Allow-Credentials: true');
            // The response now depends on the request origin, keep shared caches honest
            header('Vary: Origin', false);
        }
        elseif ( in_array('*', $allow_origin, true) )
        {
            header('Access-Control-Allow-Origin: *');
        }
        else
        {
            return;
        }

        header('Access-Control-Allow-Methods: GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS');
    }

    /**
     * Whether the current request is one config/log.php asks to log.
     *
     * @return bool
     */
    private static function _is_logged_request()
    {
        $methods = (array) config::instance('log')->get('log_request_methods', []);
        if ( !in_array('*', $methods, true) && !in_array(req::method(), $methods, true) )
        {
            return false;
        }

        $uris = (array) config::instance('log')->get('log_request_uris', []);
        if ( in_array('*', $uris, true) )
        {
            return true;
        }

        return in_array(sprintf('ct=%s&ac=%s', plato::$ct, plato::$ac), $uris, true);
    }

    /**
     * Submitted data of the current request, with the route it was dispatched to.
     *
     * @return array<string, mixed>
     */
    private static function _request_forms()
    {
        return array_merge(['ct' => plato::$ct, 'ac' => plato::$ac], req::$forms);
    }

    /**
     * Printable name of something that was expected to be callable, for error messages.
     *
     * @param mixed $method
     * @return string
     */
    private static function _callable_name($method)
    {
        if ( is_string($method) )
        {
            return $method;
        }

        if ( is_object($method) )
        {
            return get_class($method);
        }

        if ( is_array($method) )
        {
            $class  = isset($method[0]) && is_object($method[0]) ? get_class($method[0]) : ($method[0] ?? '?');
            $method = $method[1] ?? '?';

            return (is_scalar($class) ? (string) $class : gettype($class)) . '::'
                . (is_scalar($method) ? (string) $method : gettype($method));
        }

        return gettype($method);
    }
}
