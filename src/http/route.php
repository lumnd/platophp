<?php

/**
 * Request routing: resolve the request path into a controller / action pair, and build urls back
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\http;

use plato\config;
use plato\exception\route_exception;
use ReflectionClass;
use ReflectionMethod;

/**
 * Router.
 *
 * The route is resolved once per request and is then the single source of truth for the
 * controller / action pair. Nothing downstream may re-read request data to work out where a
 * request is going: every judgement made about a request -- csrf exclusion, encryption
 * requirement, authorisation, logging -- has to be made against the resolved route and never
 * against the raw query string or the request body. When those two can disagree, whatever
 * checks the request is not what dispatches it.
 *
 * Three route sources exist. The first one that claims a request wins, and there is no fall
 * through afterwards, because a fall through is just another way of having two channels:
 *
 *   path    the normalised request path, /{ct}/{ac}[/{segment}...]
 *   crypto  an encrypted request envelope, only at the configured crypto entry path
 *   manual  assigned by a CLI or server entry point through assign()
 *
 * Routing never reads $_GET, $_POST or php://input, so a request body cannot disagree with the
 * path about where the request is headed.
 *
 * Path handling rules worth knowing before changing anything here:
 *
 *   - the path comes from REQUEST_URI, not PATH_INFO: under `try_files $uri /index.php` nginx
 *     leaves PATH_INFO empty unless fastcgi_split_path_info is configured
 *   - X-Original-URL / X-Rewrite-URL are never read, they are client supplied and are a
 *     well known authorisation bypass
 *   - segments are split on / first and percent decoded afterwards, once. Decoding first would
 *     let %2F inject a segment separator
 *   - anything that does not match is rejected, never repaired. Silently stripping characters
 *     makes many different inputs collapse onto one route, so anything upstream that keys on
 *     the raw string -- acl, rate limit, log, waf -- ends up disagreeing with the router
 */
class route
{
    /** Route sources */
    public const SOURCE_PATH   = 'path';
    public const SOURCE_CRYPTO = 'crypto';
    public const SOURCE_MANUAL = 'manual';

    /**
     * How an action authenticates, as declared by its $actions entry.
     *
     * NONE     the authentication callback is not called at all, plato::$auth stays null
     * OPTIONAL the callback runs and may answer "nobody"; the action runs either way. This is what
     *          a page that is public but greets a signed in visitor needs
     * REQUIRED the callback must produce an identity or answer with a reply of its own; finding
     *          nobody answers 401 and the action does not run
     */
    public const AUTH_NONE     = 'none';
    public const AUTH_OPTIONAL = 'optional';
    public const AUTH_REQUIRED = 'required';

    /**
     * Accepted values of the auth key, in order of increasing trust.
     *
     * @var array<int, string>
     */
    protected static $_auth_modes = [self::AUTH_NONE, self::AUTH_OPTIONAL, self::AUTH_REQUIRED];

    /**
     * The router's marker for an entry point that has no http method: a CLI command, a resident
     * server. It is not an http method and is deliberately absent from $_known_methods, so that a
     * request cannot claim it -- check_action() skips method binding for it, and csrf_verify()
     * counts it among the safe methods, both of which a client would otherwise get for free by
     * sending `REQUEST_METHOD: CLI`. Only a process that is genuinely not serving http may name it.
     */
    public const METHOD_CLI = 'CLI';

    /**
     * Methods an action declaration may name. Other request methods still resolve far enough to
     * produce the resource-specific 405 response and its Allow header, but never reach the action.
     *
     * @var array<int, string>
     */
    protected static $_known_methods = [
        'GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS',
    ];

