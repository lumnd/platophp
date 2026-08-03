<?php

/**
 * CSRF defences and the ip / country gates
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\security;

use plato\config;
use plato\exception\route_exception;
use plato\http\envelope;
use plato\http\req;
use plato\http\reply;
use plato\http\resp;
use plato\http\route;
use plato\log;
use plato\plato;
use plato\view\msgbox;
use RuntimeException;

/**
 * CSRF protection, plus the two request gates the event pipeline calls.
 *
 * The CSRF scheme is signed double submit. A random nonce and its session-bound HMAC go out in a
 * cookie and have to come back in the request, alongside an Origin check. See csrf_verify() for
 * why all three conditions have to hold.
 *
 * Settings come from several owners. The token settings (`csrf_token_on`, `csrf_token_name`,
 * `csrf_token_reset`, `csrf_cookie_name`, `csrf_expire`, `csrf_secret`, `csrf_binding`,
 * `csrf_white_ips`, `csrf_exclude_routes`, `csrf_trusted_origins`) belong to the `request` section
 * and are read through req::config(); the cookie attributes belong to the `cookie` section and are
 * read through resp::config(). CORS, IP and country settings are this class's own `security` section.
 */
class security
{
    /**
     * Fallbacks for the csrf settings capture() reads out of the `request` section.
     *
     * They sit here rather than only on the properties so that capture() can re-read every
     * setting on every call without repeating the literals. The secret and binding entries are
     * validated before use, so these empty/default values can never sign a token.
     *
     * @var array<string, bool|int|string>
     */
    private const CSRF_DEFAULTS = [
        'csrf_token_on'    => true,
        'csrf_token_reset' => true,
        'csrf_expire'      => 7200,
        'csrf_token_name'  => 'csrf_token_name',
        'csrf_cookie_name' => 'csrf_cookie_name',
        'csrf_secret'      => '',
        'csrf_binding'     => 'session_id',
    ];

    private const CSRF_TOKEN_PATTERN = '/^v1\.([0-9a-f]{32})\.([0-9a-f]{64})$/D';

    /**
     * The `security` section, null until config() reads it. The `cookie` section is not held
     * here at all -- resp owns that one, and csrf_set_cookie() reads it through resp::config().
     *
     * @var array<string, mixed>|null
     */
    private static $_config = null;

    /**
     * The hash of this request, null until _csrf_set_hash() issues or recovers one
     */
    private static ?string $_csrf_hash = null;

    private static bool $_csrf_token_on = self::CSRF_DEFAULTS['csrf_token_on'];

    /** Whether a submission burns its hash and gets a new one */
    private static bool $_csrf_token_reset = self::CSRF_DEFAULTS['csrf_token_reset'];

    /** Lifetime of the cookie in seconds */
    private static int $_csrf_expire = self::CSRF_DEFAULTS['csrf_expire'];

    /** Field name the token is expected under */
    private static string $_csrf_token_name = self::CSRF_DEFAULTS['csrf_token_name'];

    /** Cookie name the hash is sent in */
    private static string $_csrf_cookie_name = self::CSRF_DEFAULTS['csrf_cookie_name'];

    /** Server-only key used to authenticate tokens */
    private static string $_csrf_secret = '';

    /** Request-specific session or authentication identity included in the HMAC */
    private static string $_csrf_binding = '';

    /**
     * The `security` settings, read on the first call that needs them.
     *
     * @param string|null $key One setting, or null for all of them
     *
     * @return mixed
     */
    public static function config(?string $key = null)
    {
        if ( self::$_config === null )
        {
            self::$_config = (array) config::instance('config')->get('security');
        }

        return $key === null ? self::$_config : (self::$_config[$key] ?? null);
    }

