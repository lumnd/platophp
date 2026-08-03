<?php

/**
 * Raised when the application's authentication callback breaks its contract
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\exception;

/**
 * Authentication integration failure.
 *
 * Not "this visitor is not logged in". A required action that the callback found nobody for is a
 * visitor state and answers 401, and an application that wants a different answer returns its own
 * reply from check_purview_handle. This is the framework saying the integration itself is wrong:
 * an action declared auth = required with no callback configured at all, so nothing was ever
 * asked, or a callback that returned a value that is neither identity nor reply. Both are
 * programming errors, so they report as 500 rather than as a client error.
 */
class auth_exception extends plato_exception
{
}
