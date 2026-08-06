<?php

/**
 * Request input: the one place the superglobals are read
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\http;

use plato\config;
use plato\cli;
use plato\cast;
use plato\arr;

/**
 * Everything that arrived with the request, bar the files.
 *
 * **$_GET, $_POST and $_REQUEST are read here and nowhere else**, and capture() empties them
 * afterwards. That is the point of the class: one place decides what an incoming value looks like
 * by the time anything reads it, so a filtering or escaping policy cannot be half applied.
 * Code outside this file asks through the accessors below.
 *
 * Uploads live in plato\http\upload, which owns $_FILES: it is a different shape from every other
 * input, and it is the only one a controller consumes by moving something rather than by reading a
 * value. capture() hands it over and reset_input() clears it, so the request boundary is shared.
 *
 *     req::get('page', 1, 'int');        // only ever from the query string
 *     req::post('name');                 // only ever from the body
 *     req::item('id', 0, 'int');         // query string or request body
 *     req::json('order.items');          // a json body, dotted paths through arr::get()
 *     req::header('Content-Type');       // request headers, case insensitively
 *
 * Each accessor takes a cast type, applied by plato\cast -- 'int', 'email' and the rest, all of
 * them coercions and none of them escaping. $throw_error decides whether an invalid value raises
 * or comes back as ''.
 *
 * **Where the parameters come from is the request method and the content type, not a guess.**
 * A form encoded POST lands in $posts, a json body in $jsons *and* in the set named by the method,
 * an xml body in $xmls, and PUT / PATCH / DELETE bodies -- which PHP itself does not parse -- are
 * parsed here into $puts / $patchs / $deletes.
 *
 * **Routing does not read any of this.** plato\http\route works from the request path alone, so a
 * body cannot disagree with the path about where the request is headed.
 *
 * **One process, one request at a time.** The parameter sets are static, which is the same
 * assumption the rest of the framework makes. A resident entry point (Workerman, Swoole) calls
 * reset_input() or set_raw() and then capture() on each new request; see capture().
 */
class req
{
    /**
     * The `request` section of config/config.php, null until config() reads it
     *
     * @var array<string, mixed>|null
     */
    private static $_config = null;

    /**
     * Request methods that have a parameter set of their own, lower cased.
     *
     * _hydrate() stores a parsed body in `self::${$method . 's'}`, so a method missing from here
     * has nowhere to put one -- HEAD, OPTIONS and CLI reach for $heads / $optionss / $clis, and
     * assigning to a static property that does not exist is a fatal Error, not a notice.
     *
     * @var array<int, string>
     */
    private const BODY_SETS = ['get', 'post', 'put', 'patch', 'delete'];

    /** @var array<array-key, mixed> $_COOKIE, copied rather than moved: it is not emptied afterwards */
    public static $cookies = array();

    /** @var array<array-key, mixed> $gets and $posts together */
    public static $forms = array();

    /** @var array<array-key, mixed> */
    public static $gets = array();

    /** @var array<array-key, mixed> */
    public static $posts = array();

    /** @var array<array-key, mixed> Body of a PUT, parsed here because PHP does not parse it */
    public static $puts = array();

    /** @var array<array-key, mixed> Body of a DELETE */
    public static $deletes = array();

    /** @var array<array-key, mixed> Body of a PATCH */
    public static $patchs = array();

    /** @var array<array-key, mixed> Request body decoded as json */
    public static $jsons = array();

    /** @var array<array-key, mixed> Request body decoded as xml */
    public static $xmls = array();

    /** @var string|null Cached php://input, null until first read */
    protected static $_raw = null;

    /**
     * Request headers, null until headers() puts them together.
     *
     * A class property so reset_input() can drop it at the resident request boundary.
     *
     * @var array<string, string>|null
     */
    protected static $_headers = null;

    /**
     * Whether a value the cast rejects raises, or comes back as ''.
     *
     * Only the types that can fail -- email, ip -- have anything to reject.
     *
     * @var bool
     */
    public static $throw_error = false;