    /**
     * Hand the settings over instead of letting them be read from config/config.php.
     *
     * Merges on top of the file settings, so an override names only what it changes.
     *
     * @param array<string, mixed> $config Same shape as the `security` section
     *
     * @return void
     */
    public static function configure(array $config): void
    {
        self::$_config = $config + (array) self::config();
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
     * Method a browser preflight wants to use, null for an ordinary request.
     *
     * A preflight is OPTIONS carrying both Origin and Access-Control-Request-Method. Merely sending
     * OPTIONS is not enough: applications may expose an ordinary OPTIONS action, and disabling
     * security.cors.preflight must restore that method binding exactly.
     *
     * Once both preflight headers are present this returns a candidate even when it is unsupported,
     * so handle() can reject it through action method binding rather than accidentally executing an
     * OPTIONS action. A malformed method returns an empty string, which binding rejects as well.
     */
    public static function preflight_method(): ?string
    {
        $cors = (array) self::config('cors');

        if ( !($cors['preflight'] ?? false)
            || !route::is_resolved()
            || route::method() !== 'OPTIONS'
            || (string) req::server('HTTP_ORIGIN', '') === '' )
        {
            return null;
        }

        $method = strtoupper(trim((string) req::server('HTTP_ACCESS_CONTROL_REQUEST_METHOD', '')));

        return preg_match('/^[A-Z][A-Z0-9_-]{0,31}$/D', $method) === 1 ? $method : '';
    }

    /**
     * Seconds a browser may cache a preflight answer, 0 for "do not advertise a lifetime".
     */
    public static function preflight_max_age(): int
    {
        $cors = (array) self::config('cors');

        return max(0, (int) ($cors['max_age'] ?? 0));
    }

    /**
     * The Access-Control-Allow-Headers value for this preflight, '' when there is nothing to
     * approve and null when the request asked for a header the configuration does not allow.
     *
     * A request naming anything outside security.cors.allow_headers is refused whole rather than
     * approved in part: answering with the subset lets the browser make the request and then be
     * surprised by it, while a preflight that simply does not list the header is the failure mode
     * CORS is built around. With no allow_headers configured the requested list is echoed back,
     * which is what a preflight did before the setting existed.
     */
    public static function preflight_headers(): ?string
    {
        $requested = trim((string) req::server('HTTP_ACCESS_CONTROL_REQUEST_HEADERS', ''));

        if ( $requested === '' )
        {
            return '';
        }

        // A header list is a comma separated sequence of tokens, RFC 9110 5.6.2. Anything else is
        // not a list this class is going to reason about.
        if ( preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+(?:\s*,\s*[A-Za-z0-9!#$%&\'*+.^_`|~-]+)*$/D', $requested) !== 1 )
        {
            return null;
        }

        $cors    = (array) self::config('cors');
        $allowed = $cors['allow_headers'] ?? null;

        if ( $allowed === null )
        {
            return $requested;
        }

        if ( !is_array($allowed) )
        {
            return null;
        }

        $normalized = [];
        foreach ( $allowed as $header )
        {
            if ( !is_string($header)
                || preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/D', trim($header)) !== 1 )
            {
                return null;
            }

            $normalized[] = strtolower(trim($header));
        }

        foreach ( explode(',', $requested) as $header )
        {
            if ( !in_array(strtolower(trim($header)), $normalized, true) )
            {
                return null;
            }
        }

        return $requested;
    }

    /**
     * Set the CSRF defences up for the request that is starting.
     *
     * plato::_bootstrap() calls this once during bootstrap, after req::capture(): the token settings
     * live in the request config, and the hash is per request. It is public and repeatable so an
     * entry point serving many requests from one process can issue a fresh hash for each of them.
     *
     * @return void
     */
    public static function capture(): void
    {
        // Both lines are the request boundary, and both matter in a resident worker. Dropping the
        // hash is what makes the promise above true: _csrf_set_hash() keeps the one it already has,
        // so without this the second request served by a worker would be handed the first request's
        // token -- two callers sharing a token means either of them can forge for the other.
        // Reading the flag again rather than leaving it set is the same argument for a configure()
        // that turned protection off between requests.
        self::$_csrf_hash      = null;
        self::$_csrf_secret    = '';
        self::$_csrf_binding   = '';
        self::$_csrf_token_on = (bool) (req::config('csrf_token_on') ?? self::CSRF_DEFAULTS['csrf_token_on']);

        if ( PHP_SAPI === 'cli' && !plato::config('cli_csrf') )
        {
            self::$_csrf_token_on = false;
        }

        if ( !self::$_csrf_token_on )
        {
            return;
        }

        // Read one by one so the effective values have the declared scalar types.
        self::$_csrf_token_reset = (bool) (req::config('csrf_token_reset') ?? self::CSRF_DEFAULTS['csrf_token_reset']);
        self::$_csrf_expire      = (int) (req::config('csrf_expire') ?? self::CSRF_DEFAULTS['csrf_expire']);
        self::$_csrf_token_name  = (string) (req::config('csrf_token_name') ?? self::CSRF_DEFAULTS['csrf_token_name']);
        self::$_csrf_cookie_name = (string) (req::config('csrf_cookie_name')
            ?? self::CSRF_DEFAULTS['csrf_cookie_name']);

        $secret  = req::config('csrf_secret') ?? self::CSRF_DEFAULTS['csrf_secret'];
        $binding = req::config('csrf_binding') ?? self::CSRF_DEFAULTS['csrf_binding'];

        if ( !is_string($secret) || strlen($secret) < 32 )
        {
            throw new RuntimeException('csrf: request.csrf_secret must contain at least 32 bytes');
        }

        if ( !is_callable($binding) )
        {
            throw new RuntimeException('csrf: request.csrf_binding must be callable');
        }

        $binding = call_user_func($binding);
        if ( !is_string($binding) || $binding === '' )
        {
            throw new RuntimeException('csrf: request.csrf_binding must return a non-empty string');
        }

        self::$_csrf_secret  = $secret;
        self::$_csrf_binding = $binding;

        self::_csrf_set_hash();
    }

    /**
     * Whether an address falls inside a CIDR block.
     *
     * IPv4 only, which is what the csrf_white_ips list it backs is written in. A value with no
     * mask is treated as a single host, and anything that does not parse as an address is a
     * non match rather than an error -- a malformed entry in a whitelist must not widen it.
     *
     * @param  string $addr Address to test
     * @param  string $cidr Block, `10.0.0.0/8`, or a bare address
     * @return bool
     */
    public static function match_cidr(string $addr, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);

        // A mask that is not a number is a malformed entry, not a /0.
        if ( isset($parts[1]) && preg_match('/^\d{1,2}$/', $parts[1]) !== 1 )
        {
            return false;
        }

        $mask = isset($parts[1]) ? (int) $parts[1] : 32;
        $net  = ip2long($parts[0]);
        $ip   = ip2long($addr);

        if ( $net === false || $ip === false || $mask > 32 )
        {
            return false;
        }

        // /0 is everything, and it is also the one shift width the comparison below cannot take
        if ( $mask === 0 )
        {
            return true;
        }

        return ($ip >> (32 - $mask)) === ($net >> (32 - $mask));
    }

    /**
     * Checks the token of a state changing request, and refreshes it either way.
     *
     * @return bool|reply True once the request may continue, or a 403 response when validation fails.
     *                    False only means the fresh cookie could not be sent, see csrf_set_cookie().
     */
    public static function csrf_verify()
    {
        if ( !self::$_csrf_token_on )
        {
            return true;
        }

        $method = route::is_resolved() ? route::method() : req::method();

        // Safe methods cannot change state, so they only get a fresh cookie. The router never lets
        // a method override downgrade a POST to a safe method.
        if ( in_array($method, ['GET', 'HEAD', 'OPTIONS', 'CLI'], true) )
        {
            return self::csrf_set_cookie();
        }

        // Routes excluded from the check, as 'ct:ac' or 'ct:*', matched after route resolution.
        if ( route::is_resolved() && route::matches((array) req::config('csrf_exclude_routes')) )
        {
            return true;
        }

        foreach ( (array) req::config('csrf_white_ips') as $ip )
        {
            if ( self::match_cidr(req::ip(), (string) $ip) )
            {
                return true;
            }
        }

        $origin_ok = self::origin_allowed();
        $token     = self::csrf_request_token();
        $cookie    = req::cookie(self::$_csrf_cookie_name);

        // Equality proves the caller read the cookie; the signature proves that neither a sibling
        // domain nor another authenticated session planted that cookie. Origin is checked as a
        // separate browser signal, so a weakness in any one condition does not bypass the other two.
        $valid = $origin_ok
            && $token !== ''
            && is_string($cookie) && $cookie !== ''
            && self::_csrf_token_valid($cookie)
            && hash_equals($cookie, $token);

        // A submission burns its hash: the cookie has to go too, otherwise _csrf_set_hash()
        // recovers the old value from it instead of issuing a new one
        if ( self::$_csrf_token_reset )
        {
            unset($_COOKIE[self::$_csrf_cookie_name]);
            self::$_csrf_hash = null;
        }

        self::_csrf_set_hash();
        self::csrf_set_cookie();

        if ( !$valid )
        {
            return self::csrf_show_error();
        }

        return true;
    }

    /**
     * The csrf token the request presented, '' when it presented none.
     *
     * Three places are checked rather than one picked by req::is_ajax(): the envelope, because
     * an encrypted request has no form fields of its own until it is decoded; the request
     * parameters, which is where a form puts it; and the header, which is where a fetch or
     * XMLHttpRequest puts it.
     *
     * @return string
     */
    public static function csrf_request_token(): string
    {
        if ( envelope::is_active() && envelope::csrf() !== '' )
        {
            return envelope::csrf();
        }

        $token = req::post(self::$_csrf_token_name);
        unset(req::$posts[self::$_csrf_token_name], req::$forms[self::$_csrf_token_name]);

        if ( is_string($token) && $token !== '' )
        {
            return $token;
        }

        return (string) req::server('HTTP_X_CSRF_TOKEN', '');
    }

    /**
     * Whether the request came from an origin that is allowed to make it.
     *
     * Origin cannot be set by page script, so it is the part of a cross site request an attacker
     * does not control -- which is what makes it worth checking alongside the token, and what
     * makes it the only defence that still works when the client side encryption key is public.
     *
     * Note that the comparison is against the host the request claims, so it relies on the web
     * server refusing requests for host names it does not serve. List the origins explicitly in
     * request.csrf_trusted_origins when that is not guaranteed.
     *
     * A request with neither Origin nor Referer is left to the token: non browser clients do not
     * send either, and refusing them outright would break every server to server caller.
     *
     * @return bool
     */
    public static function origin_allowed(): bool
    {
        $origin = (string) req::server('HTTP_ORIGIN', '');

        if ( $origin === '' )
        {
            $referer = (string) req::server('HTTP_REFERER', '');

            if ( $referer === '' )
            {
                return true;
            }

            $parts = parse_url($referer);

            // parse_url() answers false on a seriously malformed URL.
            if ( !is_array($parts) || empty($parts['scheme']) || empty($parts['host']) )
            {
                return false;
            }

            $origin = $parts['scheme'] . '://' . $parts['host']
                . (empty($parts['port']) ? '' : ':' . $parts['port']);
        }

        if ( strcasecmp($origin, req::domain()) === 0 )
        {
            return true;
        }

        foreach ( (array) req::config('csrf_trusted_origins') as $trusted )
        {
            if ( strcasecmp($origin, (string) $trusted) === 0 )
            {
                return true;
            }
        }

        log::warning('csrf: origin ' . $origin . ' is not allowed for ' . req::domain());

        return false;
    }

    /**
     * Sends the current hash out in its cookie.
     *
     * @codeCoverageIgnore
     * @return bool  False when the cookie is configured secure and this request is not on https,
     *               in which case nothing is sent -- the browser would drop it anyway
     */
    public static function csrf_set_cookie(): bool
    {
        $secure_cookie = (bool) resp::config('secure');

        if ( $secure_cookie && req::protocol() !== 'https' )
        {
            return false;
        }

        return setcookie(self::$_csrf_cookie_name, (string) self::$_csrf_hash, [
            'expires'  => time() + self::$_csrf_expire,
            'path'     => (string) resp::config('path'),
            'domain'   => (string) resp::config('domain'),
            'secure'   => $secure_cookie,
            // Deliberately not httponly, and not taken from the cookie config. A double-submit
            // token must be readable by the client so it can be copied into the request. Same
            // origin, SameSite, and the Origin check constrain where it can be sent.
            'httponly' => false,
            // Lax, not None: the cookie has no reason to travel on a cross site POST, and
            // without this attribute the browser default varies
            'samesite' => (string) (resp::config('samesite') ?? 'Lax'),
        ]);
    }

    /**
     * @return reply
     */
    public static function csrf_show_error(): reply
    {
        log::error(req::ip() . ' - ' . req::query_string(), 'CSRF Error');
        return msgbox::error(403);
    }

    /**
     * The hash of this request, for a form field or a meta tag.
     *
     * @return string|null Null when csrf protection is off, so capture() never issued one
     */
    public static function get_csrf_hash(): ?string
    {
        return self::$_csrf_hash;
    }

    /**
     * The field name csrf_verify() expects the hash back under.
     *
     * @return string
     */
    public static function get_csrf_token_name(): string
    {
        return self::$_csrf_token_name;
    }

    /**
     * Issues this request's hash, or recovers the one already in the cookie.
     *
     * The cookie wins only when its signature matches this request's binding. A page that embeds
     * sub requests otherwise has each of them mint a new token and invalidate the form on the page.
     * Which is also why capture(), not this method, drops the hash at a request boundary.
     *
     * @return string
     */
    private static function _csrf_set_hash(): string
    {
        if ( self::$_csrf_hash !== null )
        {
            return self::$_csrf_hash;
        }

        $cookie = $_COOKIE[self::$_csrf_cookie_name] ?? null;

        if ( is_string($cookie) && self::_csrf_token_valid($cookie) )
        {
            return self::$_csrf_hash = $cookie;
        }

        $nonce = bin2hex(random_bytes(16));

        return self::$_csrf_hash = 'v1.' . $nonce . '.' . self::_csrf_signature($nonce);
    }

    /**
     * Authenticate a nonce for this request's session or authentication identity.
     *
     * @param string $nonce Lowercase hexadecimal nonce
     * @return string Lowercase hexadecimal MAC
     */
    private static function _csrf_signature(string $nonce): string
    {
        $message = "plato-csrf-v1\0"
            . pack('N', strlen(self::$_csrf_binding))
            . self::$_csrf_binding
            . $nonce;

        return hash_hmac('sha256', $message, self::$_csrf_secret);
    }

    /**
     * Validate the token shape, signature, and request binding.
     *
     * @param string $token
     * @return bool
     */
    private static function _csrf_token_valid(string $token): bool
    {
        if ( preg_match(self::CSRF_TOKEN_PATTERN, $token, $matches) !== 1 )
        {
            return false;
        }

        return hash_equals(self::_csrf_signature($matches[1]), $matches[2]);
    }

    /**
     * Ends the request with a 404 when the caller's ip is blacklisted.
     *
     * The whitelist wins, so one address can be let through a blacklisted range.
     *
     * @return void
     */
    public static function ip_filter(): void
    {
        $ip = req::ip();

        if ( !in_array($ip, (array) self::config('ip_blacklist'), true) )
        {
            return;
        }

        if ( in_array($ip, (array) self::config('ip_whitelist'), true) )
        {
            return;
        }

        self::_deny();
    }

    /**
     * Ends the request with a 404 when the caller's country is blacklisted.
     *
     * The ip whitelist wins over the country lists as well: geolocation is a guess, and this is
     * the way to keep a wrongly located address working.
     *
     * @return void
     */
    public static function country_filter(): void
    {
        $country = req::country();

        if ( !in_array($country, (array) self::config('country_blacklist'), true) )
        {
            return;
        }

        if ( in_array($country, (array) self::config('country_whitelist'), true) )
        {
            return;
        }

        if ( in_array(req::ip(), (array) self::config('ip_whitelist'), true) )
        {
            return;
        }

        self::_deny();
    }

    /**
     * Refuse the request as an unresolved route, so the HTTP boundary emits a 404.
     *
     * @codeCoverageIgnore
     * @return void
     */
    private static function _deny(): void
    {
        throw new route_exception([req::path()], 2007);
    }
}
