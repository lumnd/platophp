<?php

/**
 * HTTP client: one request, a retry policy, a middleware stack and a concurrent pool
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\http;

use plato\log;
use RuntimeException;

/**
 * The outbound HTTP client, on curl.
 *
 *     $client = new client(['base_uri' => 'https://example.com']);
 *     $r = $client->get('https://example.com/users', ['query' => ['page' => 2]]);
 *     $r->ok();  $r->status();  $r->json();  $r->header('content-type');
 *
 *     $client->post('https://example.com/users', ['name' => 'plato']);      // form encoded
 *     $client->post('https://example.com/users', ['json' => ['name' => 'plato']]);
 *     $client->request('PATCH', $url, ['json' => $body, 'timeout' => 5]);
 *
 *     // Several at once; the results keep the keys of the input
 *     $results = $client->pool(['a' => ['url' => $one], 'b' => ['url' => $two]]);
 *
 * **PSR-18 shaped, not PSR-18.** The interface names would need `psr/http-client` and
 * `psr/http-message` as hard requirements, and a framework that pins a host project's major version
 * of PSR-7 makes itself uninstallable beside half of packagist. What is borrowed is the shape: one
 * `request()` that everything else goes through, a client_response value object, a middleware
 * stack around it, and nothing thrown for an HTTP status the caller might have expected.
 *
 * ### Options
 *
 *     url              Target; required unless it is the first argument
 *     method           GET by default, POST when a body is given
 *     query            Array merged into the query string
 *     body             Raw request body
 *     form             Array, sent as application/x-www-form-urlencoded
 *     json             Anything json_encode() takes, sent as application/json
 *     headers          name => value, or a list of 'Name: value' strings
 *     timeout          Whole request, seconds; 15 by default
 *     connect_timeout  Connection only, seconds; 5 by default
 *     retries          Extra attempts after the first; 0 by default
 *     retry_on         Status codes worth another attempt; 429, 500, 502, 503, 504 by default
 *     backoff_ms       Wait before each retry; [200, 1000, 3000] by default, last value repeats
 *     verify           Verify the TLS peer; **true** by default
 *     follow           Follow redirects; true by default
 *     max_redirects    10 by default
 *     proxy            Proxy address
 *     cookie           Cookie header
 *     cookie_file      File to read cookies from
 *     save_cookie      File to write cookies to
 *     user_agent       'PlatoPHP/1.0' by default
 *     throw_on_error   Throw a RuntimeException on a transport failure or a non 2xx status
 *     curl             Raw curl options, merged last so they win over everything above
 *
 * ### Retries
 *
 * A retry is attempted for a transport failure (connection refused, timeout, DNS) and for the
 * statuses in `retry_on`. **Only for idempotent methods** unless `retry_methods` says otherwise:
 * retrying a POST that timed out can charge a card twice, because a timeout says nothing about
 * whether the server processed the request. A `Retry-After` header is honoured over the configured
 * backoff, which is what a server means by sending one.
 *
 * ### Middleware
 *
 * `$client->middleware(callable)` wraps every request, innermost last:
 *
 *     $client->middleware(function (array $request, callable $next)
 *     {
 *         $request['headers']['X-Request-Id'] = log::rid();
 *
 *         return $next($request);
 *     });
 *
 * The same shape as plato\http\pipeline, on purpose. Signing, tracing headers and a circuit breaker
 * all live here rather than in a wrapper around each call site.
 *
 * **TLS peer verification is on by default.** A host that deliberately uses an unverifiable
 * certificate may pass `'verify' => false` per request, making that exception visible at the call
 * site.
 */
class client
{
    /**
     * Option defaults, before configure() and before the per request options
     *
     * @var array<string, mixed>
     */
    private const DEFAULTS = [
        'method'          => 'GET',
        'timeout'         => 15,
        'connect_timeout' => 5,
        'retries'         => 0,
        'retry_on'        => [429, 500, 502, 503, 504],
        'retry_methods'   => ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS'],
        'backoff_ms'      => [200, 1000, 3000],
        'verify'          => true,
        'follow'          => true,
        'max_redirects'   => 10,
        'user_agent'      => 'PlatoPHP/1.0',
        'throw_on_error'  => false,
    ];

    /**
     * Defaults set by configure(), merged over DEFAULTS.
     *
     * No null sentinel and no lazy read here: unlike the rest of the framework this class has no
     * section in config/, so there is nothing to read on first use. configure() is the only source.
     *
     * @var array<string, mixed>
     */
    private $_config = [];