    /**
     * The `request` settings, read on the first call that needs them.
     *
     * @param string|null $key One setting, or null for all of them
     *
     * @return mixed
     */
    public static function config(?string $key = null)
    {
        if ( self::$_config === null )
        {
            self::$_config = (array) config::instance('config')->get('request');
        }

        return $key === null ? self::$_config : (self::$_config[$key] ?? null);
    }

    /**
     * Hand the request its settings instead of letting it read config/config.php.
     *
     * Merges on top of the file settings, so an override names only what it changes.
     *
     * @param array<string, mixed> $config Same shape as the `request` section
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
     * reset_config(), not reset(): reset_input() already means something else in this class.
     *
     * @return void
     */
    public static function reset_config()
    {
        self::$_config = null;
    }

    /**
     * Read the current request into the static parameter sets.
     *
     * GET and POST values are moved into self::$forms and the superglobals are emptied; cookies are
     * copied into self::$cookies and $_COOKIE is left where it is.
     *
     * plato::_bootstrap() calls this once during bootstrap. It is public, and safe to call again,
     * because an entry point that serves many requests from one process -- Workerman, Swoole --
     * has to start each of them from a clean slate. plato::run() covers the same ground for the
     * payload it is handed, through reset_input() and assign_values().
     *
     * A raw body that is already known -- read earlier in this request, or handed over with
     * set_raw() -- survives the reset, because php://input does not read back a second time under
     * every sapi. A resident entry point therefore calls set_raw() with the new body, or
     * reset_input() to drop the old one, before calling this.
     *
     * Invalid bodies may raise request_exception. The global error handler does not call capture(),
     * so it can safely turn that failure into a 400 response.
     *
     * The settings are no longer re-read here. They come from a file that does not change between
     * two requests of one process, and re-reading them wiped whatever configure() had been given.
     *
     * @return void
     */
    public static function capture()
    {
        $raw = self::$_raw;
        self::reset_input();
        self::$_raw = $raw;

        self::_hydrate();
    }

    /**
     * Returns PHP's raw input.
     *
     * Cached: the body is read during _hydrate() and again by plato\http\envelope when the router
     * asks whether the request is an encrypted envelope, and php://input is not re-readable
     * under every sapi.
     *
     * @return  string
     */
    public static function raw()
    {
        if ( self::$_raw === null )
        {
            self::$_raw = (string) file_get_contents('php://input');
        }

        return self::$_raw;
    }

    /**
     * Supply the raw request body.
     *
     * For entry points that do not get their body from php://input -- Workerman and Swoole hand
     * it over as a string -- and for tests.
     *
     * @param  string $raw
     * @return void
     */
    public static function set_raw($raw)
    {
        self::$_raw = (string) $raw;
    }

    /**
     * Replace every request parameter with the given set.
     *
     * Used by plato\http\envelope: the decoded payload becomes the whole parameter set rather
     * than being merged into whatever arrived alongside the ciphertext.
     *
     * @param  array<string, mixed> $data
     * @return void
     */
    public static function replace_input(array $data)
    {
        self::$forms = self::$posts = $data;
        self::$gets  = self::$puts = self::$patchs = self::$deletes = self::$jsons = self::$xmls = [];
    }

    /**
     * Forget the current request.
     *
     * Long running entry points -- Workerman, Swoole -- reuse one process for many requests and
     * have to call this between them, otherwise the previous request leaks into the next one.
     *
     * @return void
     */
    public static function reset_input()
    {
        self::$forms   = self::$gets = self::$posts = self::$cookies = [];
        self::$puts    = self::$patchs = self::$deletes = [];
        self::$jsons   = self::$xmls = [];
        self::$_raw    = null;
        self::$_headers = null;

        // The uploads are this request's too, and their temporary files are gone with it
        upload::reset();
    }

    /**
     * Returns all of the GET, POST, PUT, PATCH or DELETE array's
     *
     * @return  array
     */
    public static function all()
    {
        return array_merge(self::$gets, self::$posts, self::$puts, self::$patchs, self::$deletes);
    }

