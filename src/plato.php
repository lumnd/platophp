<?php

/**
 * Bootstrap and dispatch: registry() sets the runtime up, run() maps ct/ac onto a controller
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato;

use plato\cache\cache;
use plato\database\db;
use plato\exception\auth_exception;
use plato\exception\bootstrap_exception;
use plato\exception\controller_exception;
use plato\exception\route_exception;
use plato\http\envelope;
use plato\http\pipeline;
use plato\http\req;
use plato\http\reply;
use plato\http\resp;
use plato\http\route;
use plato\http\upload;
use plato\debug\benchmark;
use plato\debug\error_handler;
use plato\debug\profiler;
use plato\security\security;
use plato\view\msgbox;
use Throwable;

/**
 * Framework core.
 *
 * Installed as a library under vendor/, this class never touches the host process at file load
 * time. Everything happens inside registry() and is driven by the configuration passed in --
 * the framework reads self::$config only, it defines no global constants of its own.
 */
class plato
{
    /**
     * Runtime configuration, as passed to registry().
     *
     * @var array<string, mixed>
     */
    public static $config = [];

    /**
     * Defaults applied to self::$config, and the fallback used by config() before registry()
     * has run at all.
     *
     * @var array<string, mixed>
     */
    protected static $_defaults = [
        'env_path'             => '.env',
        // Empty on purpose: env() falls back to APP_ENV in .env before it falls back to 'pub'
        'env'                  => '',
        'editor'               => 'mvim://open?url=file://%file&line=%line',
        'session_start'        => false,
        'cli_auth'             => false,
        'cli_csrf'             => false,
        'controller_namespace' => 'control',
    ];

    /** Application root, from the app_path configuration */
    protected static $_app_path = '';

    /** .env file, from the env_path configuration */
    protected static $_env_path = '';

    /** Writable runtime directory, defaults to app_path/data */
    protected static $_data_path = '';

    /** Wall clock at the current request stamp, in seconds with microsecond precision */
    protected static $_start_time = 0.0;

    /** Allocated memory at the current request stamp, in bytes */
    protected static $_start_mem = 0;

    /** Unix timestamp of the current request, stable for its whole lifetime */
    protected static $_timestamp = 0;

    /**
     * Identity returned by the check_purview_handle callback.
     *
     * Null when no authentication ran -- an action declared auth = none, or a CLI entry point --
     * and also when it ran for an auth = optional action and found nobody signed in. The two are
     * not distinguishable from here; an action that needs to tell them apart knows which of the
     * two it declared.
     *
     * @var object|null
     */
    public static $auth = null;

    /** Current controller and action */
    public static $ct = '';
    public static $ac = '';

    /**
     * Initialize the framework, once per process.
     *
     * Supported configuration:
     *   app_path             application root, required
     *   env_path             .env file, defaults to '.env'
     *   data_path            writable runtime directory, defaults to app_path/data
     *   debug                true shows errors, false hides them; omit it to leave the host's
     *                        own error settings alone
     *   env                  runtime environment, defaults to APP_ENV from .env then to 'pub'
     *   editor               editor url template used by the debug output, %file / %line
     *   bootstrap            callable run before core initialization
     *   session_start        start the session, non CLI only; the store is php.ini's save_handler
     *   check_purview_handle callable(string $ct, string $ac): object|reply|null, the application's
     *                        authentication callback. Run before dispatching, for actions that
     *                        declared auth = required or optional; an identity lands in
     *                        plato::$auth, a reply answers the request instead. Returning null is
     *                        "nobody is signed in": an optional action runs anyway, a required one
     *                        is answered 401 -- return a reply to answer it some other way
     *   error_handle         callable(Throwable, int $status): ?reply, asked to render a failure
     *                        that no middleware could catch, web requests only -- see
     *                        error_handler::exception_reply()
     *   reset_handle         callable invoked after reset_request() clears framework state, for
     *                        application request-scoped state in a resident process
     *   cli_auth             also run check_purview_handle under CLI / a resident server, default false
     *   cli_csrf             also verify the CSRF token under CLI / a resident server, default false
     *   controller_namespace namespace the ct is resolved in, defaults to 'control'
     *
     * @param array|null $config
     * @return void
     * @throws bootstrap_exception When app_path is missing, unreadable, or data/ is not writable
     */
    public static function registry(?array $config = [])
    {
        self::$config = array_merge(self::$_defaults, (array) $config);

        self::$_start_time = microtime(true);
        self::$_start_mem  = memory_get_usage();
        self::$_timestamp  = time();

        self::$_env_path = (string) self::config('env_path');
        self::_load_env();

        self::$_app_path = rtrim((string) self::config('app_path', ''), DIRECTORY_SEPARATOR);

        if ( self::$_app_path === '' )
        {
            throw new bootstrap_exception(['app_path'], 1006);
        }

        if ( !is_readable(self::$_app_path) )
        {
            throw new bootstrap_exception([self::$_app_path], 1001);
        }

        $data_path        = (string) self::config('data_path', '');
        self::$_data_path = rtrim($data_path !== '' ? $data_path : self::$_app_path . DIRECTORY_SEPARATOR . 'data', DIRECTORY_SEPARATOR);

        foreach ( [self::log_path(), self::cache_path()] as $dir )
        {
            // is_dir() again: a concurrent process may have won the race
            if ( !is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir) )
            {
                throw new bootstrap_exception([$dir], 1007);
            }
        }