    /**
     * Middleware, outermost first
     *
     * @var array<int, callable>
     */
    private $_middleware = [];

    /**
     * @param array<string, mixed> $config Initial defaults
     */
    public function __construct(array $config = [])
    {
        $this->_config = $config;
    }

    /**
     * Hand the client its defaults.
     *
     * Merges on top of what is already set, so an override names only what it changes.
     *
     * @param array<string, mixed> $config Any of the options above, plus `base_uri`
     *
     * @return void
     */
    public function configure(array $config): void
    {
        $this->_config = $config + $this->_config;
    }

    /**
     * The effective defaults: what configure() was given, over DEFAULTS.
     *
     * @param string|null $key One option, or null for all of them
     *
     * @return mixed
     */
    public function config(?string $key = null)
    {
        $config = $this->_config + self::DEFAULTS;

        return $key === null ? $config : ($config[$key] ?? null);
    }

    /**
     * Forget the defaults and the middleware.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->_config     = [];
        $this->_middleware = [];
    }

    /**
     * Add a middleware, run around every request from now on.
     *
     * @param callable $middleware function (array $request, callable $next): client_response
     *
     * @return void
     */
    public function middleware(callable $middleware): void
    {
        $this->_middleware[] = $middleware;
    }

    /**
     * @param string               $url
     * @param array<string, mixed> $options
     *
     * @return client_response
     */
    public function get(string $url, array $options = []): client_response
    {
        return $this->request('GET', $url, $options);
    }

    /**
     * @param string               $url
     * @param mixed                $body    Array sent as a form, string sent raw, or leave it out
     *                                      and use the `json` / `form` options
     * @param array<string, mixed> $options
     *
     * @return client_response
     */
    public function post(string $url, $body = null, array $options = []): client_response
    {
        return $this->request('POST', $url, $this->_with_body($body, $options));
    }

    /**
     * @param string               $url
     * @param mixed                $body
     * @param array<string, mixed> $options
     *
     * @return client_response
     */
    public function put(string $url, $body = null, array $options = []): client_response
    {
        return $this->request('PUT', $url, $this->_with_body($body, $options));
    }

    /**
     * @param string               $url
     * @param array<string, mixed> $options
     *
     * @return client_response
     */
    public function delete(string $url, array $options = []): client_response
    {
        return $this->request('DELETE', $url, $options);
    }

    /**
     * Send one request, through the middleware stack and the retry policy.
     *
     * @param string               $method
     * @param string               $url
     * @param array<string, mixed> $options
     *
     * @return client_response
     * @throws RuntimeException When curl is missing, or on failure with throw_on_error
     */
    public function request(string $method, string $url, array $options = []): client_response
    {
        // Before _prepare(), not after: base_uri and the query array are applied to the url there,
        // and an url set afterwards would skip both
        $options['method'] = strtoupper($method);
        $options['url']    = $url;

        $request = $this->_prepare($options);

        $next = function (array $request): client_response
        {
            return $this->_send_with_retries($request);
        };

        foreach ( array_reverse($this->_middleware) as $one )
        {
            $current = $next;
            $next    = function (array $request) use ($one, $current): client_response
            {
                return $one($request, $current);
            };
        }

        $answer = $next($request);

        if ( !empty($request['throw_on_error']) && $answer->failed() )
        {
            throw new RuntimeException(sprintf(
                '%s %s failed: %s',
                $request['method'],
                $request['url'],
                $answer->error() ?? ('HTTP ' . $answer->status())
            ));
        }

        return $answer;
    }