    /**
     * A parameter from any method's parameter set.
     *
     * @param  mixed  $index       Key, dotted paths allowed; omit for the whole set
     * @param  mixed  $default
     * @param  string $filter_type A plato\cast type, '' for none
     * @return mixed
     */
    public static function item($index = null, $default = null, $filter_type = '')
    {
        $value = (func_num_args() === 0) ? self::all() : arr::get(self::all(), $index, $default);
        return cast::to($value, $filter_type, self::$throw_error);
    }

    /**
     * Gets the specified GET variable.
     *
     * @param   string  $index    The index to get
     * @param   string  $default  The default value
     * @return  string|array
     */
    public static function get($index = null, $default = null, $filter_type = '')
    {
        $value = (func_num_args() === 0) ? self::$gets : arr::get(self::$gets, $index, $default);
        return cast::to($value, $filter_type, self::$throw_error);
    }

    /**
     * Gets the specified POST variable.
     *
     * @param   string  $index    The index to get
     * @param   string  $default  The default value
     * @return  string|array
     */
    public static function post($index = null, $default = null, $filter_type = '')
    {
        $value = (func_num_args() === 0) ? self::$posts : arr::get(self::$posts, $index, $default);
        return cast::to($value, $filter_type, self::$throw_error);
    }

    /**
     * Fetch an item from the php://input for put arguments
     *
     * @param   string  $index    The index key
     * @param   mixed   $default  The default value
     * @return  string|array
     */
    public static function put($index = null, $default = null, $filter_type = '')
    {
        $value = (func_num_args() === 0) ? self::$puts : arr::get(self::$puts, $index, $default);
        return cast::to($value, $filter_type, self::$throw_error);
    }

    /**
     * Fetch an item from the php://input for patch arguments
     *
     * @param   string  $index    The index key
     * @param   mixed   $default  The default value
     * @return  string|array
     */
    public static function patch($index = null, $default = null, $filter_type = '')
    {
        $value = (func_num_args() === 0) ? self::$patchs : arr::get(self::$patchs, $index, $default);
        return cast::to($value, $filter_type, self::$throw_error);
    }

    /**
     * Fetch an item from the php://input for delete arguments
     *
     * @param   string  $index    The index key
     * @param   mixed   $default  The default value
     * @return  string|array
     */
    public static function delete($index = null, $default = null, $filter_type = '')
    {
        $value = (func_num_args() === 0) ? self::$deletes : arr::get(self::$deletes, $index, $default);
        return cast::to($value, $filter_type, self::$throw_error);
    }

    /**
     * Get the request body interpreted as JSON.
     *
     * @param   mixed  $index
     * @param   mixed  $default
     * @return  array  parsed request body content.
     */
    public static function json($index = null, $default = null, $filter_type = '')
    {
        $value = (func_num_args() === 0) ? self::$jsons : arr::get(self::$jsons, $index, $default);
        return cast::to($value, $filter_type, self::$throw_error);
    }

    /**
     * Get the request body interpreted as XML.
     *
     * @param   mixed  $index
     * @param   mixed  $default
     * @return  array  parsed request body content.
     */
    public static function xml($index = null, $default = null, $filter_type = '')
    {
        $value = (func_num_args() === 0) ? self::$xmls : arr::get(self::$xmls, $index, $default);
        return cast::to($value, $filter_type, self::$throw_error);
    }

    /**
     * @param  mixed  $index
     * @param  mixed  $default
     * @param  string $filter_type
     * @return mixed
     */
    public static function cookie($index = null, $default = null, $filter_type = '')
    {
        $value = (func_num_args() === 0) ? self::$cookies : arr::get(self::$cookies, $index, $default);
        return cast::to($value, $filter_type, self::$throw_error);
    }

    /**
     * A $_SERVER value. Unlike the parameter sets this is read live: $_SERVER is process state
     * rather than request input, and nothing here empties it.
     *
     * @param   string|null $index   Case insensitive
     * @param   mixed       $default
     * @return  mixed
     */
    public static function server($index = null, $default = null)
    {
        return (func_num_args() === 0) ? $_SERVER : arr::get($_SERVER, strtoupper($index), $default);
    }

