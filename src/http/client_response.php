<?php

/**
 * Response returned by a client request
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\http;

/**
 * One HTTP client response, as a value.
 *
 * Deliberately **not** a PSR-7 message. PSR-7 would be the right shape if this package could
 * require `psr/http-message`, but it cannot: a framework that pins a host project's major version
 * of PSR-7 makes itself uninstallable next to half of packagist, and PSR-7 without an
 * implementation is only interfaces. So this is the same information in a class of a dozen
 * methods, and an application that wants PSR-7 wraps it.
 *
 * A transport failure is a response too -- `status()` is 0, `error()` says what curl reported, and
 * `ok()` is false. Nothing here throws: a caller that would rather have an exception asks for one
 * with `throw_on_error()` in the request options.
 */
class client_response
{
    /**
     * HTTP status code, 0 when the request never got an answer
     *
     * @var int
     */
    private $_status;

    /**
     * Response body, empty string when there was none
     *
     * @var string
     */
    private $_body;

    /**
     * Response headers, lower cased name => value. A header sent twice keeps the last value.
     *
     * @var array<string, string>
     */
    private $_headers;

    /**
     * What curl_getinfo() reported
     *
     * @var array<string, mixed>
     */
    private $_info;

    /**
     * Transport error, null when the request reached the server
     *
     * @var string|null
     */
    private $_error;

    /**
     * How many attempts it took, 1 when the first one worked
     *
     * @var int
     */
    private $_attempts;

    /**
     * @param int                   $status
     * @param string                $body
     * @param array<string, string> $headers
     * @param array<string, mixed>  $info
     * @param string|null           $error
     * @param int                   $attempts
     */
    public function __construct(
        int $status,
        string $body = '',
        array $headers = [],
        array $info = [],
        ?string $error = null,
        int $attempts = 1
    )
    {
        $this->_status   = $status;
        $this->_body     = $body;
        $this->_headers  = $headers;
        $this->_info     = $info;
        $this->_error    = $error;
        $this->_attempts = $attempts;
    }

    /**
     * @return int  0 when the request never reached the server
     */
    public function status(): int
    {
        return $this->_status;
    }

    /**
     * @return string
     */
    public function body(): string
    {
        return $this->_body;
    }

    /**
     * The body decoded as JSON.
     *
     * @param bool $associative Arrays rather than objects
     *
     * @return mixed  Null when the body is not JSON, which is also what a JSON `null` decodes to;
     *                check the body itself when the difference matters
     */
    public function json(bool $associative = true)
    {
        return json_decode($this->_body, $associative);
    }

    /**
     * One response header, by name, case insensitively.
     *
     * @param string      $name
     * @param string|null $default
     *
     * @return string|null
     */
    public function header(string $name, ?string $default = null)
    {
        return $this->_headers[strtolower($name)] ?? $default;
    }

    /**
     * @return array<string, string>  Lower cased names
     */
    public function headers(): array
    {
        return $this->_headers;
    }

    /**
     * @param string|null $key
     * @param mixed       $default
     *
     * @return mixed  curl_getinfo(), whole or one key of it
     */
    public function info(?string $key = null, $default = null)
    {
        if ( $key === null )
        {
            return $this->_info;
        }

        return $this->_info[$key] ?? $default;
    }

    /**
     * @return string|null  What curl reported, null when the request reached the server
     */
    public function error()
    {
        return $this->_error;
    }

    /**
     * @return int  Attempts made, including the one that succeeded
     */
    public function attempts(): int
    {
        return $this->_attempts;
    }

    /**
     * Whether the status is 2xx.
     *
     * @return bool
     */
    public function ok(): bool
    {
        return $this->_status >= 200 && $this->_status < 300;
    }

    /**
     * Whether the server said 4xx.
     *
     * @return bool
     */
    public function client_error(): bool
    {
        return $this->_status >= 400 && $this->_status < 500;
    }

    /**
     * Whether the server said 5xx, or never answered at all.
     *
     * @return bool
     */
    public function server_error(): bool
    {
        return $this->_status === 0 || $this->_status >= 500;
    }

    /**
     * Whether anything went wrong: a transport failure, or a status outside 2xx.
     *
     * @return bool
     */
    public function failed(): bool
    {
        return !$this->ok();
    }

    /**
     * The array shape returned by http_request().
     *
     * @return array{head: array<string, mixed>, body: string|null, info: array<string, mixed>}
     */
    public function to_legacy(): array
    {
        $info = $this->_error === null
            ? ['status' => $this->_status]
            : ['errno' => (int) ($this->_info['errno'] ?? 0), 'error' => $this->_error];

        return [
            'head' => $this->_info,
            // null and not '' on a transport failure: callers test the body for null
            'body' => $this->_error === null ? $this->_body : null,
            'info' => $info,
        ];
    }
}
