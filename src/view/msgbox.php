<?php

/**
 * Self-contained message and HTTP error responses
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\view;

use plato\http\reply;
use plato\http\resp;

/**
 * Builds small HTML responses without depending on application templates.
 */
class msgbox
{
    /**
     * Build a message page with an optional same-site redirect.
     *
     * @param string     $title
     * @param string     $msg
     * @param string|int $gourl     A local absolute path, -1 for browser back, or -2 for sign in
     * @param int        $limittime Redirect delay in milliseconds
     */
    public static function show($title, $msg, $gourl = '', $limittime = 3000): reply
    {
        $title = self::_escape($title === '' ? 'System message' : (string) $title);
        $msg   = nl2br(self::_escape((string) $msg));
        $url   = self::_local_url((string) $gourl);
        $head  = '';
        $next  = '';

        if ( (string) $gourl === '-1' )
        {
            $next = '<button type="button" onclick="history.back()">Go back</button>';
        }
        elseif ( (string) $gourl === '-2' )
        {
            $next = '<a href="/logout">Sign in again</a>';
        }
        elseif ( $url !== '' )
        {
            $delay = max(0, (int) ceil(((int) $limittime) / 1000));
            $safe  = self::_escape($url);
            $head  = '<meta http-equiv="refresh" content="' . $delay . ';url=' . $safe . '">';
            $next  = '<a href="' . $safe . '">Continue</a>';
        }

        $html = '<!doctype html><html><head><meta charset="utf-8">' . $head
            . '<title>' . $title . '</title></head><body><main><h1>' . $title . '</h1><p>' . $msg
            . '</p>' . $next . '</main></body></html>';

        return resp::html($html);
    }

    /**
     * Build a minimal 401, 403, 404 or 500 response.
     *
     * @param int|string $http_code
     */
    public static function error($http_code = '404'): reply
    {
        $requested = (int) $http_code;
        $status    = in_array($requested, [401, 403, 404, 500], true) ? $requested : 404;
        $title     = [
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            500 => 'Internal Server Error',
        ][$status];
        $html   = '<!doctype html><html><head><meta charset="utf-8"><title>' . $title
            . '</title></head><body><main><h1>' . $title . '</h1></main></body></html>';

        return resp::html($html, $status);
    }

    private static function _escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Accept only same-site absolute paths. Protocol-relative and script URLs are rejected.
     */
    private static function _local_url(string $url): string
    {
        $url = trim($url);

        if ( $url === '' || $url[0] !== '/' || substr($url, 0, 2) === '//' )
        {
            return '';
        }

        return str_replace(["\r", "\n"], '', $url);
    }
}