    /**
     * A request header, by name, case insensitively.
     *
     * Read once per request and kept in self::$_headers, which reset_input() clears.
     *
     * @param   mixed $index
     * @param   mixed $default
     * @return  mixed
     */
    public static function headers($index = null, $default = null)
    {
        if ( self::$_headers === null )
        {
            self::$_headers = [];

            // getallheaders() is an apache function; under fcgi and nginx the headers have to be
            // put back together from the HTTP_ prefixed $_SERVER keys
            if ( ! function_exists('getallheaders') )
            {
                $server = arr::filter_prefixed(static::server(), 'HTTP_', true);

                foreach ( $server as $key => $value )
                {
                    $key = join('-', array_map('ucfirst', explode('_', strtolower($key))));

                    self::$_headers[$key] = $value;
                }

                // These two arrive without the HTTP_ prefix, so the loop above misses them
                $value = static::server('Content_Type') and self::$_headers['Content-Type'] = $value;
                $value = static::server('Content_Length') and self::$_headers['Content-Length'] = $value;
            }
            else
            {
                self::$_headers = (array) getallheaders();
            }
        }

        $headers = self::$_headers;

        return empty($headers) ? $default : ((func_num_args() === 0) ? $headers : arr::get(array_change_key_case($headers), strtolower($index), $default));
    }

    /**
     * The language tag the client asked for.
     *
     * A `language` cookie wins, so an application that lets a visitor choose only has to write
     * one; otherwise the first entry of Accept-Language, lower cased and with the q values
     * stripped. '' when the client said nothing.
     *
     * **Which languages exist is not decided here.** The tag is reported as the client stated it,
     * and an application feeds it to whatever translator it uses.
     *
     * The value is client supplied. A caller that builds a path or a file name out of it has to
     * validate it first.
     *
     * @return string
     */
    public static function language()
    {
        if ( $lang = self::cookie("language") )
        {
            return $lang;
        }

        $accept = (string) self::server('HTTP_ACCEPT_LANGUAGE', '');

        if ( $accept === '' )
        {
            return '';
        }

        // explode() always answers at least one element, so there is nothing to fall back to
        $languages = explode(',', (string) preg_replace('/(;\s?q=[0-9\.]+)|\s/i', '', strtolower(trim($accept))));

        return $languages[0];
    }

    /**
     * Return's the query string
     *
     * @param   string $default
     * @return  string
     */
    public static function query_string($default = '')
    {
        return static::server('QUERY_STRING', $default);
    }

    /**
     * Return's the host
     *
     * @param   string $default
     * @return  string
     */
    public static function host($default = '')
    {
        return static::server('HTTP_HOST', $default);
    }

    /**
     * Return's the port
     *
     * @param   string $default
     * @return  string
     */
    public static function port($default = '')
    {
        return static::server('SERVER_PORT', $default);
    }

    /**
     * Return's the remote port
     *
     * @param   string $default
     * @return  string
     */
    public static function remote_port($default = '')
    {
        return static::server('REMOTE_PORT', $default);
    }

    /**
     * The scheme the request came in on.
     *
     * X-Forwarded-Proto / X-Forwarded-Port are believed, so this is only trustworthy behind a
     * proxy that overwrites them. Directly exposed, a client can claim https for itself.
     *
     * @return  string  http | https
     */
    public static function protocol()
    {
        if ( static::server('HTTPS') == 'on' or
            static::server('HTTPS') == 1 or
            static::server('SERVER_PORT') == 443 or
            static::server('HTTP_X_FORWARDED_PROTO') == 'https' or
            static::server('HTTP_X_FORWARDED_PORT') == 443 )
        {
            return 'https';
        }

        return 'http';
    }

    /**
     * Scheme and host, https://www.platophp.com.
     *
     * @param  mixed $domain Ignored
     * @return string
     */
    public static function domain($domain = null)
    {
        return self::protocol() . '://' . self::host();
    }

    /**
     * The current address without its query string, https://www.platophp.com/order/list.
     *
     * @param  string $uri      Returned appended to the origin instead, when given
     * @param  mixed  $protocol Ignored
     * @return string
     */
    public static function base_url($uri = '', $protocol = null)
    {
        if ( $uri )
        {
            return self::domain() . $uri;
        }

        $url = self::url();
        $at  = strpos($url, '?');

        // strpos() and not a truthiness test: a '?' at offset 0 is falsy, and while url() cannot
        // produce one now, the test being wrong is how this lost the path in the first place
        return $at === false ? $url : substr($url, 0, $at);
    }