    /**
     * Configuration, from the route section of config/config.php.
     *
     * List valued settings are deliberately absent from config/config.php and default here
     * instead: application configuration is merged into the framework configuration rather than
     * replacing it, so a list shipped as a framework default cannot be shortened by an
     * application -- ['GET', 'POST'] overridden with ['POST'] merges back to ['POST', 'POST'].
     * Keeping lists out of the config file means the application value replaces this one whole.
     *
     * @var array<string, mixed>
     */
    protected static $_defaults = [
        'default_ct'      => 'index',
        'default_ac'      => 'index',
        // Path prefix to strip when the application is not served from the document root,
        // '/blog' for example. Explicit on purpose: deriving it from SCRIPT_NAME makes the
        // router disagree with the web server in exactly the setups that are hard to debug.
        'base_path'       => '',
        // Optional url suffix, '.html' for example, stripped before parsing and appended by url()
        'path_suffix'     => '',
        // Path the encrypted envelope is accepted at, '' disables the crypto source entirely
        'crypto_entry'    => '/',
        // true refuses any action that is not listed in the controller's $actions
        'strict_actions'  => false,
        // Honour X-HTTP-Method-Override. Off by default and even when on it only ever promotes
        // POST to PUT / PATCH / DELETE: allowing an override to GET or HEAD would let a client
        // turn a state changing request into one that skips the csrf check.
        'method_override' => false,
        'max_path_length' => 255,
        'max_segments'    => 8,
        // Send a non canonical url -- a trailing slash -- to the canonical one with a 301
        'canonical_redirect' => true,
        // Methods an action accepts when the controller does not declare $actions
        'default_methods' => ['GET', 'POST'],
        // Routes, as 'ct:ac' or 'ct:*', that only accept an encrypted envelope. This states a
        // wire format requirement, not an authorisation rule: client side keys are extractable,
        // so a valid envelope proves nothing about who sent it.
        'crypto_required' => [],
    ];

    /**
     * Resolved configuration, null until config() reads it.
     *
     * @var array<string, mixed>|null
     */
    protected static $_config = null;

    /**
     * Resolver for the crypto source, registered by whoever owns the envelope format.
     *
     * It is handed the raw request body and returns null when the request is not a valid
     * envelope, or ['ct' => ..., 'ac' => ..., 'method' => ...] when it is. The resolver is
     * responsible for having made the envelope payload available as request parameters; the
     * router only takes the routing fields, and validates them exactly as it validates a path.
     *
     * @var callable|null
     */
    protected static $_crypto_resolver = null;

    /** Resolved route */
    protected static $_ct       = '';
    protected static $_ac       = '';
    protected static $_method   = '';
    protected static $_source   = '';
    protected static $_resolved = false;

    /**
     * Trailing segments after ct / ac, /article/view/10 gives ['10'].
     *
     * @var array<int, string>
     */
    protected static $_segments = [];

    /**
     * Canonical path the request should be redirected to, '' when the request is already
     * canonical. Only ever built from validated segments, never echoed back from the input.
     *
     * @var string
     */
    protected static $_redirect = '';

    /**
     * Metadata of the action check_action() admitted for the current request.
     *
     * Null until an action has been admitted, which auth_mode() reads as AUTH_REQUIRED: a caller
     * that asks before the router has ruled must not be told that no authentication is needed.
     *
     * @var array{methods: mixed, auth: string}|null
     */
    protected static $_action = null;

    /**
     * Merge settings into the router configuration.
     *
     * Meant for tests and for long running entry points that need a different policy than the
     * config file; applications should use config/config.php.
     *
     * @param array<string, mixed> $config
     * @return void
     */
    public static function configure(array $config)
    {
        self::$_config = $config + (array) self::config();
    }

    /**
     * Router configuration.
     *
     * @param string|null $key
     * @return mixed
     */
    public static function config(?string $key = null)
    {
        if ( self::$_config === null )
        {
            $cfg = config::instance('config')->get('route');
            self::$_config = (is_array($cfg) ? $cfg : []) + self::$_defaults;
        }

        return $key === null ? self::$_config : (self::$_config[$key] ?? null);
    }

    /**
     * Register the resolver for the crypto source.
     *
     * @param callable $resolver
     * @return void
     */
    public static function register_crypto_resolver(callable $resolver)
    {
        self::$_crypto_resolver = $resolver;
    }

    /**
     * Forget the resolved route.
     *
     * Long running entry points -- Workerman, Swoole -- reuse one process for many requests and
     * have to call this between them, otherwise the previous route leaks into the next request.
     *
     * @param bool $config Also drop the cached configuration
     * @return void
     */
    public static function reset($config = false)
    {
        self::$_ct       = '';
        self::$_ac       = '';
        self::$_method   = '';
        self::$_source   = '';
        self::$_segments = [];
        self::$_redirect = '';
        self::$_action   = null;
        self::$_resolved = false;

        if ( $config )
        {
            self::$_config = null;
        }
    }

    public static function ct()
    {
        return self::$_ct;
    }

    public static function ac()
    {
        return self::$_ac;
    }

    /**
     * Request method, as the router determined it.
     *
     * This is authoritative: unlike req::method() it does not trust X-HTTP-Method-Override
     * unless the application turned it on, and it refuses methods outside the known set.
     *
     * @return string
     */
    public static function method()
    {
        return self::$_method;
    }