    /**
     * Send several requests at once.
     *
     * The results keep the keys of the input, so a caller can line them up with what it asked for.
     * Middleware and retries do **not** apply here: both are per request, and running them inside a
     * multi handle would serialise exactly the thing the pool exists to parallelise. A caller that
     * needs either sends the requests one at a time.
     *
     * @param array<array-key, array<string, mixed>> $requests Option arrays, each with a `url`
     *
     * @return array<array-key, client_response>
     * @throws RuntimeException When curl is missing
     */
    public function pool(array $requests): array
    {
        $this->_require_curl();

        if ( !function_exists('curl_multi_init') )
        {
            // No curl_multi in this build: doing them in turn is slower and still correct
            $answers = [];

            foreach ( $requests as $key => $options )
            {
                $answers[$key] = $this->request(
                    (string) ($options['method'] ?? 'GET'),
                    (string) ($options['url'] ?? ''),
                    $options
                );
            }

            return $answers;
        }

        $multi   = curl_multi_init();
        $handles = [];
        $headers = [];

        foreach ( $requests as $key => $options )
        {
            $request       = $this->_prepare($options);
            $headers[$key] = [];
            $handle        = $this->_handle($request, $headers[$key]);

            $handles[$key] = $handle;
            curl_multi_add_handle($multi, $handle);
        }

        $running = null;

        do
        {
            $status = curl_multi_exec($multi, $running);

            if ( $running )
            {
                // Blocks until something happens rather than spinning the CPU
                curl_multi_select($multi, 1.0);
            }
        } while ( $running && $status === CURLM_OK );

        $answers = [];

        foreach ( $handles as $key => $handle )
        {
            $body = curl_multi_getcontent($handle);

            $answers[$key] = $this->_response(
                $handle,
                is_string($body) ? $body : '',
                $headers[$key],
                1
            );

            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }

        curl_multi_close($multi);

        return $answers;
    }

    /**
     * Array-oriented compatibility entry point over request() and pool().
     *
     * The return shape is `['head' => ..., 'body' => ..., 'info' => ...]`,
     * with a null body on a transport failure.
     *
     * **New code should use request() and the client_response object.** The array shape cannot say whether
     * a body was empty or missing, has no headers in it, and reports a status only some of the time.
     *
     * @param array<mixed> $data  Request description, or a list of them when $multi
     * @param bool         $multi Whether to run them concurrently
     *
     * @return array<mixed>
     * @deprecated Use request(), get(), post() or pool()
     */
    public function http_request($data, $multi = false)
    {
        // A list of request descriptions rather than one.
        if ( !isset($data['url']) && ($first = current($data)) && isset($first['url']) )
        {
            $answers = $multi
                ? $this->pool(array_map([$this, '_from_array'], $data))
                : array_map(function ($one)
                {
                    $one = $this->_from_array($one);

                    return $this->request((string) ($one['method'] ?? 'GET'), (string) $one['url'], $one);
                }, $data);

            return array_map(function (client_response $answer): array
            {
                return $answer->to_legacy();
            }, $answers);
        }

        $options = $this->_from_array((array) $data);
        $answer  = $this->request((string) ($options['method'] ?? 'GET'), (string) $options['url'], $options);

        if ( !empty($data['return_head']) )
        {
            return (array) $answer->info();
        }

        return $answer->to_legacy();
    }

    /**
     * Translate the array API option names into request() options.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function _from_array(array $data): array
    {
        $options = [
            'url'             => (string) ($data['url'] ?? ''),
            'timeout'         => (int) ($data['timeout'] ?? 15),
            'connect_timeout' => (int) ($data['timeout'] ?? 15),
            'user_agent'      => (string) ($data['UA'] ?? 'Mozilla/5.0'),
            // The array compatibility API preserves its explicit transport contract here.
            'verify'          => false,
            'headers'         => (array) ($data['header'] ?? []),
        ];

        if ( isset($data['post']) && $data['post'] !== '' && $data['post'] !== [] )
        {
            $options['method'] = 'POST';
            $options['body']   = is_array($data['post']) ? http_build_query($data['post']) : (string) $data['post'];
        }

        foreach ( ['referer', 'cookie', 'cookie_file', 'save_cookie', 'proxy'] as $key )
        {
            if ( !empty($data[$key]) )
            {
                $options[$key] = $data[$key];
            }
        }

        if ( !empty($data['ip']) )
        {
            $options['headers']['X-Forwarded-For'] = (string) $data['ip'];
            $options['headers']['Client-IP']       = (string) ($data['client'] ?? $data['ip']);
        }

        if ( !empty($data['connection']) )
        {
            $options['headers']['Connection'] = (string) $data['connection'];
        }

        if ( !empty($data['option']) )
        {
            $options['curl'] = (array) $data['option'];
        }

        return $options;
    }

    /**
     * Turn a body argument into options.
     *
     * @param  mixed                $body
     * @param  array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function _with_body($body, array $options): array
    {
        if ( $body === null )
        {
            return $options;
        }

        if ( is_array($body) )
        {
            $options['form'] = $body;

            return $options;
        }

        $options['body'] = (string) $body;

        return $options;
    }

    /**
     * Merge the defaults into one request description.
     *
     * @param  array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function _prepare(array $options): array
    {
        $request = $options + (array) $this->config();

        $request['url'] = $this->_url((string) ($request['url'] ?? ''), $request);

        // Header names are matched case insensitively everywhere below, so they are normalised once
        $request['headers'] = $this->_headers((array) ($request['headers'] ?? []));

        return $request;
    }

    /**
     * The absolute url, with base_uri and the query array applied.
     *
     * @param  string               $url
     * @param  array<string, mixed> $request
     * @return string
     */
    private function _url(string $url, array $request): string
    {
        $base = (string) ($request['base_uri'] ?? '');

        if ( $base !== '' && !preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) )
        {
            $url = rtrim($base, '/') . '/' . ltrim($url, '/');
        }