    /**
     * The whole current address, https://www.platophp.com/order/list?page=2.
     *
     * @return string
     */
    public static function url()
    {
        return self::domain() . self::path();
    }

    /**
     * The request path with its query string, /index.php?ct=test&ac=demo&id=10.
     *
     * @return string
     */
    public static function path()
    {
        if ( !empty(self::server("REQUEST_URI")) )
        {
            $script_name = self::server("REQUEST_URI");
            $nowurl = $script_name;
        }
        else
        {
            $script_name = self::server("PHP_SELF");
            $nowurl = empty(self::server("QUERY_STRING")) ? $script_name : $script_name . "?" . self::server("QUERY_STRING");
        }
        return $nowurl;
    }

    /**
     * Return's the referrer
     *
     * @param   string $default
     * @return  string
     */
    public static function referrer($default = '')
    {
        return static::server('HTTP_REFERER', $default);
    }

    /**
     * The peer address, straight from REMOTE_ADDR.
     *
     * X-Forwarded-For is deliberately not consulted: it is client supplied, and a client that
     * sends its own is otherwise free to pick the address a rate limiter or an audit log records.
     * Behind a proxy, resolve the real address from the header the proxy is known to overwrite.
     *
     * @param   string $default
     * @return  mixed
     */
    public static function ip($default = '0.0.0.0')
    {
        return static::server('REMOTE_ADDR', $default);
    }

    /**
     * The two letter country code the edge said this request came from.
     *
     * Never resolved here. The web server or trusted edge writes COUNTRY_SHORT as a server variable.
     * An HTTP_* value is deliberately not accepted because a directly connected client can supply
     * any request header unless a trusted proxy is known to overwrite it.
     *
     * Client-controlled request parameters are never accepted as geographic evidence.
     *
     * @return string  '-' when nothing upstream said, which is not the same as "not blocked" --
     *                 a caller that refuses traffic on this has to decide what '-' means
     */
    public static function country(): string
    {
        $country = strtoupper((string) self::server('COUNTRY_SHORT', ''));

        return preg_match('/^[A-Z]{2}$/D', $country) === 1 ? $country : '-';
    }

    /**
     * Return's the user agent
     *
     * @param   string $default
     * @return  mixed
     */
    public static function user_agent($default = '')
    {
        return static::server('HTTP_USER_AGENT', $default);
    }

    /**
     * Whether the client wants json back.
     *
     * Three ways of saying so, in order: an `is_json` request parameter, the request's own content
     * type, and Accept. Accept is what the client wants to receive and Content-Type is what it
     * sent, so they are different questions -- both are taken as a yes because a client that posts
     * json almost always wants json back.
     *
     * @return bool
     */
    public static function is_json()
    {
        if ( self::item('is_json', 0, 'int') )
        {
            return true;
        }

        // Through headers(), not req::server('HTTP_CONTENT_TYPE'): php-fpm puts the request's own
        // content type in CONTENT_TYPE without the HTTP_ prefix, so that key is normally absent
        if ( self::content_type() === 'application/json' )
        {
            return true;
        }

        // Accept is a comma separated list and every entry may carry its own parameters, so it is
        // split on the commas first -- cutting at the first ';' threw away every entry after the
        // first one that had a q value
        foreach ( explode(',', (string) self::server('HTTP_ACCEPT')) as $accept )
        {
            if ( self::_bare_mime($accept) === 'application/json' )
            {
                return true;
            }
        }

        return false;
    }

    /**
     * The request's own content type, without any parameters: 'application/json' for a
     * 'application/json; charset=utf-8' header.
     *
     * @return string  '' when the request declared none
     */
    public static function content_type()
    {
        return self::_bare_mime((string) self::headers('Content-Type', ''));
    }

