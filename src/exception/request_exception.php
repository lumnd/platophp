<?php

/**
 * Raised when an HTTP request body cannot be parsed as the declared media type
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\exception;

/**
 * Malformed request input. This is a client error and maps to HTTP 400.
 */
class request_exception extends plato_exception
{
    public function status(): int
    {
        return 400;
    }
}
