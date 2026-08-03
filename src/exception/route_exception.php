<?php

/**
 * Raised while the router is resolving a request into a controller / action pair
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\exception;

/**
 * Routing failure.
 *
 * Carries the HTTP status the failure should be reported with, so the dispatcher does not have
 * to map error codes onto status codes itself. Unknown codes report as 404 rather than 500: a
 * request that cannot be routed is a client error, and answering 500 would tell an attacker
 * that something on the server reacted to the input.
 */
class route_exception extends plato_exception
{
    /**
     * Error code => HTTP status.
     *
     * @var array<int, int>
     */
    protected static $_status = [
        2007 => 404, // invalid or unroutable path
        2008 => 405, // method not allowed for this action
        2009 => 404, // action is not declared routable
        // The two server errors in this table. A declaration the framework cannot read is a
        // programming mistake that no request can steer, so hiding it behind 404 only costs the
        // developer the clue.
        2011 => 500, // action declaration cannot be read
        2012 => 500, // $actions is not a public static array
    ];

    /**
     * HTTP status this failure should be reported with.
     *
     * @return int
     */
    public function status()
    {
        return self::$_status[$this->getCode()] ?? 404;
    }
}
