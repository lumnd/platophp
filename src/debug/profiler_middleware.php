<?php

/**
 * Middleware that appends the profiler panel to HTML replies in debug mode
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\debug;

use plato\http\reply;
use plato\http\resp;
use plato\plato;
use plato\tpl;

/**
 * Decorate completed HTML replies without buffering files, streams or non-HTML payloads.
 *
 * Only the debug flag turns the panel on here. An application that enables the profiler by hand
 * outside debug mode still gets it on the `tpl::output()` echo path, which reads the flag and not
 * the flag's origin; this middleware deliberately does not, so registering it globally cannot
 * append the panel to a production page.
 */
class profiler_middleware
{
    /**
     * @param callable $next
     *
     * @return mixed
     */
    public function handle(callable $next)
    {
        if ( !plato::debug() )
        {
            return $next();
        }

        profiler::instance()->enable_profiler();

        $reply = self::_answer($next());

        if ( !$reply instanceof reply || !self::_is_html($reply) )
        {
            return $reply;
        }

        return $reply->with_body(tpl::decorate($reply->body()));
    }

    /**
     * The reply this request is answered with, whichever way the action produced it.
     *
     * An action may `return resp::html(...)` or call it and return nothing, and plato::run() falls
     * back to resp::prepared() for the second form. Resolving it here too means the panel does not
     * depend on which of the two an action used. Returning the decorated reply is what run() then
     * takes, so the stale copy resp still holds is never read.
     *
     * @param mixed $result What the rest of the pipeline returned
     *
     * @return mixed
     */
    private static function _answer($result)
    {
        if ( $result instanceof reply )
        {
            return $result;
        }

        return resp::prepared() ?? $result;
    }

    /**
     * Read the response media type rather than guessing it from the request.
     */
    private static function _is_html(reply $reply): bool
    {
        foreach ( $reply->headers() as $name => $value )
        {
            if ( strtolower($name) === 'content-type' )
            {
                return stripos($value, 'text/html') === 0;
            }
        }

        return false;
    }
}
