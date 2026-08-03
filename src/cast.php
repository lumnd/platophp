<?php

/**
 * Coercing a request value to a type
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato;

use Exception;

/**
 * Type coercion for values that arrive as strings.
 *
 * Everything an HTTP request carries is a string. This turns one of those into the type the code
 * downstream expects, which is what `req::get('page', 1, 'int')` is asking for. That is the whole
 * job -- nine types, no rule sets, no error messages.
 *
 * | Type            | What comes back |
 * | ---             | --- |
 * | `int` `float` `bool` | The cast |
 * | `gt0`           | The int cast, never below 0 |
 * | `string`        | The string cast, trimmed |
 * | `email` `ip`    | The value when filter_var accepts it, otherwise `''` -- or an exception
 *   when $throw is on |
 * | `var` `hash`    | Everything outside `\w` / `[0-9a-zA-Z]` removed |
 *
 * A type outside that list is an exception, so a misspelling is loud rather than silently
 * applying some other type to the data.
 *
 * **Nothing here escapes.** There is no `safe_str`, no `htmlentities`, no `xss_clean`: a value is
 * encoded at the point it is written out, against the context it is written into, and not on the
 * way in. That is the same position `req` takes on the raw body -- see the "Keeping the raw input"
 * section of docs/source/request.md -- and the reason `request.global_xss_filtering` was removed.
 * Escaping on input cannot know whether the value ends up in HTML, in an attribute, in JavaScript,
 * in a URL or in an SQL statement, and it corrupts the stored value for every consumer that is
 * none of those.
 *
 * Where the other halves of the job live:
 *
 * - **SQL**: bound parameters, through the query builder. No amount of input rewriting substitutes.
 * - **HTML**: Smarty escapes plain variables by default (`template.escape_html`), and
 *   `view\msgbox` escapes what it builds itself.
 * - **Rich text**: an allowlist sanitiser the application chooses and keeps patched.
 * - **Reporting why a value is wrong**, and rules like a minimum length or a national id format:
 *   `security\validate`, which answers with messages instead of with a rewritten value.
 */
class cast
{
    /**
     * The types to() accepts.
     *
     * @var array<int, string>
     */
    public const TYPES = ['int', 'gt0', 'float', 'bool', 'string', 'email', 'ip', 'var', 'hash'];

    /**
     * Coerces one value.
     *
     * An array is coerced element by element, recursively. Null is left alone rather than cast, so
     * a missing value stays distinguishable from a zero or an empty string.
     *
     * @param  mixed  $val   Value to coerce
     * @param  string $type  One of TYPES; '' returns the value untouched
     * @param  bool   $throw Whether a malformed email / ip throws instead of coming back as ''.
     *                       Applications set this through req::$throw_error rather than here
     * @return mixed
     *
     * @throws Exception When the type is not one of TYPES, or when $throw is on and the value
     *                   does not fit the type
     */
    public static function to($val, string $type = '', bool $throw = false)
    {
        if ( $type === '' )
        {
            return $val;
        }

        if ( is_array($val) )
        {
            foreach ($val as $k => $v)
            {
                $val[$k] = self::to($v, $type, $throw);
            }

            return $val;
        }

        if ( $val === null )
        {
            return null;
        }

        $type = strtolower($type);
        $val  = is_string($val) ? trim($val) : $val;

        switch ( $type )
        {
            case 'int':
                return (int) $val;
            case 'gt0':
                return max(0, (int) $val);
            case 'float':
                return (float) $val;
            case 'bool':
                return (bool) $val;
            case 'string':
                return trim((string) $val);
            case 'email':
                // An absent value is not "an invalid email": it is blanked without throwing even
                // when $throw is on, because required is validate's job, not this one's
                return self::_reject(
                    $val,
                    filter_var($val, FILTER_VALIDATE_EMAIL) !== false,
                    $throw && strlen((string) $val) > 0,
                    'email'
                );
            case 'ip':
                // v4 and v6 both, which is what the client actually connects from
                return self::_reject($val, filter_var($val, FILTER_VALIDATE_IP) !== false, $throw, 'ip address');
            case 'var':
                return preg_replace('/[^\w]/', '', (string) $val);
            case 'hash':
                return preg_replace('/[^0-9a-zA-Z]/', '', (string) $val);
        }

        throw new Exception('cast: unknown type ' . $type);
    }

    /**
     * Blanks a value that did not fit its type, or throws when the caller asked to be told.
     *
     * @param  mixed  $val
     * @param  bool   $ok    Whether the value fits its type
     * @param  bool   $throw Whether a value that does not fit is an exception rather than a blank
     * @param  string $what  Named in the message
     * @return mixed  The value when it fits, '' when it does not
     *
     * @throws Exception
     */
    private static function _reject($val, bool $ok, bool $throw, string $what)
    {
        if ( $ok )
        {
            return $val;
        }

        if ( $throw )
        {
            throw new Exception('cast: not a valid ' . $what);
        }

        return '';
    }
}
