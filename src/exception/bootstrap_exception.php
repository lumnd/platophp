<?php

/**
 * Raised while plato::registry() is setting the runtime up
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\exception;

/**
 * Bootstrap failure: missing or unusable app_path, unwritable data directories, ...
 *
 * Thrown before the framework error handlers are installed, so the host process sees it as an
 * uncaught exception unless it wraps plato::registry() in a try / catch of its own.
 */
class bootstrap_exception extends plato_exception
{
}
