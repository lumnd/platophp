<?php

namespace middleware;

use plato\http\resp;

/**
 * Middleware fixture: answers on its own, so the action is never reached.
 *
 * What a rate limiter or a maintenance switch does -- and the reason the pipeline exists, since an
 * event hook cannot stop a request.
 */
class refuse
{
    /**
     * @param callable $next
     * @return \plato\http\reply
     */
    public function handle(callable $next)
    {
        return resp::json(['code' => -429, 'msg' => 'refused by middleware'], 429);
    }
}
