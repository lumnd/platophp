<?php

/**
 * Response: status, headers, cookies, and the bodies a controller answers with
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\http;

use plato\config;
use plato\plato;
use plato\security\crypt;

/**
 * The answer side of a request.
 *
 * Controllers and middleware prepare a reply; plato::run() emits it at the HTTP boundary. This
 * keeps action return values observable and lets resident workers call plato::handle() without
 * writing to stdout or terminating the process.
 *
 *     resp::json(['id' => 7]);                       // 200, application/json
 *     resp::status(201)->json(['id' => 7]);          // 201
 *     resp::header('X-Trace', $id)->text('ok');      // headers set before the body
 *     resp::redirect('/order/list');                 // 302
 *     resp::download('/tmp/report.csv', 'report.csv');
 *     resp::response(0, ['id' => 7], 'created');     // the classic envelope
 *
 * **Chaining is on purpose and shallow.** status(), header(), headers(), type(), cookie() and
 * no_cache() answer an instance so they can be strung together; the instance's methods are the same
 * ones. There is a single instance behind it, since a process handles one response at a time -- the
 * same reason req:: is static.
 *
 * **Headers are queued, not sent.** They go out when a body method sends, or when send_headers() is
 * called. That makes them readable back through pending() -- which is how a test asserts a header
 * without a web server -- and it means status() after header() is still in time. CR and LF are
 * stripped from every name and value: a header built out of a request value is otherwise a way to
 * inject a second header, or a body.
 *
 * **Encryption follows the request.** When plato\http\envelope accepted an encrypted request it
 * turns encryption on here, and json() / response() encrypt their body with the client's key. The
 * other bodies do not: a downloaded file or a redirect has no envelope to put it in.
 */
class resp
{
    /**
     * json_encode flags json() and encode() default to.
     *
     * Unicode is left alone because an escaped Chinese string is unreadable in a log and longer on
     * the wire, and slashes because \/ in a url helps nobody.
     */
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * Cookie defaults, null until config() reads the cookie section of config/config.php
     *
     * @var array<string, mixed>|null
     */
    private static $_config = null;

    /**
     * Key the body is encrypted with
     *
     * @var string
     */
    private static $_encrypt_key = '';

    /** @var string Purpose authenticated with an encrypted response */
    private static $_encrypt_context = '';

    /** @var bool Whether useful compression may be applied before response encryption */
    private static $_encrypt_compress = true;

    /**
     * Status code to send, 0 to leave whatever the SAPI decided
     *
     * @var int
     */
    private static $_status = 0;

    /**
     * Queued headers, name => value, in the order they were set
     *
     * @var array<string, string>
     */
    private static $_headers = [];

    /**
     * Whether the queued headers have been handed to the SAPI
     *
     * @var bool
     */
    private static $_headers_sent = false;

    /**
     * Whether a body has been written
     *
     * @var bool
     */
    private static $_sent = false;

    /** @var reply|null Response prepared for the current request */
    private static $_reply = null;

    /**
     * The single instance the chainable setters answer.
     *
     * @var self|null
     */
    private static $_instance = null;

    /**
     * The cookie defaults, read on the first call that needs them.
     *
     * @param string|null $key One setting, or null for all of them
     *
     * @return mixed
     */
    public static function config(?string $key = null)
    {
        if ( self::$_config === null )
        {
            self::$_config = (array) config::instance('config')->get('cookie');
        }

        return $key === null ? self::$_config : (self::$_config[$key] ?? null);
    }

    /**
     * Hand the cookie defaults over instead of letting them be read from config/config.php.
     *
     * Merges on top of the file settings, so an override names only what it changes.
     *
     * @param array<string, mixed> $config Same shape as the `cookie` section
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
     * The per request state is left alone -- that is what reset() is for; the cookie defaults are
     * not request scoped.
     *
     * @return void
     */
    public static function reset_config(): void
    {
        self::$_config = null;
    }

