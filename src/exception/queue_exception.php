<?php

/**
 * Raised by the queue facade and its drivers
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\exception;

/**
 * Queue failure.
 *
 * Covers the three things that go wrong at the queue boundary: a driver that cannot be resolved
 * from the configuration, a capability the configured driver does not have (asking kafka for a
 * delayed message, say), and a payload that will not survive the wire format.
 *
 * A backend that is merely unreachable does not come through here -- that is the extension's own
 * exception, and the driver lets it through so the caller can tell "redis is down" apart from
 * "this queue is misconfigured".
 */
class queue_exception extends plato_exception
{
}
