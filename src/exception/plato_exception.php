<?php

/**
 * Base class for every exception thrown by the framework
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\exception;

use plato\plato;
use Throwable;

/**
 * Framework exception.
 *
 * The framework identifies an error by its numeric code; the human readable text lives in
 * config/exception.php and is rendered through plato::fmt_code(). Callers therefore pass the
 * sprintf arguments instead of a message:
 *
 *     throw new bootstrap_exception(['app_path'], 1006);
 *
 * getMessage() returns the rendered text, params() returns the raw arguments.
 */
class plato_exception extends \Exception
{
    /**
     * Arguments the message template was rendered with.
     *
     * @var array<int, mixed>
     */
    protected $params = [];

    /**
     * @param array<int, mixed>|string $params Template arguments, a bare string is accepted too
     * @param int                      $code   Key of config/exception.php
     * @param Throwable|null           $previous
     */
    public function __construct($params = [], $code = 0, ?Throwable $previous = null)
    {
        $this->params = is_array($params) ? array_values($params) : [$params];

        parent::__construct(plato::fmt_code($code, $this->params), (int) $code, $previous);
    }

    /**
     * Arguments the message template was rendered with.
     *
     * @return array<int, mixed>
     */
    public function params()
    {
        return $this->params;
    }
}
