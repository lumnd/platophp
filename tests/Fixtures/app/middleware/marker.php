<?php

namespace middleware;

use plato\http\reply;

/**
 * Middleware fixture: adds a header on the way in, and one on the way out.
 *
 * Two headers rather than one so tests/Feature/httpKernelTest.php can tell that the pipeline wraps
 * the action instead of only running ahead of it.
 */
class marker
{
    /**
     * @param callable $next
     * @return mixed
     */
    public function handle(callable $next)
    {
        $result = $next();

        if ( !$result instanceof reply )
        {
            return $result;
        }

        return $result
            ->with_header('X-Middleware-Before', '1')
            ->with_header('X-Middleware-After', '1');
    }
}
