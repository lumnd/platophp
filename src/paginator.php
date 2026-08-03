<?php

/**
 * Pagination arithmetic
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato;

/**
 * Everything a listing needs to paginate itself, and nothing else.
 *
 * This is a pure function of its three arguments: it reads no request, no cookie and no
 * configuration, so a JSON endpoint, an HTML listing and a unit test all get the same answer
 * from the same inputs. Whoever calls it decides where the page number comes from --
 * `req::item('page_no', 1)` in a controller, a path segment behind a router, a queue payload.
 *
 * @version $Id$
 */
class paginator
{
    /**
     * Page numbers and offsets for a result set of $total rows.
     *
     * Returned keys:
     *
     *   total         Row count as given, floored at zero
     *   total_page    Number of pages, zero when there is nothing to page through
     *   current_page  The requested page, floored at one
     *   page_size     Rows per page, floored at one
     *   offset        Rows to skip, for the LIMIT clause
     *   prev          Previous page number, null on the first page
     *   next          Next page number, null on the last page and when there are no rows
     *
     * `current_page` is deliberately *not* clamped to `total_page`: a request for page 99 of a
     * five page listing keeps its 99 and gets an offset past the end, so the caller sees an empty
     * result and can answer 404. Clamping would silently serve page five instead.
     *
     * @param int $total     Total number of rows
     * @param int $page      Requested page number, one based
     * @param int $page_size Rows per page
     *
     * @return array{
     *     total: int, total_page: int, current_page: int, page_size: int, offset: int,
     *     prev: int|null, next: int|null
     * }
     */
    public static function meta($total, $page = 1, $page_size = 10): array
    {
        $total        = max(0, (int) $total);
        $page_size    = max(1, (int) $page_size);
        $current_page = max(1, (int) $page);
        $total_page   = (int) ceil($total / $page_size);

        return [
            'total'        => $total,
            'total_page'   => $total_page,
            'current_page' => $current_page,
            'page_size'    => $page_size,
            'offset'       => ($current_page - 1) * $page_size,
            'prev'         => $current_page > 1 ? $current_page - 1 : null,
            'next'         => $current_page < $total_page ? $current_page + 1 : null,
        ];
    }
}
