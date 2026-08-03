<?php

return [
    // Access control, owned by plato\security\security.
    'security' => [
        // Addresses allowed to see debug output, see plato\debug\error_handler
        'safe_client_ip' => [
            '127.0.0.1'
        ],
        // Exact addresses, not ranges. The whitelist wins over both blacklists, which is how a
        // wrongly geolocated address is kept working
        'ip_whitelist' => [],
        'ip_blacklist' => [],
        // Two letter country codes, resolved by req::country()
        'country_whitelist' => [],
        'country_blacklist' => [],
        // Origins allowed to make cross site requests, with the scheme and any non default port,
        // e.g. https://www.example.com. '*' answers any origin with
        // Access-Control-Allow-Origin: * and no Allow-Credentials; naming origins instead limits
        // it to those, and a match echoes the origin back and allows cookies.
        // Send no CORS headers at all with 'allow_origin' => false -- an empty array does not do
        // it, because an application's configuration is merged into this one rather than
        // replacing it
        'allow_origin' => [
            '*'
        ],
        'cors' => [
            // Automatically answer a browser preflight after the route and requested method have
            // been validated, but before CSRF, authentication and the controller action. Set false
            // to bind OPTIONS like an ordinary action, which was the behavior before this switch.
            'preflight' => true,
            // Seconds a browser may cache a preflight answer. 0 sends no Access-Control-Max-Age
            // and makes every cross origin write pay for a second round trip.
            'max_age' => 600,
            // Request headers a preflight may approve. Absent -- the default -- approves whatever
            // the browser asked for, which is what a named origin with Allow-Credentials gets as
            // well. Name the headers to narrow it:
            //     'allow_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
            // Matching is case insensitive, and a request naming anything outside the list is not
            // approved at all rather than approved in part. A malformed configured list also
            // approves nothing. It is deliberately absent instead of shipped as ['*']: an
            // application's configuration is merged into this one, so a list here could not be
            // shortened by an application.
        ],
    ],

    // Routing, owned by plato\http\route.
    // Scalars only. List valued settings (default_methods, crypto_required) default inside the
    // route class instead: an application's configuration is merged into this file rather than
    // replacing it, so a non empty list here could never be shortened -- ['GET','POST'] overridden
    // with ['POST'] merges into ['POST','POST']. Defaulted in the class, the application's list
    // takes effect whole.
    'route' => [
        'default_ct'      => 'index',
        'default_ac'      => 'index',
        // Path prefix to strip when the application is not at the document root, e.g. '/blog'.
        // Deliberately explicit: deriving it from SCRIPT_NAME makes routing and the web server
        // disagree in exactly the deployments where that is hardest to track down
        'base_path'       => '',
        // Optional URL suffix, e.g. '.html'. Stripped before parsing, appended by url()
        'path_suffix'     => '',
        // Entry path of the encrypted envelope. '' turns that route source off entirely
        'crypto_entry'    => '/',
        // With true a controller has to declare public static $actions, and any action missing
        // from it is a 404
        'strict_actions'  => false,
        // Whether X-HTTP-Method-Override is honoured. Off by default, and even when on only a POST
        // may be promoted to PUT / PATCH / DELETE -- allowing an override to GET / HEAD would let
        // a client move a state changing request onto the branch that runs no CSRF check
        'method_override' => false,
        'max_path_length' => 255,
        'max_segments'    => 8,
    ],

    // Middleware, by route pattern: '*' for every route, 'ct:*' for a controller, 'ct:ac' for one
    // action. Every entry is a class with handle(callable $next), a Closure, or any other callable;
    // see plato\http\pipeline. Order is the order of this file, '*' first, and a middleware named
    // twice still runs once.
    //
    //  'middleware' => [
    //      '*'         => ['middleware\cors'],
    //      'admin:*'   => ['middleware\admin_only'],
    //  ],
    //
    // Empty here on purpose: the framework ships no middleware of its own, and a non empty default
    // could not be shortened by an application -- configuration is merged into it, not replacing it.
    'middleware' => [],

    // Rate limiting, read by plato\security\throttle. The limiter is **not** applied to anything
    // until the class is named in `middleware` above -- these are the settings it will use then,
    // not a switch that turns it on:
    //
    //  'middleware' => ['*' => ['plato\security\throttle']],
    //
    // `by` decides what counts as one caller: 'ip', 'route' (one allowance shared by everybody on
    // that route), 'ip_route' (per caller per route), or any callable answering a string.
    // `message` is the body of the 429; a callable there is called with the seconds left and
    // answers the request itself.
    //
    // The counter is a fixed window, so a caller can spend its allowance at the end of one window
    // and the next one at the start of the following: halve the window if that burst matters.
    // Counting is atomic on the redis cache driver and read-modify-write on the other three.
    'throttle' => [
        'enable'  => true,
        'limit'   => 60,
        'window'  => 60,
        'by'      => 'ip_route',
        'message' => 'Too many requests',
    ],

    // Distributed locks, owned by plato\lock. `connection` is a logical redis connection name: a
    // name of its own gets a socket of its own, and `server` (same shape as `redis.server` in
    // config/cache.php) moves the locks to a server of their own -- spell out `prefix` inside it
    // when doing that, since only the cache settings carry one by default. A lock server must not
    // evict keys: an `allkeys-*` maxmemory policy is free to drop the key two processes are
    // relying on, which lets both of them hold the same lock.
    'lock' => [
        'connection'       => 'redis',
        'server'           => [],
        'prefix'           => 'Lock:',
        'expire'           => 15,
        'wait_interval_us' => 100000,
    ],

    // Console commands an application adds, as class names implementing plato\console\command.
    // plato.config.php can name them under `commands` as well, which is what a command that has to
    // be reachable before the framework boots needs
    'console' => [
        'commands' => [],
    ],

    // Request handling and CSRF, owned by plato\http\req.
    'request' => [
        'csrf_token_on'     => true,                // Whether the token is checked at all
        'csrf_token_name'   => 'csrf_token_name',   // Name of the hidden form field holding it
        'csrf_token_reset'  => true,                // Issue a new token after every submitted check
        'csrf_cookie_name'  => 'csrf_cookie_name',  // Cookie the token is kept in
        'csrf_expire'       => 86400,               // Token lifetime in seconds
        // Server-only HMAC key. Keep at least 32 random bytes in .env; an empty value makes an
        // enabled CSRF policy fail during bootstrap instead of issuing unsigned tokens.
        'csrf_secret'       => $_ENV['CSRF_SECRET'] ?? '',
        // Called once per request and included in the HMAC. session_id is safe only when registry()
        // starts the session; custom authentication supplies a callback returning its own stable,
        // opaque session identity.
        'csrf_binding'      => 'session_id',
        // Addresses exempt from the CSRF check. The framework default is empty because merged
        // defaults must never disable a security check for the host application.
        'csrf_white_ips'    => [],
        // Routes exempt from the CSRF check, written 'ct:ac' or 'ct:*' and matched against the
        // resolved route.
        'csrf_exclude_routes'  => [],
        // Origins besides this site allowed to make non idempotent requests, with the scheme,
        // e.g. https://app.example.com
        'csrf_trusted_origins' => [],
        // Header the caller's address is read from: X_REAL_IP | X-Forwarded-For
        'user_ip'              => 'X_REAL_IP',
    ],

    // Encrypted request envelope, owned by plato\http\envelope. With an empty path, ct/ac are
    // read out of the ciphertext.
    // Client keys are all extractable (on the web they sit in the JavaScript), so the envelope is
    // a wire format and a client identifier, not authentication and not CSRF protection:
    // authorization still goes through check_purview_handle and CSRF through csrf_verify().
    // `clients` is the key table -- a secret and a list -- so it defaults inside the envelope class
    // (empty) and the application fills it from $_ENV.
    'crypto' => [
        // Server variable naming the client platform, used only to pick a key. An unknown value is
        // refused: no falling back to a default key, and never trying every key in turn, which
        // would turn the failure path into an oracle for which platform a ciphertext belongs to
        'client_header' => 'HTTP_X_CLIENT',
        // Accepted clock skew and nonce lifetime, in seconds. 0 turns replay protection off.
        'replay_window' => 300,
        'nonce_prefix'  => 'plato:envelope:nonce',
        // Maximum JSON bytes accepted after authenticated decompression.
        'max_plaintext_bytes' => 4_194_304,
        // Replies use the same binary envelope and compress only when that saves bytes.
        'encrypt_reply'  => true,
        'compress_reply' => true,
    ],

    // Cookie defaults, owned by plato\http\resp. Every one of these is a default for
    // resp::cookie(), which takes the same keys per call.
    'cookie' => [
        // Prepended by resp::cookie() and stripped again by req::cookie()
        'prefix'   => 'plato_',
        'expire'   => 7200,
        'path'     => '/',
        // Use '.example.com' when subdomains have to share the cookie
        'domain'   => null,
        'secure'   => false,
        'httponly' => false,
        // None requires secure=true as well, and makes the cookie ride along on cross site
        // requests -- do not loosen this without a reason
        'samesite' => 'Lax',
    ],

    // Sections of the debug output, see plato\debug\profiler
    'profiler' => [
        'benchmarks'         => true,
        'config'             => true,
        'controller_info'    => true,
        'http_headers'       => true,
        'uri_string'         => true,
        'get'                => true,
        'post'               => true,
        'cookie_data'        => true,
        'session_data'       => true,
        'memory_usage'       => true,
        'queries'            => true,
        'query_toggle_count' => 25,
    ],

    // Smarty settings, owned by plato\tpl.
    'template' => [
        'left_delimiter'  => '{',
        'right_delimiter' => '}',
        // true recompiles a template whose source changed, at one stat per render. Off in
        // production, where the sources do not change under a running process
        'compile_check'   => true,
        'force_compile'   => false,
        // Escape template variables by default; use nofilter only for deliberately trusted HTML.
        'escape_html'     => true,
        'debugging'       => false,
        'caching'         => false,
        'cache_lifetime'  => 120,
        // Plugin directory names under the application path. Several are allowed, and the one
        // registered first wins a name clash
        'plugins' => [
            'smarty_plugins'
        ],
    ],

    // There is no `page` section: plato\paginator is a pure function of its arguments.

    // Date and time helpers, owned by plato\date. Separate from `timezone_set` below on purpose:
    // that one is the process timezone every bare date() call reads, this one is what the
    // application displays. A deployment serving several regions moves this and leaves the process
    // on UTC. Empty means "whatever the process is set to".
    'date' => [
        'timezone' => '',
        'format'   => 'Y-m-d H:i:s',
    ],

    // Request signing, owned by plato\security\sign. Secrets are never configured here: the key
    // table is the application's, read from the environment and passed in per call.
    //   algo   any name from hash_algos()
    //   field  parameter carrying the signature, left out of the payload
    //   style  'hmac', or 'append' for hash(payload . '&key=' . secret) when an existing
    //          counterpart already speaks that shape
    //   upper  uppercase the hexadecimal digest, which some counterparts expect
    // The list of excluded fields defaults inside the class (ct, ac): a list here could not be
    // shortened by an application, since this file is merged into rather than replaced.
    'sign' => [
        'algo'  => 'sha256',
        'field' => 'sign',
        'style' => 'hmac',
        'upper' => false,
    ],

    // Handed to date_default_timezone_set() while plato::registry() boots
    'timezone_set' => 'Asia/Shanghai',
];