    /**
     * @return array<int, string>
     */
    public static function segments()
    {
        return self::$_segments;
    }

    /**
     * Which source the route came from, one of the SOURCE_* constants.
     *
     * @return string
     */
    public static function source()
    {
        return self::$_source;
    }

    public static function is_resolved()
    {
        return self::$_resolved;
    }

    /**
     * Canonical path this request should be redirected to, '' when none is needed.
     *
     * @return string
     */
    public static function redirect()
    {
        return self::$_redirect;
    }

    /**
     * Resolved route as 'ct:ac', the form used by the csrf exclusion and crypto_required lists.
     *
     * @return string
     */
    public static function name()
    {
        return self::$_ct . ':' . self::$_ac;
    }

    /**
     * Metadata of the action that passed check_action().
     *
     * @return array{methods: mixed, auth: string}|null
     */
    public static function action()
    {
        return self::$_action;
    }

    /**
     * How the admitted action authenticates: AUTH_NONE, AUTH_OPTIONAL or AUTH_REQUIRED.
     *
     * Answers AUTH_REQUIRED when no action has been admitted yet. The safe answer to "does this
     * need authentication?" before anything has been decided is yes.
     *
     * @return string
     */
    public static function auth_mode()
    {
        $mode = self::$_action['auth'] ?? null;

        return in_array($mode, self::$_auth_modes, true) ? $mode : self::AUTH_REQUIRED;
    }

    /** Whether the admitted action insists on an authenticated identity. */
    public static function requires_auth()
    {
        return self::auth_mode() === self::AUTH_REQUIRED;
    }