    /**
     * A media type with its parameters and casing taken off.
     *
     * @param  string $value
     * @return string
     */
    private static function _bare_mime($value)
    {
        return body::bare_mime($value);
    }

    public static function is_cli()
    {
        return self::method() === 'CLI';
    }

    /**
     * Whether the request announced itself as XMLHttpRequest.
     *
     * jQuery sets X-Requested-With on its own; hand written fetch() and XMLHttpRequest calls have
     * to set it themselves, so a false answer does not mean the caller was a browser navigation.
     *
     * @return bool
     */
    public static function is_ajax()
    {
        return (static::server('HTTP_X_REQUESTED_WITH') !== null) and strtolower(static::server('HTTP_X_REQUESTED_WITH')) === 'xmlhttprequest';
    }

    /**
     * Request method: GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS or CLI.
     *
     * Delegates to the router, which is authoritative once the route has been resolved. The router
     * honours X-HTTP-Method-Override only when route.method_override is on, and only promotes POST
     * to PUT, PATCH, or DELETE.
     *
     * @return string
     */
    public static function method()
    {
        if ( PHP_SAPI === 'cli' )
        {
            return 'CLI';
        }

        if ( route::is_resolved() )
        {
            return route::method();
        }

        // _hydrate() needs the method before the route exists, so work it out from the request.
        // $_SERVER is passed in rather than read through self::server() to avoid re-entering
        // this class while it is still initialising.
        return route::detect_method($_SERVER);
    }

    /**
     * The page to go back to: the referrer, or the given fallback when there is none.
     *
     * @param string $gourl Fallback
     * @return mixed  Client supplied when it comes from the referrer, so it is not safe to put in
     *                a Location header as it stands -- resp::redirect() refuses anything that is
     *                not a path or an absolute http url
     */
    public static function back_url($gourl = '')
    {
        $gourl = empty(self::server('HTTP_REFERER')) ? $gourl : self::server('HTTP_REFERER');
        return $gourl;
    }

    /**
     * Remember where to go after the next round trip, in a `gourl` cookie.
     *
     * Written with setcookie() directly rather than through resp::cookie(), so it carries none of
     * the configured cookie defaults -- no prefix, no httponly, no samesite, session lifetime.
     * redirect() reads it back under the bare name accordingly.
     *
     * @param string $gourl
     *
     * @return void
     */
    public static function set_redirect($gourl = '')
    {
        $gourl = urlencode($gourl);
        setcookie('gourl', $gourl);
    }

    /**
     * Where set_redirect() said to go, falling back to the referrer.
     *
     * @param string $gourl Fallback
     *
     * @return string
     */
    public static function redirect($gourl = '')
    {
        $gourl = self::cookie('gourl', $gourl, 'urldecode');
        $gourl = $gourl ?: self::referrer();
        return $gourl;
    }

    /**
     * Add parameters to the current request, for an entry point that carries its own payload --
     * a CLI command or a server message rather than an http request.
     *
     * Merges rather than replaces; plato\http\envelope uses replace_input() where the payload has
     * to be the only source.
     *
     * @param  array<string, mixed> $data
     * @param  string               $method Which set to add to: GET goes to $gets, anything else
     *                                      to $posts
     *
     * @return void
     */
    public static function assign_values(array &$data, $method = 'GET')
    {
        foreach ( $data as $k => $v )
        {
            self::$forms[$k] = $v;

            if ( strtoupper($method) == 'GET' )
            {
                self::$gets[$k] = $v;
            }
            else
            {
                self::$posts[$k] = $v;
            }
        }
    }