    /**
     * The instance the chainable setters return.
     *
     * @return self
     */
    private static function _self(): self
    {
        if ( self::$_instance === null )
        {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    /**
     * Forget the queued status, headers and body flag.
     *
     * A resident process serves more than one request per process, and everything above is per
     * request: without this, the status of the last answer is the status of the next one. Called by
     * whoever owns the request boundary -- the server dispatcher does, between two messages.
     *
     * The encryption settings are cleared as well: they come from the request's envelope, not from
     * the application, and a plaintext request after an encrypted one must not answer in cipher.
     *
     * @return void
     */
    public static function reset()
    {
        self::$_status       = 0;
        self::$_headers      = [];
        self::$_headers_sent = false;
        self::$_sent         = false;
        self::$_reply        = null;
        self::$_encrypt_key      = '';
        self::$_encrypt_context  = '';
        self::$_encrypt_compress = true;
    }

    /**
     * Bind JSON response encryption to the accepted request envelope.
     *
     * Called by plato\http\envelope, not by an application. The key and its authenticated purpose
     * are set together so response encryption can never be enabled with only half its state.
     *
     * @param string $key
     * @param string $context
     * @param bool   $compress
     *
     * @return void
     */
    public static function set_encryption(string $key, string $context, bool $compress = true): void
    {
        self::$_encrypt_key      = $key;
        self::$_encrypt_context  = $context;
        self::$_encrypt_compress = $compress;
    }

    /**
     * Whether a body has already been written.
     *
     * @return bool
     */
    public static function sent(): bool
    {
        return self::$_sent;
    }

    /**
     * The status and headers that have not been sent yet.
     *
     * For tests and for a middleware that wants to know what is about to go out; under the CLI SAPI
     * this is the only way to see them at all, since header() there does nothing.
     *
     * @return array{status: int, headers: array<string, string>, sent: bool}
     */
    public static function pending(): array
    {
        return [
            'status'  => self::$_status,
            'headers' => self::$_headers,
            'sent'    => self::$_sent,
        ];
    }

    /**
     * Put back a snapshot taken with pending().
     *
     * For code that hands control to something which may queue a response and then fail part way
     * through -- error_handler asks the application's error_handle callback to render a page, and
     * a callback that threw after setting a header must not have that header answer for it. Only
     * what pending() reports is restored; the prepared reply and the encryption settings belong to
     * the request rather than to one attempt at answering it.
     *
     * @param array{status?: int, headers?: array<string, string>, sent?: bool} $pending
     *
     * @return void
     */
    public static function restore(array $pending): void
    {
        self::$_status  = (int) ($pending['status'] ?? 0);
        self::$_headers = (array) ($pending['headers'] ?? []);
        self::$_sent    = (bool) ($pending['sent'] ?? false);
    }

    /**
     * The response prepared by the action or middleware, if any.
     */
    public static function prepared(): ?reply
    {
        return self::$_reply;
    }

    /**
     * Set the status code.
     *
     * @param int $code
     *
     * @return self
     */
    public static function status(int $code): self
    {
        self::$_status = $code;

        return self::_self();
    }

    /**
     * Queue a header.
     *
     * @param string $name  Header name; CR / LF are stripped
     * @param string $value Header value; CR / LF are stripped
     *
     * @return self
     */
    public static function header(string $name, string $value): self
    {
        $name = self::_scrub($name);

        if ( $name !== '' )
        {
            self::$_headers[$name] = self::_scrub($value);
        }

        return self::_self();
    }

    /**
     * Queue several headers.
     *
     * @param array<string, string> $headers
     *
     * @return self
     */
    public static function headers(array $headers): self
    {
        foreach ( $headers as $name => $value )
        {
            self::header((string) $name, (string) $value);
        }

        return self::_self();
    }

    /**
     * Set the content type.
     *
     * @param string $mime
     * @param string $charset Appended for the text types that need one, '' to leave it off
     *
     * @return self
     */
    public static function type(string $mime, string $charset = 'utf-8'): self
    {
        return self::header('Content-Type', $charset === '' ? $mime : $mime . '; charset=' . $charset);
    }

    /**
     * Ask every cache in the way to keep nothing.
     *
     * @return self
     */
    public static function no_cache(): self
    {
        return self::headers([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'        => 'no-cache',
            'Expires'       => 'Thu, 01 Jan 1970 00:00:00 GMT',
        ]);
    }

    /**
     * Write a cookie, defaulting to the cookie section of config/config.php.
     *
     * The configured prefix is applied, so that req::cookie() -- which strips it -- reads back what
     * was written here.
     *
     * @param string               $name    Cookie name, without the configured prefix
     * @param string               $value   Value; '' with a past expiry is how one is deleted
     * @param array<string, mixed> $options expires (a unix time) or lifetime (seconds from now),
     *                                      path, domain, secure, httponly, samesite
     *
     * @return self
     */
    public static function cookie(string $name, string $value, array $options = []): self
    {
        $name = self::_scrub($name);

        if ( $name === '' || headers_sent() )
        {
            return self::_self();
        }

        $config = (array) self::config();

        $lifetime = array_key_exists('lifetime', $options)
            ? (int) $options['lifetime']
            : (int) ($config['expire'] ?? 7200);

        setcookie(
            (string) ($config['prefix'] ?? '') . $name,
            $value,
            [
                'expires'  => (int) ($options['expires'] ?? (time() + $lifetime)),
                'path'     => (string) ($options['path'] ?? $config['path'] ?? '/'),
                'domain'   => (string) ($options['domain'] ?? $config['domain'] ?? ''),
                'secure'   => (bool) ($options['secure'] ?? $config['secure'] ?? false),
                'httponly' => (bool) ($options['httponly'] ?? $config['httponly'] ?? true),
                'samesite' => (string) ($options['samesite'] ?? $config['samesite'] ?? 'Lax'),
            ]
        );

        return self::_self();
    }

    /**
     * Delete a cookie.
     *
     * @param string               $name
     * @param array<string, mixed> $options path and domain have to match the ones it was set with
     *
     * @return self
     */
    public static function forget_cookie(string $name, array $options = []): self
    {
        return self::cookie($name, '', ['expires' => time() - 86400] + $options);
    }

    /**
     * Send the queued status and headers, without a body.
     *
     * @return bool  False when the SAPI had already sent them, or when they went out earlier
     */
    public static function send_headers(): bool
    {
        if ( self::$_headers_sent || headers_sent() )
        {
            return false;
        }

        self::$_headers_sent = true;

        if ( self::$_status > 0 )
        {
            http_response_code(self::$_status);
        }

        foreach ( self::$_headers as $name => $value )
        {
            header($name . ': ' . $value);
        }

        return true;
    }

    /**
     * Answer with JSON.
     *
     * @param mixed    $data   Anything json_encode() accepts
     * @param int|null $status Status code, null to keep whatever status() set
     * @param int      $flags  json_encode flags; unicode and slashes are left unescaped by default
     *
     * @return reply
     */
    public static function json($data, ?int $status = null, int $flags = self::JSON_FLAGS): reply
    {
        if ( $status !== null )
        {
            self::status($status);
        }

        if ( !isset(self::$_headers['Content-Type']) )
        {
            self::type('application/json');
        }

        return self::_prepare(self::_maybe_encrypt(self::encode($data, $flags)));
    }

    /**
     * Answer with plain text.
     *
     * @param string   $text
     * @param int|null $status
     *
     * @return reply
     */
    public static function text(string $text, ?int $status = null): reply
    {
        if ( $status !== null )
        {
            self::status($status);
        }

        if ( !isset(self::$_headers['Content-Type']) )
        {
            self::type('text/plain');
        }

        return self::_prepare($text);
    }

    /**
     * Answer with HTML.
     *
     * @param string   $html
     * @param int|null $status
     *
     * @return reply
     */
    public static function html(string $html, ?int $status = null): reply
    {
        if ( $status !== null )
        {
            self::status($status);
        }

        if ( !isset(self::$_headers['Content-Type']) )
        {
            self::type('text/html');
        }

        return self::_prepare($html);
    }

    /**
     * Answer with a body of whatever type was set.
     *
     * @param string      $content
     * @param string|null $mime    Content type, null to keep whatever type() set
     * @param int|null    $status
     *
     * @return reply
     */
    public static function raw(string $content, ?string $mime = null, ?int $status = null): reply
    {
        if ( $mime !== null )
        {
            self::type($mime, '');
        }

        if ( $status !== null )
        {
            self::status($status);
        }

        return self::_prepare($content);
    }

    /**
     * Send a redirect.
     *
     * Only a path or an absolute http(s) url is accepted, and CR / LF are stripped before the header
     * is built: a Location taken from a request parameter is otherwise both a header injection and an
     * open redirect. A value that is neither is refused rather than sent as something else.
     *
     * @param string $url    Path, or an absolute http / https url
     * @param int    $status 302 by default; 301 for a move that is permanent, 303 after a POST
     *
     * @return reply|false False when the url is not one this will send
     */
    public static function redirect(string $url, int $status = 302)
    {
        $url = self::_scrub($url);

        $safe = $url !== ''
            && ($url[0] === '/' || preg_match('#^https?://#i', $url) === 1)
            // A protocol relative url takes the scheme from the page and the host from the value,
            // which makes //evil.example a redirect off site that looks like a path
            && strncmp($url, '//', 2) !== 0;

        if ( !$safe )
        {
            return false;
        }

        self::status($status);
        self::header('Location', $url);

        return self::_prepare('');
    }

    /**
     * Send a file as an attachment.
     *
     * @param string      $path Absolute path of the file
     * @param string|null $name Name the client saves it as, null to use the file's own
     * @param string|null $mime Content type, null for application/octet-stream
     *
     * @return reply|false False when the file cannot be read, so the caller can answer 404 instead
     */
    public static function download(string $path, ?string $name = null, ?string $mime = null)
    {
        if ( !is_file($path) || !is_readable($path) )
        {
            return false;
        }

        $name = self::_scrub($name === null ? basename($path) : $name);
        $name = str_replace(['"', '\\', '/'], '', $name);

        self::type($mime === null ? 'application/octet-stream' : $mime, '');
        self::header('Content-Disposition', self::_disposition($name));
        self::header('Content-Length', (string) filesize($path));

        return self::file($path);
    }

    /**
     * Send the contents of a file as the body, inline.
     *
     * @param string      $path Absolute path of the file
     * @param string|null $mime Content type, null to keep whatever type() set
     *
     * @return reply|false False when the file cannot be read
     */
    public static function file(string $path, ?string $mime = null)
    {
        if ( !is_file($path) || !is_readable($path) )
        {
            return false;
        }

        if ( $mime !== null )
        {
            self::type($mime, '');
        }

        return self::_prepare(static function () use ($path): void
        {
            // readfile() rather than file_get_contents(): a file bigger than memory_limit should
            // still be answerable.
            readfile($path);
        });
    }

    /**
     * Send a body a callable produces.
     *
     * The headers go out first, then the callable writes with echo, and nothing on this side buffers
     * what it wrote -- which is why there is no Content-Length here and why a long export can be
     * answered without holding it in memory.
     *
     * Whether each chunk leaves the server as it is written is the host's business, not this
     * package's: an output buffer the host opened -- php.ini output_buffering, an ob_start() in the
     * entry file -- belongs to the host, and flushing it from in here would also flush whatever else
     * it was holding. A writer that needs its chunks out one at a time says so itself:
     *
     *     resp::stream(function ()
     *     {
     *         foreach ( $rows as $row )
     *         {
     *             echo $row . "\n";
     *             ob_get_level() && ob_flush();
     *             flush();
     *         }
     *     }, 'text/csv');
     *
     * @param callable    $writer
     * @param string|null $mime
     * @param int|null    $status
     *
     * @return reply
     */
    public static function stream(callable $writer, ?string $mime = null, ?int $status = null): reply
    {
        if ( $mime !== null )
        {
            self::type($mime, '');
        }

        if ( $status !== null )
        {
            self::status($status);
        }

        return self::_prepare(\Closure::fromCallable($writer));
    }

    /**
     * The classic envelope, for an application built on it: `{code, msg, data, timestamp}`.
     *
     * @param int    $code Application status; 0 is success and anything else is a failure
     * @param mixed  $data Payload
     * @param string $msg  Human readable status
     *
     * @return reply
     */
    public static function response($code = 0, $data = [], $msg = 'successful'): reply
    {
        if ( !isset(self::$_headers['Content-Type']) )
        {
            self::type('application/json');
        }

        $body = self::_maybe_encrypt(self::encode([
            'code'      => (int) $code,
            'msg'       => (string) $msg,
            'data'      => $data,
            'timestamp' => plato::timestamp(),
        ]));

        return self::_prepare($body);
    }

    /**
     * The envelope for a failure.
     *
     * @param int    $code
     * @param string $msg
     *
     * @return reply
     */
    public static function response_error($code = -1, $msg = 'faild'): reply
    {
        return self::response($code, [], $msg);
    }

    /**
     * The framework's own answer to a request it will not fulfil.
     *
     * This is the reply an application that configured nothing gets: a 401 from an action declaring
     * `auth = required` that nobody was signed in for, a 403 from a failed csrf check, and whatever
     * status an uncaught exception resolved to. All three used to answer differently -- the routing
     * failures negotiated on Accept while the 401 and the 403 returned a fixed HTML page, so a JSON
     * client that failed csrf got a document it could not decode -- and going through one method is
     * what stops them from drifting apart again.
     *
     * The body is JSON for a request that asked for JSON and text/plain otherwise. Deliberately not
     * HTML: an error page is a design decision, and the framework has no template of the
     * application's to render one with. Applications wanting one return their own reply from the
     * `error_handle` callback or from the authorisation callback.
     *
     * @param int         $status HTTP status
     * @param string|null $detail Message; null for the standard reason phrase of $status. A caller
     *                            passing detail has decided the reader is allowed to see it
     *
     * @return reply
     */
    public static function error(int $status, ?string $detail = null): reply
    {
        $detail = $detail ?? self::_status_message($status);

        if ( req::is_json() )
        {
            return self::status($status)
                ->type('application/json')
                ->response_error(-$status, $detail);
        }

        return self::status($status)
            ->type('text/plain')
            ->text($detail);
    }

    /**
     * The reason phrase shown for a status when the caller has nothing safer to say.
     *
     * Only the statuses the framework itself answers with are named; anything else an application
     * passes gets the generic phrase rather than a table of every code in the registry.
     */
    private static function _status_message(int $status): string
    {
        return [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            500 => 'Internal Server Error',
        ][$status] ?? 'Request Failed';
    }

    /**
     * json_encode with the float precision PHP's default gets wrong.
     *
     * serialize_precision defaults to 17 in some builds, which turns 0.1 into 0.100000000000000006.
     * It is set for the encode and put back afterwards: this package is installed into somebody
     * else's project and does not get to change their ini for the rest of the request.
     *
     * @param mixed $data
     * @param int   $flags
     *
     * @return string
     */
    public static function encode($data, int $flags = self::JSON_FLAGS): string
    {
        $precision = ini_get('serialize_precision');

        if ( $precision !== false && $precision !== '-1' )
        {
            ini_set('serialize_precision', '-1');
        }

        try
        {
            return json_encode($data, $flags | JSON_THROW_ON_ERROR);
        }
        finally
        {
            if ( $precision !== false && $precision !== '-1' )
            {
                ini_set('serialize_precision', $precision);
            }
        }
    }

    /**
     * Encrypt a body when the request came in encrypted.
     *
     * @param string $body
     *
     * @return string
     */
    private static function _maybe_encrypt(string $body): string
    {
        if ( self::$_encrypt_key === '' )
        {
            return $body;
        }

        self::type(crypt::MEDIA_TYPE, '');

        return crypt::encode(
            $body,
            self::$_encrypt_key,
            self::$_encrypt_context,
            self::$_encrypt_compress
        );
    }

    /**
     * Emit one prepared response at the HTTP boundary.
     */
    public static function send(reply $reply): void
    {
        // Outer middleware may use the chainable resp setters after the action prepared its reply.
        // Those later values take precedence, while a directly constructed reply still supplies
        // everything the static response state does not hold.
        self::$_status  = self::$_status > 0 ? self::$_status : $reply->status();
        self::$_headers = array_replace($reply->headers(), self::$_headers);
        self::send_headers();
        self::$_sent = true;

        $reply->emit_body();
    }

    /**
     * Build the current response without writing it.
     *
     * @param string|\Closure $content
     */
    private static function _prepare($content): reply
    {
        self::$_reply = new reply(
            self::$_status > 0 ? self::$_status : 200,
            self::$_headers,
            $content
        );

        return self::$_reply;
    }

    /**
     * A Content-Disposition value that survives a non ASCII file name.
     *
     * The plain filename is what every client understands and has to stay ASCII; filename* carries
     * the real one for those that read RFC 5987, which is all of them since IE11.
     *
     * @param string $name
     *
     * @return string
     */
    private static function _disposition(string $name): string
    {
        // A run of non ASCII bytes becomes one underscore, not one per byte: a name in Chinese would
        // otherwise turn into three underscores per character
        $ascii = preg_replace('/[^\x20-\x7e]+/', '_', $name);
        $ascii = $ascii === null || $ascii === '' ? 'download' : $ascii;

        $value = 'attachment; filename="' . $ascii . '"';

        if ( $ascii !== $name )
        {
            $value .= "; filename*=UTF-8''" . rawurlencode($name);
        }

        return $value;
    }

    /**
     * Take the line breaks out of a header name, value or url.
     *
     * @param string $value
     *
     * @return string
     */
    private static function _scrub(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], '', $value));
    }
}