        $query = (array) ($request['query'] ?? []);

        if ( $query )
        {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        }

        return $url;
    }

    /**
     * Normalise headers to name => value, whichever form they arrived in.
     *
     * @param  array<mixed> $headers
     * @return array<string, string>
     */
    private function _headers(array $headers): array
    {
        $normal = [];

        foreach ( $headers as $name => $value )
        {
            // A list of 'Name: value' strings, which is what curl itself takes
            if ( is_int($name) )
            {
                $parts = explode(':', (string) $value, 2);

                if ( count($parts) === 2 )
                {
                    $normal[trim($parts[0])] = trim($parts[1]);
                }

                continue;
            }

            $normal[(string) $name] = (string) $value;
        }

        return $normal;
    }

    /**
     * Send, and try again while the policy says to.
     *
     * @param  array<string, mixed> $request
     * @return client_response
     */
    private function _send_with_retries(array $request): client_response
    {
        $retries  = max(0, (int) ($request['retries'] ?? 0));
        $backoff  = (array) ($request['backoff_ms'] ?? []);
        $attempt  = 0;
        $answer   = null;

        while ( true )
        {
            $attempt++;
            $answer = $this->_send($request, $attempt);

            if ( $attempt > $retries || !$this->_should_retry($request, $answer) )
            {
                return $answer;
            }

            // Retry-After wins over the configured backoff: it is the server saying how long it
            // wants to be left alone, and ignoring it is how a client turns a 429 into an outage
            $wait = $this->_retry_after($answer);

            if ( $wait === null )
            {
                $index = min($attempt - 1, count($backoff) - 1);
                $wait  = $backoff === [] ? 0 : (int) $backoff[max(0, $index)];
            }

            $wait > 0 && usleep($wait * 1000);
        }
    }

    /**
     * Whether an answer is worth another attempt.
     *
     * @param  array<string, mixed> $request
     * @param  client_response      $answer
     * @return bool
     */
    private function _should_retry(array $request, client_response $answer): bool
    {
        $method = strtoupper((string) ($request['method'] ?? 'GET'));

        // A timeout says nothing about whether the server processed the request, so a POST is only
        // retried when the caller has said it is safe to
        if ( !in_array($method, (array) ($request['retry_methods'] ?? []), true) )
        {
            return false;
        }

        if ( $answer->error() !== null )
        {
            return true;
        }

        return in_array($answer->status(), array_map('intval', (array) ($request['retry_on'] ?? [])), true);
    }

    /**
     * The Retry-After header, in milliseconds.
     *
     * @param  client_response $answer
     * @return int|null  Null when the header is absent or unreadable
     */
    private function _retry_after(client_response $answer)
    {
        $value = $answer->header('retry-after');

        if ( $value === null || $value === '' )
        {
            return null;
        }

        if ( preg_match('/^\d+$/', trim($value)) )
        {
            return (int) trim($value) * 1000;
        }

        // The other legal form is an HTTP date
        $at = strtotime($value);

        return $at === false ? null : (int) max(0, ($at - time()) * 1000);
    }

    /**
     * One attempt.
     *
     * @param  array<string, mixed> $request
     * @param  int                  $attempt
     * @return client_response
     */
    private function _send(array $request, int $attempt): client_response
    {
        $this->_require_curl();

        $headers = [];
        $handle  = $this->_handle($request, $headers);
        $body    = curl_exec($handle);

        $answer = $this->_response($handle, is_string($body) ? $body : '', $headers, $attempt);

        curl_close($handle);

        if ( $answer->error() !== null )
        {
            log::error(sprintf('%s|%s', $request['url'], $answer->error()), 'http.client');
        }

        return $answer;
    }

    /**
     * Build a curl handle for a request.
     *
     * @param  array<string, mixed>  $request
     * @param  array<string, string> $headers Filled by the header callback as the answer arrives
     * @return \CurlHandle
     */
    private function _handle(array $request, array &$headers)
    {
        $handle = curl_init((string) $request['url']);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_CUSTOMREQUEST  => strtoupper((string) ($request['method'] ?? 'GET')),
            CURLOPT_USERAGENT      => (string) ($request['user_agent'] ?? 'PlatoPHP/1.0'),
            CURLOPT_CONNECTTIMEOUT => (int) ($request['connect_timeout'] ?? 5),
            CURLOPT_TIMEOUT        => (int) ($request['timeout'] ?? 15),
            CURLOPT_FOLLOWLOCATION => (bool) ($request['follow'] ?? true),
            CURLOPT_MAXREDIRS      => (int) ($request['max_redirects'] ?? 10),
            CURLOPT_SSL_VERIFYPEER => (bool) ($request['verify'] ?? true),
            CURLOPT_SSL_VERIFYHOST => ($request['verify'] ?? true) ? 2 : 0,
            CURLOPT_HEADERFUNCTION => function ($ignored, string $line) use (&$headers): int
            {
                $length = strlen($line);
                $parts  = explode(':', $line, 2);

                if ( count($parts) === 2 )
                {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                // curl wants the number of bytes it handed over, whatever was done with them
                return $length;
            },
        ];

        $body    = $this->_body($request, $request['headers']);
        $headers_out = $request['headers'];

        if ( $body !== null )
        {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        // HEAD has no body, and curl needs telling separately or it waits for one
        if ( $options[CURLOPT_CUSTOMREQUEST] === 'HEAD' )
        {
            $options[CURLOPT_NOBODY] = true;
        }

        $lines = [];

        foreach ( $headers_out as $name => $value )
        {
            $lines[] = $name . ': ' . $value;
        }

        if ( $lines )
        {
            $options[CURLOPT_HTTPHEADER] = $lines;
        }

        foreach ( ['referer' => CURLOPT_REFERER, 'cookie' => CURLOPT_COOKIE, 'proxy' => CURLOPT_PROXY,
                   'cookie_file' => CURLOPT_COOKIEFILE, 'save_cookie' => CURLOPT_COOKIEJAR ] as $key => $option )
        {
            if ( !empty($request[$key]) )
            {
                $options[$option] = $request[$key];
            }
        }

        // Raw curl options win over everything decided above. The caller's array is on the *left*
        // of the union on purpose: `+` keeps the left operand's value for a key both sides have,
        // so putting $options first would have silently dropped every override
        curl_setopt_array($handle, (array) ($request['curl'] ?? []) + $options);

        return $handle;
    }

    /**
     * The request body, and the content type that goes with it.
     *
     * @param  array<string, mixed>  $request
     * @param  array<string, string> $headers Content-Type is added here when the body implies one
     * @return string|null
     */
    private function _body(array $request, array &$headers)
    {
        $has = function (string $name) use ($headers): bool
        {
            foreach ( $headers as $key => $ignored )
            {
                if ( strcasecmp($key, $name) === 0 )
                {
                    return true;
                }
            }

            return false;
        };

        if ( isset($request['json']) )
        {
            $has('Content-Type') || $headers['Content-Type'] = 'application/json; charset=utf-8';

            return (string) json_encode($request['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ( isset($request['form']) )
        {
            $has('Content-Type') || $headers['Content-Type'] = 'application/x-www-form-urlencoded';

            return http_build_query((array) $request['form']);
        }

        if ( isset($request['body']) && $request['body'] !== '' )
        {
            return is_array($request['body']) ? http_build_query($request['body']) : (string) $request['body'];
        }

        return null;
    }

    /**
     * Turn a finished handle into a response.
     *
     * @param  \CurlHandle           $handle
     * @param  string                $body
     * @param  array<string, string> $headers
     * @param  int                   $attempt
     * @return client_response
     */
    private function _response($handle, string $body, array $headers, int $attempt): client_response
    {
        $errno = curl_errno($handle);
        $info  = curl_getinfo($handle);
        $info  = is_array($info) ? $info : [];

        if ( $errno )
        {
            $info['errno'] = $errno;

            return new client_response(0, '', $headers, $info, curl_error($handle), $attempt);
        }

        return new client_response(
            (int) ($info['http_code'] ?? 0),
            $body,
            $headers,
            $info,
            null,
            $attempt
        );
    }

    /**
     * @return void
     * @throws RuntimeException When the curl extension is missing
     */
    private function _require_curl(): void
    {
        if ( !function_exists('curl_init') )
        {
            throw new RuntimeException('plato\http\client requires the curl extension.');
        }
    }
}