    /**
     * Fill the parameter sets from the request.
     *
     * The body is parsed by plato\http\body, because PHP only does it itself for a form encoded
     * POST: a json body, an xml body, and a PUT / PATCH / DELETE of any type all arrive as nothing
     * but php://input. What is left here is where the result goes -- which is this class' subject,
     * and the parsing is not.
     *
     * @return  void
     */
    protected static function _hydrate()
    {
        $method = strtolower(self::method());

        // The whole header, parameters included: the multipart branch needs the boundary out of it
        $content_header = (string) self::headers('Content-Type', '');

        // Encrypted bodies are resolved by plato\http\envelope at the configured route entry path.
        $parsed    = body::parse(self::raw(), $content_header, $method);
        $php_input = $parsed['data'];

        if ( $parsed['xml'] !== null )
        {
            self::$xmls = $parsed['xml'];
        }

        if ( $parsed['json'] !== null )
        {
            self::$jsons = $parsed['json'];

            // Also in the set named by the method, so req::post() finds a json POST body. Only
            // for the methods that have a set: HEAD, OPTIONS and CLI do not, and writing
            // self::$heads is a fatal "access to undeclared static property". Their body is
            // still readable through req::json()
            if ( in_array($method, self::BODY_SETS, true) )
            {
                self::${$method . 's'} = $parsed['json'];
            }

            $_REQUEST = array_merge($parsed['json'], $_REQUEST);
        }

        if ( !$parsed['known'] )
        {
            // Nothing understood this content type, so the body is still the raw string. Clear the
            // method so it is not stored under it below, and leave it for the application to read
            // through raw()
            $method = null;
        }

        if ( $method === 'cli' )
        {
            // Named command line arguments stand in for the query string, so a controller action
            // reads `--id=10` through req::get('id') exactly as it would over http
            if ( count(cli::$args) > 0 )
            {
                foreach ( cli::$args as $k => $v )
                {
                    if ( !is_numeric($k) )
                    {
                        $_GET[$k] = $v;
                    }
                }
            }
        }

        if ( !empty($_FILES) )
        {
            upload::capture($_FILES);
        }

        if ( count($_GET) > 0 )
        {
            self::$gets = $_GET;
        }

        if ( count($_POST) > 0 )
        {
            self::$posts = $_POST;
        }

        if ( count($_COOKIE) > 0 )
        {
            self::$cookies = $_COOKIE;
        }

        // The union of gets and posts, rather than a copy of $_REQUEST: what $_REQUEST holds
        // depends on the request_order ini setting, which this package does not get to set
        if ( self::$gets || self::$posts )
        {
            self::$forms = array_merge(self::$gets, self::$posts);
        }

        // Emptied so that anything reading a superglobal directly gets nothing, rather than
        // getting a value that skipped the filtering below
        $_GET = $_POST = $_REQUEST = [];

        // store the parsed data based on the request method
        if ( $php_input && ($method == 'put' or $method == 'patch' or $method == 'delete') )
        {
            self::${$method . 's'} = !is_array($php_input) ? json_decode($php_input, true) : $php_input;
        }

        // Fields whose name carries the application's base64 upload flag are moved out of $posts
        // and into plato\http\upload: they hold a whole encoded file, and leaving them in the
        // parameter sets puts the file into every request log line that dumps them
        if ( defined('BASE64_UPLOAD_FLAG') && BASE64_UPLOAD_FLAG )
        {
            $flag_len = strlen(BASE64_UPLOAD_FLAG);
            foreach ( self::$posts as $field => $value )
            {
                if ( substr($field, 0, $flag_len) === BASE64_UPLOAD_FLAG )
                {
                    $is_base64 = is_array($value);
                    if ( $is_base64 )
                    {
                        foreach ( $value as $k => $v )
                        {
                            if ( !is_numeric($k) || !is_string($v) )
                            {
                                $is_base64 = false;
                                break;
                            }
                        }
                    }

                    $real_field = substr($field, $flag_len);
                    if ( $is_base64 )
                    {
                        upload::set($real_field, $value);
                    }
                    else
                    {
                        self::$posts[$real_field] = !empty(self::$posts[$field][0]['value']) ?
                        self::$posts[$field][0]['value'] : $value;
                    }

                    unset(self::$posts[$field], self::$forms[$field]);
                }
            }
        }
    }

    /**
     * Flatten a SimpleXMLElement tree into nested arrays.
     *
     * Lives in plato\http\body now, together with the rest of the body parsing; this stays because
     * it is a public call an application may already be making.
     *
     * @param  iterable<string, \SimpleXMLElement> $xmls
     * @return array<string, mixed>
     */
    public static function xml_to_array($xmls)
    {
        return body::xml_to_array($xmls);
    }
}