        // Application constants and shared definitions are injected here; the framework itself
        // never reaches back into application code
        $bootstrap = self::config('bootstrap');
        if ( !empty($bootstrap) && is_callable($bootstrap) )
        {
            call_user_func($bootstrap);
        }

        // No debug configuration means the host said nothing, so keep its php.ini settings
        if ( array_key_exists('debug', self::$config) && self::$config['debug'] !== null )
        {
            $debug = (bool) self::$config['debug'];
            error_reporting($debug ? E_ALL : E_ALL & ~E_NOTICE & ~E_DEPRECATED);
            ini_set('display_errors', $debug ? 'On' : 'Off');
        }

        if ( PHP_SAPI != 'cli' && self::config('session_start') )
        {
            $token = $_SERVER['HTTP_TOKEN'] ?? $_REQUEST['token'] ?? '';
            $token && session_id($token);

            // Where the session is kept is php.ini's business. The redis and memcached extensions
            // both provide native handlers with locking.
            session_start();
        }

        self::_bootstrap();
    }

    /**
     * Read a configuration value, falling back to the framework default.
     *
     * Usable before registry() has run, in which case only the defaults are visible.
     *
     * @param string|null $key     Configuration key, null returns the whole array
     * @param mixed       $default Returned when neither the config nor the defaults hold the key
     * @return mixed
     */
    public static function config($key = null, $default = null)
    {
        if ( $key === null )
        {
            return self::$config + self::$_defaults;
        }

        return self::$config[$key] ?? self::$_defaults[$key] ?? $default;
    }

    /**
     * Fully qualified controller class for a ct.
     *
     * The namespace comes from the controller_namespace configuration, so several applications
     * living in one repository -- one entry point each, admin/ and api/ say -- can each own a
     * namespace of their own and still hold controllers of the same name. Composer's PSR-4 map is
     * process wide and has no notion of the current entry point: mapping one `control\` prefix at
     * two directories makes the first one win every lookup, and an api request would silently get
     * the admin ctl_index. Naming them `admin\control` and `api\control` is what keeps them apart.
     *
     * The default is the bare 'control', which is what a single application skeleton wants.
     *
     * @param string $ct Controller name, as the router validated it
     * @return string
     */
    public static function controller_class($ct)
    {
        $namespace = trim((string) self::config('controller_namespace', 'control'), '\\');

        return ($namespace === '' ? '' : $namespace . '\\') . 'ctl_' . $ct;
    }

    /**
     * Whether debug output is enabled.
     *
     * @return bool
     */
    public static function debug()
    {
        return !empty(self::$config['debug']);
    }

    /**
     * Current runtime environment, conventionally 'dev', 'pre' or 'pub'.
     *
     * Read from the registry() configuration, then from APP_ENV in .env, so an application that
     * keeps its environment in .env does not have to pass it in twice. Nothing in the framework
     * branches on it; it exists for applications to ask is_env() with.
     *
     * @return string
     */
    public static function env()
    {
        $env = (string) self::config('env', '');

        if ( $env !== '' )
        {
            return $env;
        }

        return (string) ($_ENV['APP_ENV'] ?? 'pub');
    }

    /**
     * Whether the runtime environment matches.
     *
     * @param string $env
     * @return bool
     */
    public static function is_env($env)
    {
        return self::env() === $env;
    }

    /**
     * Editor URL template used by the debug output.
     *
     * @return string
     */
    public static function editor()
    {
        return (string) self::config('editor');
    }

    /**
     * Start a new request inside a resident process.
     *
     * The three readings below are stamped once, by registry(), because under php-fpm a process is
     * a request: `timestamp()` says "stable for its whole lifetime" and the lifetime ends with the
     * response. A resident worker -- Workerman, RoadRunner, FrankenPHP worker mode -- has no such
     * boundary, so without this every message after the first is stamped with the time the worker
     * booted, and app_total() reports how long the *worker* has been up rather than how long this
     * message took.
     *
     * The log's request id is request scoped for the same reason, and moves on here rather than in
     * the dispatcher so that anything else driving a resident process gets it by calling this one
     * method. Only that key is replaced: whatever else a worker put in the shared context is its
     * own business, and log::forget_context() is how it takes it back out.
     *
     * PHP 8.2 and later can also reset the process memory high-water mark. That scopes
     * app_total() memory growth to this request instead of the worker lifetime. Code that needs a
     * lifetime peak must track it independently.
     *
     * Called by plato\server\dispatcher between frames. Anything else serving many requests from one
     * process calls it too, first, before the capture() methods.
     *
     * @return void
     */
    public static function restamp()
    {
        self::$_start_time = microtime(true);
        self::$_start_mem  = memory_get_usage();

        if ( function_exists('memory_reset_peak_usage') )
        {
            memory_reset_peak_usage();
        }

        self::$_timestamp = time();

        log::context(['rid' => log::new_rid()]);
    }

    /**
     * Unix timestamp of the current request, stable for its whole lifetime.
     *
     * Falls back to the current time when the class is used without registry(). A resident worker
     * moves it on with restamp().
     *
     * @return int
     */
    public static function timestamp()
    {
        if ( self::$_timestamp === 0 )
        {
            self::$_timestamp = time();
        }

        return self::$_timestamp;
    }

    /**
     * Wall clock at the current request stamp.
     *
     * @return float
     */
    public static function start_time()
    {
        if ( self::$_start_time === 0.0 )
        {
            self::$_start_time = microtime(true);
        }

        return self::$_start_time;
    }

    /**
     * Allocated memory at the current request stamp.
     *
     * @return int
     */
    public static function start_mem()
    {
        if ( self::$_start_mem === 0 )
        {
            self::$_start_mem = memory_get_usage();
        }

        return self::$_start_mem;
    }

    /**
     * Application root, available once registry() has run.
     *
     * @param string $sub Sub path, such as 'config' or 'template'
     * @return string
     */
    public static function app_path($sub = '')
    {
        if ( self::$_app_path === '' )
        {
            return '';
        }

        return $sub === '' ? self::$_app_path : self::$_app_path . DIRECTORY_SEPARATOR . ltrim($sub, DIRECTORY_SEPARATOR);
    }

    /**
     * Writable runtime directory, defaults to app_path/data.
     *
     * @param string $sub
     * @return string
     */
    public static function data_path($sub = '')
    {
        if ( self::$_data_path === '' )
        {
            self::$_data_path = self::app_path('data');
        }

        if ( self::$_data_path === '' )
        {
            return '';
        }

        return $sub === '' ? self::$_data_path : self::$_data_path . DIRECTORY_SEPARATOR . ltrim($sub, DIRECTORY_SEPARATOR);
    }

    /**
     * Log directory.
     *
     * @param string $sub
     * @return string
     */
    public static function log_path($sub = '')
    {
        return self::data_path($sub === '' ? 'log' : 'log' . DIRECTORY_SEPARATOR . ltrim($sub, DIRECTORY_SEPARATOR));
    }

    /**
     * Cache directory.
     *
     * @param string $sub
     * @return string
     */
    public static function cache_path($sub = '')
    {
        return self::data_path($sub === '' ? 'cache' : 'cache' . DIRECTORY_SEPARATOR . ltrim($sub, DIRECTORY_SEPARATOR));
    }

    /**
     * The .env file currently in use.
     *
     * @return string
     */
    public static function env_path()
    {
        return self::$_env_path;
    }

    /**
     * Framework package root, used to locate the bundled config/ and lang/.
     *
     * @param string $sub
     * @return string
     */
    public static function framework_path($sub = '')
    {
        $base = dirname(__DIR__);
        return $sub === '' ? $base : $base . DIRECTORY_SEPARATOR . ltrim($sub, DIRECTORY_SEPARATOR);
    }

    /**
     * Load the .env file into $_ENV, missing or unreadable files are ignored.
     *
     * @return void
     */
    private static function _load_env()
    {
        if ( self::$_env_path === '' || !file_exists(self::$_env_path) )
        {
            return;
        }

        $envs = @parse_ini_file(self::$_env_path);
        if ( !$envs )
        {
            return;
        }

        foreach ( $envs as $k => $v )
        {
            if ( strpos($k, "# ") === false ) // skip commented out lines
            {
                $_ENV[$k] = $v;
            }
        }
    }

    /**
     * Core initialization
     */
    private static function _bootstrap()
    {
        $timezone_set = config::instance('config')->get('timezone_set');
        date_default_timezone_set($timezone_set);

        // Explicit boot order for process and request side effects. Everything else in the
        // framework reads its configuration on first use and needs no entry here.
        //
        //   cli           parses argv, which the router and req::_hydrate() both read
        //   log           checks the log directory and registers the buffer flush
        //   req           reads the request; the two below take their answers off it
        //   error_handler  decides whether this client may see debug output
        //   security      issues the CSRF hash for this request
        if ( PHP_SAPI === 'cli' )
        {
            cli::boot();
        }

        log::boot();

        register_shutdown_function([error_handler::class, 'shutdown_handler']);
        set_error_handler([error_handler::class, 'error_handler'], E_ALL);
        set_exception_handler([error_handler::class, 'exception_handler']);

        req::capture();
        error_handler::capture();
        security::capture();

        event::start();

        if ( PHP_SAPI != 'cli' )
        {
            // Client IP, language, and country are read from req::.
            event::trigger(event::ON_FILTER);
        }

        // Start the stopwatch... tick tock...
        benchmark::mark('total_execution_start');
        benchmark::mark('loading_time:_base_classes_start');
    }

    /**
     * Route the request to control\ctl_{ct}::{ac}().
     *
     * @param array|null $req_data Request payload to route, for CLI / resident server entry points
     * @return bool|void False when a CLI request could not be dispatched
     * @throws Throwable Under CLI only, where the caller -- bin/plato, a queue worker -- owns the
     *                   failure. A web request is answered by error_handler::exception_reply()
     */
    public static function run(?array $req_data = null)
    {
        try
        {
            $result = self::handle($req_data);
        }
        catch ( Throwable $e )
        {
            if ( PHP_SAPI === 'cli' )
            {
                throw $e;
            }

            $result = error_handler::exception_reply($e);
        }

        if ( $result instanceof reply )
        {
            resp::send($result);
        }

        return $result;
    }

    /**
     * Clear request-scoped state before a resident worker accepts the next request.
     *
     * The caller populates req and restores any transport identity after this method returns.
     */
    public static function reset_request(): void
    {
        self::restamp();

        req::reset_input();
        upload::reset();
        route::reset();
        envelope::reset();
        resp::reset();
        tpl::reset();
        profiler::reset();
        error_handler::reset();
        cache::free_mem();
        db::flush_log();

        benchmark::$marker = [];
        self::$auth = null;
        self::$ct   = '';
        self::$ac   = '';

        $reset = self::config('reset_handle');
        if ( $reset !== null && is_callable($reset) )
        {
            call_user_func($reset);
        }
    }

    /**
     * Dispatch a request and return its result without emitting it.
     *
     * @param array<string, mixed>|null $req_data
     * @return mixed
     */
    public static function handle(?array $req_data = null)
    {
        $is_cli = PHP_SAPI === 'cli';

        try
        {
            $route = self::_resolve_route($req_data, $is_cli);
        }
        catch ( route_exception $e )
        {
            return self::_route_error($is_cli, $e);
        }

        $ct = self::$ct = $route['ct'];
        $ac = self::$ac = $route['ac'];

        event::trigger(event::ON_REQUEST);

        // A non canonical url -- a trailing slash -- is sent to the canonical one instead of
        // being served under both. The target comes out of the router, built from the validated
        // segments, never echoed back from the request.
        if ( !$is_cli
            && route::redirect() !== ''
            && in_array($route['method'], ['GET', 'HEAD'], true)
            && route::config('canonical_redirect') )
        {
            $query = (string) req::server('QUERY_STRING', '');
            return resp::redirect(
                route::redirect() . ($query === '' ? '' : '?' . $query),
                301
            );
        }

        $ctl        = 'ctl_' . $ct;
        $controller = self::controller_class($ct);
        $preflight  = !$is_cli ? security::preflight_method() : null;

        if ( !class_exists($controller) )
        {
            return self::_dispatch_error($is_cli, [$ctl], 2001);
        }

        // Which actions exist, and which methods each accepts. This replaces method_exists(),
        // which answered true for inherited, static, protected and private methods alike.
        try
        {
            // A browser asks whether its intended method may reach this action; binding the wire
            // method OPTIONS would reject every normal POST/PUT/DELETE declaration before the
            // pipeline could answer. An ordinary OPTIONS request leaves $preflight null and keeps
            // the normal method binding.
            $action = route::check_action(
                $controller,
                $ac,
                $preflight !== null ? $preflight : $route['method']
            );
        }
        catch ( route_exception $e )
        {
            return self::_route_error($is_cli, $e);
        }

        // A valid preflight belongs to the resolved route and runs through its middleware, but the
        // destination is an empty 204 rather than CSRF, authentication or the controller action.
        // That also keeps an encrypted route usable from a browser: OPTIONS carries no envelope.
        if ( $preflight !== null )
        {
            $result = pipeline::run(
                pipeline::for_route($ct, $ac),
                static function () use ($preflight): reply
                {
                    return self::_preflight_reply($preflight);
                }
            );

            return $result instanceof reply ? $result : (resp::prepared() ?? $result);
        }

        // Routes that are configured to require an encrypted envelope. Asked now rather than in
        // req::_hydrate(), because the answer depends on the route and the route is now known.
        if ( route::crypto_required() && !envelope::is_active() )
        {
            return self::_route_error($is_cli, new route_exception([$ct . ':' . $ac], 2007));
        }

        // Everything from here on runs inside the middleware the route is configured for, so that a
        // middleware can refuse an over-limit request before the csrf check and the authentication
        // callback, and still see plato::$auth when it lets one through. Nothing above is inside it:
        // a request that did not resolve has no ct:ac to match against.

        // Read once, here, and handed down. The router keeps the metadata of the admitted action
        // in static state so that middleware can ask about it, but a security decision must not
        // depend on that state still being what check_action() left: it travels as an argument.
        $auth = route::auth_mode();

        $result = pipeline::run(
            pipeline::for_route($ct, $ac),
            function () use ($controller, $action, $ct, $ac, $is_cli, $auth)
            {
                return self::_kernel($controller, $action, $ct, $ac, $is_cli, $auth);
            }
        );

        return $result instanceof reply ? $result : (resp::prepared() ?? $result);
    }

    /**
     * Verify, authorise, and run the action.
     *
     * The middleware pipeline wraps this sequence: CSRF, authorisation, then the action.
     *
     * There is no before / after action event any more. The middleware a route is configured for
     * wraps exactly this call, so it can do what those two hooks did and more -- it sees the
     * return value, it can answer without ever reaching the action, and it is scoped to a route
     * instead of firing on every request.
     *
     * @param string $controller Fully qualified controller class
     * @param string $action     Action name, as the router validated it
     * @param string $ct
     * @param string $ac
     * @param bool   $is_cli
     * @param string $auth       Authentication mode of the action, a route::AUTH_* value
     * @return mixed
     * @throws auth_exception When the authentication integration itself is wrong -- not when the
     *                        visitor is signed out, which answers 401
     */
    private static function _kernel($controller, $action, $ct, $ac, $is_cli, $auth)
    {
        // CSRF token. There is no same origin notion under CLI / a resident server so it is skipped by
        // default; set cli_csrf = true to opt back in.
        if ( !$is_cli || self::config('cli_csrf') )
        {
            $csrf = security::csrf_verify();

            if ( $csrf instanceof reply )
            {
                return $csrf;
            }
        }

        // Authentication. The action's declaration decides whether the callback runs at all: 'none'
        // skips it, 'optional' lets it answer "nobody", 'required' insists on an identity. CLI / a
        // resident server (Workerman, Swoole) skips it by default whatever the action declared,
        // meaning those two entry points authenticate on their own; set cli_auth = true to opt
        // back in. It runs after csrf so that a forged request cannot reach an authorisation
        // callback that writes login logs or refreshes tokens.
        if ( $auth !== route::AUTH_NONE && ( !$is_cli || self::config('cli_auth') ) )
        {
            $name    = $ct . ':' . $ac;
            $purview = self::config('check_purview_handle');

            // No callback at all is a configuration mistake rather than a signed out visitor:
            // nothing was asked, so nothing can be concluded about who is here.
            if ( $purview === null && $auth === route::AUTH_REQUIRED )
            {
                throw new auth_exception([$name], 2013);
            }

            if ( $purview !== null )
            {
                $identity = call_user_func_array($purview, [$ct, $ac]);

                // The application refusing the request itself -- a 401 of its own shape, a
                // redirect to a login page. Its answer stands for both modes, an optional route
                // may still say no, and it is the hook for anything other than the 401 below.
                if ( $identity instanceof reply )
                {
                    return $identity;
                }

                if ( $identity !== null && !is_object($identity) )
                {
                    throw new auth_exception([$name, gettype($identity)], 2014);
                }

                // Nobody is signed in and this action insists on someone. That is the visitor's
                // state, not a programming error, so it answers 401 the way a failed csrf check
                // answers 403 -- an application that wants a different page returns its own reply
                // from the callback instead of null.
                if ( $identity === null && $auth === route::AUTH_REQUIRED )
                {
                    log::info($name . ' - ' . req::ip(), 'Unauthenticated');

                    return msgbox::error(401);
                }

                // Assigned only once it is known to be an identity, so that a callback that broke
                // the contract cannot leave a value behind for whoever handles the failure.
                self::$auth = $identity;
            }
        }

        benchmark::mark('controller_execution_( ' . $ct . ' / ' . $ac . ' )_start');

        $instance = new $controller();

        try
        {
            // Dispatch to the name the router returned, not to a local copy of it, so nothing that
            // was not validated can be invoked.
            return $instance->$action();
        }
        finally
        {
            benchmark::mark('controller_execution_( ' . $ct . ' / ' . $ac . ' )_end');
        }
    }

    /**
     * Empty browser preflight response for a method check that already passed.
     *
     * A request headers list the configuration does not approve leaves the header off entirely.
     * The status stays 204: a preflight is a question, and "these headers are what I allow" is the
     * complete answer to it. The browser is the one that then refuses to send the real request,
     * which is where that refusal belongs and is also the only place it can be reported usefully.
     */
    private static function _preflight_reply(string $method): reply
    {
        $headers = [
            'Access-Control-Allow-Methods' => $method,
            'Vary' => 'Origin, Access-Control-Request-Method, Access-Control-Request-Headers',
        ];

        $approved = security::preflight_headers();
        if ( (string) $approved !== '' )
        {
            $headers['Access-Control-Allow-Headers'] = $approved;
        }

        $max_age = security::preflight_max_age();
        if ( $max_age > 0 )
        {
            $headers['Access-Control-Max-Age'] = (string) $max_age;
        }

        return new reply(204, $headers, '');
    }

    /**
     * Work out the route for this request.
     *
     * Three entry points, one validation path:
     *
     *   web           route::resolve() reads the request path, and falls back to the encrypted
     *                 envelope when the path carries no route
     *   CLI           ct / ac come from the command line arguments
     *   server        ct / ac come from the payload handed to run()
     *
     * The ?ct=&ac= query form is gone. Routing no longer reads $_GET, $_POST or php://input, so
     * a request body cannot disagree with the path about where the request is going -- which is
     * what made the csrf exclusion list and the encryption requirement bypassable.
     *
     * @param array<string, mixed>|null $req_data
     * @param bool $is_cli
     * @return array{ct: string, ac: string, method: string, segments: array<int, string>, source: string}
     * @throws route_exception
     */
    private static function _resolve_route(?array $req_data, $is_cli)
    {
        // Resident server entry point: Workerman, Swoole. Drop the previous request first so its
        // data cannot leak into this one.
        if ( $req_data )
        {
            if ( $is_cli )
            {
                req::reset_input();
                envelope::reset();
            }

            req::assign_values($req_data);

            return route::assign(
                $req_data['ct'] ?? route::config('default_ct'),
                $req_data['ac'] ?? route::config('default_ac'),
                $is_cli ? 'CLI' : req::method()
            );
        }

        if ( $is_cli )
        {
            return route::assign(
                cli::$args['ct'] ?? route::config('default_ct'),
                cli::$args['ac'] ?? route::config('default_ac'),
                'CLI'
            );
        }

        envelope::register();

        return route::resolve();
    }

    /**
     * Report a routing failure with the status the router asked for.
     *
     * Everything unroutable answers 404 and nothing else: "this controller does not exist",
     * "this method is protected" and "this path is malformed" have to be indistinguishable from
     * outside, or they become a way to enumerate what is there.
     *
     * @param bool            $is_cli
     * @param route_exception $e
     * @return false
     * @throws route_exception
     */
    private static function _route_error($is_cli, route_exception $e)
    {
        if ( !$is_cli )
        {
            $params = $e->params();
            if ( $e->status() === 405 && isset($params[2]) && (string) $params[2] !== '' )
            {
                resp::header('Allow', (string) $params[2]);
            }

            // Set the status before raising, so it is right whatever the error handler renders
            if ( !headers_sent() )
            {
                http_response_code($e->status());
            }

            throw $e;
        }

        log::warning($e->getMessage());
        return false;
    }

    /**
     * Report a dispatch failure: web requests raise, CLI requests only log and bail out.
     *
     * @param bool  $is_cli
     * @param array $params Message template arguments
     * @param int   $code   Key of config/exception.php
     * @return false
     * @throws controller_exception
     */
    private static function _dispatch_error($is_cli, array $params, $code)
    {
        $e = new controller_exception($params, $code);

        if ( !$is_cli )
        {
            throw $e;
        }

        log::warning($e->getMessage());
        return false;
    }

    /**
     * Render an error code into its message, see config/exception.php.
     *
     * @param int              $code
     * @param array|string|int $params Template arguments, a bare scalar is accepted too
     * @return string
     */
    public static function fmt_code($code, $params = [])
    {
        $params = is_array($params) ? array_values($params) : [$params];

        try
        {
            $msgtpl = config::instance('exception')->get($code);
        }
        catch ( \Throwable $e )
        {
            $msgtpl = '';
        }

        if ( !is_string($msgtpl) || $msgtpl === '' )
        {
            return trim($code . ': ' . implode(', ', array_map('strval', $params)), ': ');
        }

        try
        {
            return vsprintf($msgtpl, $params);
        }
        catch ( \Throwable $e )
        {
            // Not enough arguments for the template, show it raw rather than fail inside a
            // failure path
            return $msgtpl;
        }
    }

    /**
     * Elapsed time and peak memory growth since the current request stamp.
     *
     * The memory value is floored at zero because another library may reset the process peak below
     * the baseline captured here.
     *
     * @return array{0: float, 1: int}
     */
    public static function app_total()
    {
        return array(
            microtime(true) - self::start_time(),
            max(0, memory_get_peak_usage() - self::start_mem()),
        );
    }
}
