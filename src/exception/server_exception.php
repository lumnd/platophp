<?php

/**
 * Raised at the socket server boundary, by the server facade and by the dispatcher
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\exception;

/**
 * Resident server failure.
 *
 * Covers what goes wrong around a socket server rather than inside one: a driver that cannot be
 * resolved from the configuration, an adapter package that is named but not installed, and a message
 * whose payload is not something the configured codec can read.
 *
 * What it deliberately does not cover is a failure while handling one message. A message that ends
 * in an exception must not take the worker with it -- the other connections of that process did
 * nothing wrong -- so server\dispatcher catches every Throwable, logs it, and answers the client with
 * an error reply. This class is for the two ends of the process instead: refusing to start, and
 * refusing to accept a message at all.
 */
class server_exception extends plato_exception
{
}
