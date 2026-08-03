<?php

/**
 * Relative path validation, shared by every disk
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\storage;

use plato\exception\storage_exception;

/**
 * The one place a caller supplied path is checked.
 *
 * Every disk runs its arguments through here before touching anything, and the rule is **reject,
 * do not normalise**. A `..` segment stripped out of a path turns a traversal attempt into a
 * successful read of the wrong file, and nothing in the log says it happened; a rejected one is a
 * thrown exception with the offending path in it.
 *
 * What is refused:
 *
 *   - an empty path, or one that is only separators
 *   - an absolute path, `/etc/passwd` or `C:\windows`
 *   - any `.` or `..` segment, wherever it appears
 *   - a backslash, which is a directory separator on Windows and a legal filename character
 *     elsewhere -- allowing it would mean the same string names two different files depending on
 *     where the code runs
 *   - a null byte, which truncates the path in every C function underneath
 *   - a protocol wrapper: `php://`, `data://`, `phar://`. `file_get_contents` takes all of them
 *
 * What is allowed through unchanged: forward slashes, spaces, unicode, and a trailing dot in a
 * name. Those are all legal in an object key and in a filename.
 */
class path
{
    /**
     * Check a relative path and answer it with the separators tidied.
     *
     * "Tidied" here means only collapsing repeated slashes and dropping a trailing one; nothing is
     * resolved, because anything that needed resolving has already been refused.
     *
     * @param string $path
     *
     * @return string
     * @throws storage_exception When the path is not one a disk may act on
     */
    public static function clean(string $path): string
    {
        if ( strpos($path, "\0") !== false )
        {
            throw new storage_exception('a storage path may not hold a null byte');
        }

        if ( strpos($path, '\\') !== false )
        {
            throw new storage_exception('a storage path uses forward slashes: ' . $path);
        }

        if ( preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $path) )
        {
            throw new storage_exception('a storage path may not name a protocol: ' . $path);
        }

        if ( $path === '' || $path[0] === '/' )
        {
            throw new storage_exception('a storage path is relative and not empty: ' . $path);
        }

        // A Windows drive letter is absolute too, and does not start with a slash
        if ( preg_match('#^[a-zA-Z]:#', $path) )
        {
            throw new storage_exception('a storage path may not be absolute: ' . $path);
        }

        $segments = explode('/', $path);
        $keep     = [];

        foreach ( $segments as $segment )
        {
            if ( $segment === '.' || $segment === '..' )
            {
                throw new storage_exception('a storage path may not walk up or stay put: ' . $path);
            }

            // Repeated slashes are the one thing collapsed rather than refused: 'a//b' names the
            // same file as 'a/b' on every filesystem and in every object store
            if ( $segment !== '' )
            {
                $keep[] = $segment;
            }
        }

        if ( $keep === [] )
        {
            throw new storage_exception('a storage path is relative and not empty: ' . $path);
        }

        return implode('/', $keep);
    }

    /**
     * The same, for a prefix: an empty one means the root and is allowed.
     *
     * @param string $prefix
     *
     * @return string  '' for the root
     * @throws storage_exception
     */
    public static function clean_prefix(string $prefix): string
    {
        $prefix = trim($prefix);

        if ( $prefix === '' || $prefix === '/' )
        {
            return '';
        }

        return self::clean($prefix);
    }
}
