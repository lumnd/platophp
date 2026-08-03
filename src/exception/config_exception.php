<?php

/**
 * Raised while a configuration module is being loaded
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\exception;

/**
 * Configuration failure.
 *
 * Carries the list of files that were looked at, so the message says where the module was
 * expected instead of only naming the module.
 */
class config_exception extends plato_exception
{
}
