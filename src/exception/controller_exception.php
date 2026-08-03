<?php

/**
 * Raised while plato::run() is dispatching to a controller
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\exception;

/**
 * Dispatch failure: unknown controller, missing action, action denied by the naming rules.
 */
class controller_exception extends plato_exception
{
}