    /**
     * Whether the resolved route matches one of the given 'ct:ac' / 'ct:*' patterns.
     *
     * @param array<int, string> $patterns
     * @return bool
     */
    public static function matches(array $patterns)
    {
        foreach ( $patterns as $pattern )
        {
            $pattern = strtolower(trim((string) $pattern));

            if ( $pattern === self::$_ct . ':' . self::$_ac || $pattern === self::$_ct . ':*' )
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the resolved route may only be reached with an encrypted envelope.
     *
     * Safe to ask now, which it was not before: the route is known before the question is put,
     * so the answer no longer has to be guessed from the query string.
     *
     * @return bool
     */
    public static function crypto_required()
    {
        return self::matches((array) self::config('crypto_required'));
    }

    /**
     * Resolve the request into a route.
     *
     * @param string|null $uri    Request path to parse, defaults to the current request
     * @param string|null $method Request method, defaults to the one the request arrived with
     * @return array{ct: string, ac: string, method: string, segments: array<int, string>, source: string}
     * @throws route_exception When the request cannot be routed
     */
    public static function resolve($uri = null, $method = null)
    {
        self::reset();

        $method = $method === null ? self::_method() : strtoupper((string) $method);
        $parsed = self::_parse($uri === null ? self::_request_uri() : (string) $uri);

        if ( $parsed === false )
        {
            throw new route_exception([self::_safe_uri()], 2007);
        }

        // The crypto source only claims the request when the path carries no route of its own,
        // so the two sources can never both describe the same request.
        if ( self::_is_crypto_entry($parsed) )
        {
            $envelope = self::_resolve_crypto();

            if ( $envelope !== null )
            {
                return self::_accept(
                    $envelope['ct'] ?? '',
                    $envelope['ac'] ?? '',
                    $envelope['method'] ?? $method,
                    [],
                    self::SOURCE_CRYPTO
                );
            }

            // A dedicated crypto entry has no path route to fall back to, so a request that is
            // not a valid envelope ends here rather than being dispatched to ctl_{entry}. The
            // document root is the exception: it still serves the default route.
            if ( trim((string) self::config('crypto_entry'), '/') !== '' )
            {
                throw new route_exception([self::_safe_uri()], 2007);
            }
        }

        $segments = $parsed['segments'];
        $ct       = array_shift($segments);
        $ac       = array_shift($segments);

        self::$_redirect = $parsed['redirect'];

        return self::_accept(
            $ct === null ? (string) self::config('default_ct') : $ct,
            $ac === null ? (string) self::config('default_ac') : $ac,
            $method,
            $segments,
            self::SOURCE_PATH
        );
    }

    /**
     * Assign a route directly, for CLI and server entry points that carry no request path.
     *
     * The values go through the same validation as a path derived route: an entry point that
     * builds its own route does not get to skip the rules.
     *
     * @param string $ct
     * @param string $ac
     * @param string $method
     * @param array<int, string> $segments
     * @return array{ct: string, ac: string, method: string, segments: array<int, string>, source: string}
     * @throws route_exception
     */
    public static function assign($ct, $ac = null, $method = 'CLI', array $segments = [])
    {
        self::reset();

        return self::_accept(
            (string) $ct,
            $ac === null ? (string) self::config('default_ac') : (string) $ac,
            (string) $method,
            $segments,
            self::SOURCE_MANUAL
        );
    }

    /**
     * Validate a resolved triple and store it.
     *
     * Every source funnels through here, which is the point: there is one validation path, so
     * two sources cannot drift apart.
     *
     * @param mixed  $ct
     * @param mixed  $ac
     * @param mixed  $method
     * @param array<int, string> $segments
     * @param string $source
     * @return array{ct: string, ac: string, method: string, segments: array<int, string>, source: string}
     * @throws route_exception
     */
    protected static function _accept($ct, $ac, $method, array $segments, $source)
    {
        // Guard the types explicitly before the dispatcher receives the route.
        if ( !is_string($ct) || !is_string($ac) || !self::valid_name($ct) || !self::valid_name($ac) )
        {
            throw new route_exception([self::_safe_uri()], 2007);
        }

        foreach ( $segments as $segment )
        {
            if ( !self::valid_segment($segment) )
            {
                throw new route_exception([self::_safe_uri()], 2007);
            }
        }

        $method = is_string($method) ? strtoupper($method) : '';

        // An envelope method is decoded structured input, so hold it to the declared vocabulary at
        // that boundary. It is malformed routing data, not a wire method being tried against a
        // resource, and therefore reports as an invalid route rather than as a 405 without the
        // resource-specific Allow header. Path methods continue to check_action() below.
        if ( $source === self::SOURCE_CRYPTO && !in_array($method, self::$_known_methods, true) )
        {
            throw new route_exception([self::_safe_uri()], 2007);
        }

        self::$_ct       = $ct;
        self::$_ac       = $ac;
        self::$_method   = $method;
        self::$_segments = array_values($segments);
        self::$_source   = $source;
        self::$_resolved = true;

        return [
            'ct'       => $ct,
            'ac'       => $ac,
            'method'   => $method,
            'segments' => self::$_segments,
            'source'   => $source,
        ];
    }

    /**
     * Whether a string is a valid controller or action name.
     *
     * Lower case only, and a leading underscore is refused. Class and method names are case
     * insensitive in PHP, so allowing upper case would give one action an unbounded number of
     * spellings and any application check written as in_array($ac, $need_login) could be walked
     * straight past.
     *
     * Takes mixed rather than string on purpose: routing input has arrived here as an array
     * before, and the type check belongs with the value check rather than one layer up.
     *
     * @param mixed $name
     * @return bool
     */
    public static function valid_name($name)
    {
        return is_string($name) && preg_match('/^[a-z0-9][a-z0-9_]{0,31}$/', $name) === 1;
    }

    /**
     * Whether a value is acceptable as a trailing path segment.
     *
     * @param mixed $segment
     * @return bool
     */
    public static function valid_segment($segment)
    {
        return is_string($segment) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $segment) === 1;
    }

    /**
     * Verify that an action may be reached, and with this method.
     *
     * A controller opts its actions in by declaring them, which also states the methods each one
     * accepts and how it authenticates:
     *
     *     public static $actions = [
     *         'index'   => ['methods' => ['GET'],  'auth' => 'none'],
     *         'profile' => ['methods' => ['GET'],  'auth' => 'optional'],
     *         'save'    => ['methods' => ['POST'], 'auth' => 'required'],
     *     ];
     *
     * The structured form requires and validates both keys. The previous method-only forms remain
     * accepted for compatibility:
     *
     *     'index' => ['GET'],
     *     'index' => 'GET',
     *
     * They normalize to auth = optional, which preserves the old global callback behavior: call it
     * when configured, but do not require an identity or even a callback. New code should use the
     * structured form so its authentication policy is explicit. See the AUTH_* constants for what
     * each mode does.
     *
     * Without that declaration the fallback is reflection: public, non static, declared by the
     * controller itself. method_exists() cannot be used for this -- it answers true for
     * inherited, static, protected and private methods alike, which turns every public helper
     * on a shared base controller into a reachable action, and makes the difference between a
     * protected method and a missing one observable from outside. An action reached that way
     * authenticates: an undeclared action has said nothing about who may reach it.
     *
     * Returns the method name to call. Callers must dispatch to the returned name rather than
     * to their own copy of it, so that nothing unvalidated can be invoked. The metadata that came
     * with it is available from action() / auth_mode() until the next request resets it.
     *
     * That last part makes this a writer, not just a validator: admitting an action replaces what
     * action() / auth_mode() answer. Tooling that wants to know what a controller declares without
     * disturbing the request in flight has to read actions() instead.
     *
     * @param string $controller Fully qualified controller class, must already be loaded
     * @param string $ac
     * @param string $method
     * @return string
     * @throws route_exception
     */
    public static function check_action($controller, $ac, $method)
    {
        if ( !self::valid_name($ac) || !class_exists($controller) )
        {
            throw new route_exception([(string) $ac, (string) $controller], 2009);
        }

        $declared = self::_declared_actions($controller);

        if ( $declared === false )
        {
            throw new route_exception([$controller], 2012);
        }

        if ( $declared !== null )
        {
            // Keys are compared with === so that a declaration for 'index' cannot be reached as
            // 'INDEX' by way of PHP's case insensitive method lookup.
            $declaration = null;
            $found       = false;
            foreach ( $declared as $name => $entry )
            {
                if ( (string) $name === $ac )
                {
                    $declaration = $entry;
                    $found       = true;
                    break;
                }
            }

            if ( !$found )
            {
                throw new route_exception([$ac, $controller], 2009);
            }

            if ( !self::_is_routable_method($controller, $ac, false) )
            {
                throw new route_exception([$ac, $controller], 2009);
            }

            $metadata = self::_action_metadata($declaration);

            if ( $metadata === null )
            {
                throw new route_exception([$ac, $controller], 2011);
            }

            $allowed = $metadata['methods'];
        }
        elseif ( self::config('strict_actions') )
        {
            throw new route_exception([$ac, $controller], 2009);
        }
        else
        {
            if ( !self::_is_routable_method($controller, $ac) )
            {
                throw new route_exception([$ac, $controller], 2009);
            }

            $allowed  = self::config('default_methods');
            $metadata = [
                'methods' => $allowed,
                'auth'    => self::AUTH_REQUIRED,
            ];
        }

        // Method binding is an http notion and a CLI or server entry point has no http method,
        // so it is not applied there. This keeps those entry points behaving as they did -- they
        // already default to skipping authorisation and csrf, see cli_auth / cli_csrf -- while
        // still holding them to the action whitelist above. Declaring an action for GET does not
        // make it unreachable from the command line. Both the SAPI and the route source decide
        // that the marker is genuine: a path, envelope or preflight that names CLI falls through
        // to method binding even when a test or worker happens to run under the CLI SAPI.
        if ( $method === self::METHOD_CLI
            && PHP_SAPI === 'cli'
            && self::source() === self::SOURCE_MANUAL )
        {
            self::$_action = $metadata;
            return $ac;
        }

        if ( !self::method_allowed($method, $allowed) )
        {
            throw new route_exception([
                $method,
                $controller . '::' . $ac,
                self::_allow_header($allowed),
            ], 2008);
        }

        self::$_action = $metadata;
        return $ac;
    }

    /**
     * Whether a value is usable as an action's method declaration.
     *
     * The mirror of method_allowed(): everything it accepts is valid here, and nothing else is.
     * Without this a declaration of [] or null passes for "no method matches" and the action
     * answers 405 to every request, which reads as a routing bug rather than as the typo it is.
     *
     * @param mixed $methods '*', true, a method name, a comma separated list, or an array
     * @return bool
     */
    public static function valid_methods($methods)
    {
        return self::_method_list($methods) !== null;
    }

    /**
     * The http methods an action declaration may name, for whoever has to answer a client about
     * them. METHOD_CLI is not among them: it is the router's own marker, not an http method.
     *
     * @return array<int, string>
     */
    public static function http_methods()
    {
        return self::$_known_methods;
    }

    /**
     * Whether a method is covered by an allowed method declaration.
     *
     * GET implies HEAD: a HEAD request is a GET whose body is discarded, and refusing it would
     * break crawlers and uptime checks for no gain.
     *
     * A method http_methods() does not list is never covered, whatever the declaration says --
     * including by '*'. A wildcard means "every method this router knows", not "every string a
     * client can put on the request line".
     *
     * @param string $method
     * @param mixed  $allowed '*', a method name, a comma separated list, or an array
     * @return bool
     */
    public static function method_allowed($method, $allowed)
    {
        $method = strtoupper((string) $method);
        $list   = self::_method_list($allowed);

        if ( $list === null || !in_array($method, self::$_known_methods, true) )
        {
            return false;
        }

        if ( in_array('*', $list, true) )
        {
            return true;
        }

        if ( $method === 'HEAD' && in_array('GET', $list, true) )
        {
            return true;
        }

        return in_array($method, $list, true);
    }

    /**
     * Read a controller's public static $actions declaration.
     *
     * This does not discover controller files or apply strict_actions: the application owns its
     * controller catalogue, while the framework owns the declaration format. Null means the class
     * is unavailable or has no usable declaration.
     *
     * The declaration is returned as written, without the per entry validation check_action()
     * applies: this answers "what does this controller say about itself", and a tool that builds
     * a permission list from it should expect the ['methods' => ..., 'auth' => ...] shape but not
     * assume every entry is well formed.
     *
     * @param string $controller Fully qualified controller class
     * @return array<string, mixed>|null
     */
    public static function actions($controller)
    {
        if ( !class_exists($controller) )
        {
            return null;
        }

        $declared = self::_declared_actions($controller);

        return is_array($declared) ? $declared : null;
    }

    /**
     * The controller's $actions declaration, null when it does not have one.
     *
     * hasProperty() sees inherited properties too, so a base controller that uses the name for
     * something of its own makes every controller under it unusable rather than silently falling
     * back to reflection. That is the intended direction -- the alternative is a base class whose
     * declaration is quietly ignored -- and it is why the caller reports it as its own error
     * rather than as a malformed entry.
     *
     * @param string $controller
     * @return array<string, mixed>|false|null False when the property exists but is not a public
     *                                         static array, or is typed and never initialized
     */
    protected static function _declared_actions($controller)
    {
        $ref = new ReflectionClass($controller);

        if ( !$ref->hasProperty('actions') )
        {
            return null;
        }

        $prop = $ref->getProperty('actions');

        // isInitialized() before getValue(): reading a typed static that was declared without a
        // default is a fatal Error, not something getValue() reports back.
        if ( !$prop->isStatic() || !$prop->isPublic() || !$prop->isInitialized() )
        {
            return false;
        }

        $value = $prop->getValue();

        return is_array($value) ? $value : false;
    }

    /**
     * Normalize one declared action without guessing at a partly structured declaration.
     *
     * @param mixed $declaration
     * @return array{methods: mixed, auth: string}|null
     */
    private static function _action_metadata($declaration): ?array
    {
        $structured = is_array($declaration)
            && (array_key_exists('methods', $declaration) || array_key_exists('auth', $declaration));

        if ( $structured )
        {
            if ( !array_key_exists('methods', $declaration)
                || !array_key_exists('auth', $declaration)
                || !self::valid_methods($declaration['methods'])
                || !in_array($declaration['auth'], self::$_auth_modes, true) )
            {
                return null;
            }

            return [
                'methods' => $declaration['methods'],
                'auth'    => $declaration['auth'],
            ];
        }

        if ( !self::valid_methods($declaration) )
        {
            return null;
        }

        return [
            'methods' => $declaration,
            'auth'    => self::AUTH_OPTIONAL,
        ];
    }

    /**
     * Normalize and validate a method declaration.
     *
     * @param mixed $methods
     * @return array<int, string>|null
     */
    private static function _method_list($methods): ?array
    {
        if ( $methods === '*' || $methods === true )
        {
            return ['*'];
        }

        if ( is_string($methods) )
        {
            $methods = explode(',', $methods);
        }

        if ( !is_array($methods) || $methods === [] )
        {
            return null;
        }

        $list = [];
        foreach ( $methods as $method )
        {
            if ( !is_string($method) )
            {
                return null;
            }

            $method = strtoupper(trim($method));
            if ( $method === '' || ($method !== '*' && !in_array($method, self::$_known_methods, true)) )
            {
                return null;
            }

            $list[] = $method;
        }

        return array_values(array_unique($list));
    }

    /**
     * HTTP Allow value for one action declaration.
     *
     * @param mixed $methods
     */
    private static function _allow_header($methods): string
    {
        $list = self::_method_list($methods);
        if ( $list === null )
        {
            return '';
        }

        if ( in_array('*', $list, true) )
        {
            $list = self::$_known_methods;
        }

        $allowed = [];
        foreach ( $list as $method )
        {
            $allowed[] = $method;
            if ( $method === 'GET' && !in_array('HEAD', $list, true) )
            {
                $allowed[] = 'HEAD';
            }
        }

        return implode(', ', array_values(array_unique($allowed)));
    }

    /**
     * Whether a method may be routed to under the reflection fallback.
     *
     * Note that a method a controller picks up from a trait counts as declared by the
     * controller, because as far as PHP is concerned it is. Excluding those needs an explicit
     * $actions declaration.
     *
     * @param string $controller
     * @param string $ac
     * @return bool
     */
    protected static function _is_routable_method($controller, $ac, $declared_here = true)
    {
        if ( !method_exists($controller, $ac) )
        {
            return false;
        }

        $ref = new ReflectionMethod($controller, $ac);

        // getName() returns the declared spelling, so === refuses the case variants that PHP's
        // own method lookup would otherwise accept
        return $ref->getName() === $ac
            && $ref->isPublic()
            && !$ref->isStatic()
            && !$ref->isAbstract()
            && !$ref->isConstructor()
            && !$ref->isDestructor()
            && $ac[0] !== '_'
            && (!$declared_here || $ref->getDeclaringClass()->getName() === ltrim($controller, '\\'));
    }

    /**
     * Build a url for a route, the counterpart of the path parsing above.
     *
     * Generation and parsing read the same configuration, so a url this produces is a url the
     * router accepts. That is what makes rewriting safe to rely on, and it replaces rewriting
     * links by regex over the rendered html after the fact.
     *
     * @param string $ct
     * @param string|null $ac
     * @param array<string, mixed> $params   Query string parameters
     * @param array<int, string>   $segments Trailing path segments
     * @return string
     * @throws route_exception When asked to build a url that would not parse back
     */
    public static function url($ct, $ac = null, array $params = [], array $segments = [])
    {
        $default_ct = (string) self::config('default_ct');
        $default_ac = (string) self::config('default_ac');

        $ct = (string) $ct;
        $ac = $ac === null ? $default_ac : (string) $ac;

        if ( !self::valid_name($ct) || !self::valid_name($ac) )
        {
            throw new route_exception([$ct . ':' . $ac], 2007);
        }

        $path = [];
        foreach ( $segments as $segment )
        {
            $segment = (string) $segment;

            if ( !self::valid_segment($segment) )
            {
                throw new route_exception([$ct . ':' . $ac], 2007);
            }

            $path[] = rawurlencode($segment);
        }

        // Collapse to the shortest form that parses back to the same route, so every route has
        // one canonical url instead of several
        if ( $path === [] && $ac === $default_ac )
        {
            $parts = $ct === $default_ct ? [] : [$ct];
        }
        else
        {
            $parts = array_merge([$ct, $ac], $path);
        }

        $url = rtrim((string) self::config('base_path'), '/') . '/' . implode('/', $parts);

        $suffix = (string) self::config('path_suffix');
        if ( $suffix !== '' && $parts !== [] )
        {
            $url .= $suffix;
        }

        if ( $params !== [] )
        {
            $url .= '?' . http_build_query($params);
        }

        return $url;
    }

    /**
     * Parse a request uri into path segments.
     *
     * @param string $uri
     * @return array{segments: array<int, string>, redirect: string}|false False when the path is not routable
     */
    protected static function _parse($uri)
    {
        $path = explode('?', $uri, 2)[0];
        $path = explode('#', $path, 2)[0];

        if ( strlen($path) > (int) self::config('max_path_length') )
        {
            return false;
        }

        // Checked before decoding, so an encoded backslash or null byte cannot slip past
        if ( $path !== '' && preg_match('#%00|\\\\#i', $path) === 1 )
        {
            return false;
        }

        if ( $path === '' )
        {
            $path = '/';
        }

        $base = rtrim((string) self::config('base_path'), '/');
        if ( $base !== '' )
        {
            if ( strpos($path, $base . '/') !== 0 && $path !== $base )
            {
                return false;
            }

            $path = substr($path, strlen($base));
            $path = $path === '' ? '/' : $path;
        }

        if ( $path[0] !== '/' )
        {
            return false;
        }

        $suffix = (string) self::config('path_suffix');
        if ( $suffix !== '' && substr($path, -strlen($suffix)) === $suffix )
        {
            $path = substr($path, 0, -strlen($suffix));
        }

        // A trailing slash is a different url for the same route; report it so the dispatcher
        // can redirect to the canonical one rather than serving both
        $redirect = false;
        if ( strlen($path) > 1 && substr($path, -1) === '/' )
        {
            $redirect = true;
            $path     = rtrim($path, '/');
        }

        $trimmed = trim($path, '/');

        if ( $trimmed === '' )
        {
            return ['segments' => [], 'redirect' => ''];
        }

        $raw = explode('/', $trimmed);

        if ( count($raw) > (int) self::config('max_segments') )
        {
            return false;
        }

        $segments = [];
        foreach ( $raw as $i => $segment )
        {
            // An empty segment means the path held // -- refuse it instead of collapsing it,
            // otherwise /a//b and /a/b are the same route by two different names
            if ( $segment === '' )
            {
                return false;
            }

            // Split first, decode once. Decoding earlier would let %2F create a new segment.
            $segment = rawurldecode($segment);

            if ( strpbrk($segment, "/\\\0%") !== false )
            {
                return false;
            }

            $valid = $i < 2 ? self::valid_name($segment) : self::valid_segment($segment);

            if ( !$valid )
            {
                return false;
            }

            $segments[] = $segment;
        }

        return [
            'segments' => $segments,
            // Built from the validated segments only. Reflecting the raw path back into a
            // Location header would be an open redirect and a header injection.
            'redirect' => $redirect ? self::_canonical($segments) : '',
        ];
    }

    /**
     * Canonical path for a validated segment list.
     *
     * @param array<int, string> $segments
     * @return string
     */
    protected static function _canonical(array $segments)
    {
        $encoded = array_map('rawurlencode', $segments);
        $path    = rtrim((string) self::config('base_path'), '/') . '/' . implode('/', $encoded);

        $suffix = (string) self::config('path_suffix');

        return $suffix !== '' && $segments !== [] ? $path . $suffix : $path;
    }

    /**
     * Whether a parsed path is the crypto entry.
     *
     * @param array{segments: array<int, string>, redirect: string} $parsed
     * @return bool
     */
    protected static function _is_crypto_entry(array $parsed)
    {
        $entry = (string) self::config('crypto_entry');

        if ( $entry === '' || self::$_crypto_resolver === null )
        {
            return false;
        }

        $path = '/' . implode('/', $parsed['segments']);

        return $path === '/' . trim($entry, '/');
    }

    /**
     * Ask the registered resolver whether this request is an encrypted envelope.
     *
     * @return array<string, mixed>|null
     */
    protected static function _resolve_crypto()
    {
        if ( self::$_crypto_resolver === null )
        {
            return null;
        }

        $envelope = call_user_func(self::$_crypto_resolver);

        return is_array($envelope) ? $envelope : null;
    }

    /**
     * Work out the request method from a server array.
     *
     * Kept separate from the current request so the override policy can be tested: under the
     * CLI sapi the live method is always CLI, which would make the policy untestable.
     *
     * @param array<string, mixed> $server
     * @return string
     */
    public static function detect_method(array $server)
    {
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));

        if ( self::config('method_override') && $method === 'POST' )
        {
            $override = strtoupper((string) ($server['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? ''));

            // Only ever a promotion of POST. An override to GET / HEAD / OPTIONS would let a
            // client move a state changing request onto a branch where the csrf check does not
            // run, which is what made the old unconditional override a csrf bypass.
            if ( in_array($override, ['PUT', 'PATCH', 'DELETE'], true) )
            {
                $method = $override;
            }
        }

        return $method;
    }

    /**
     * Request method, without trusting the override header unless it was turned on.
     *
     * @return string
     */
    protected static function _method()
    {
        if ( PHP_SAPI === 'cli' )
        {
            return 'CLI';
        }

        return self::detect_method((array) req::server());
    }

    /**
     * Raw request path.
     *
     * @return string
     */
    protected static function _request_uri()
    {
        $uri = (string) req::server('REQUEST_URI', '');

        if ( $uri === '' )
        {
            $uri = (string) req::server('PATH_INFO', '');
        }

        return $uri;
    }

    /**
     * Request path, reduced to characters that are safe to put in a log or an error message.
     *
     * @return string
     */
    protected static function _safe_uri()
    {
        $uri = substr(self::_request_uri(), 0, 128);

        return (string) preg_replace('/[^\x20-\x7E]/', '', $uri);
    }
}
