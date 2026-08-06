<?php

/**
 * String helpers: format detection, random strings, placeholders, masking and byte counts
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato;

use InvalidArgumentException;

class str
{
    /**
     * Character pools the variable-length random types draw from.
     *
     * 'distinct' drops the characters that are read wrong out loud (0/O, 1/I/l).
     */
    private const POOLS = [
        'alnum'    => '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ',
        'alpha'    => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ',
        'numeric'  => '0123456789',
        'nozero'   => '123456789',
        'distinct' => '2345679ACDEFHJKLMNPRSTUVWXYZ',
        'hexdec'   => '0123456789abcdef',
    ];

    /**
     * Source tokens swapped out around highlight_string().
     *
     * Each entry is [placeholder, what the token becomes in the output]. The tokens either open a
     * new PHP context or get escaped by the highlighter, so they are replaced by a placeholder
     * before the call and put back after it. Placeholders survive because the highlighter treats
     * them as ordinary identifiers and leaves the text itself alone.
     */
    private const MARKERS = [
        '<?'        => ['PLATO_MARK_PHP_OPEN', '&lt;?'],
        '?>'        => ['PLATO_MARK_PHP_CLOSE', '?&gt;'],
        '<%'        => ['PLATO_MARK_ASP_OPEN', '&lt;%'],
        '%>'        => ['PLATO_MARK_ASP_CLOSE', '%&gt;'],
        '\\'        => ['PLATO_MARK_BACKSLASH', '\\'],
        '</script>' => ['PLATO_MARK_SCRIPT_CLOSE', '&lt;/script&gt;'],
    ];

    /**
     * Rolling counter behind unique_id(), seeded on first use.
     *
     * Not reset when the second rolls over: the timestamp in front of it has already changed, and
     * a counter that restarts from the same place every second would hand out the same low values
     * over and over.
     *
     * @var int|null
     */
    private static $_counter = null;

    /** @var int|null Timestamp used by the current counter window */
    private static $_counter_second = null;

    /** @var int Number of counter slots used in the current second */
    private static $_counter_issued = 0;

    /**
     * Whether the string parses as JSON.
     *
     * @param  string $str
     *
     * @return bool
     */
    public static function is_json(string $str): bool
    {
        // 8.3+ validates without building the decoded structure, which matters for request bodies
        if ( function_exists('json_validate') )
        {
            return json_validate($str);
        }

        json_decode($str);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Whether the string is a PHP serialized value.
     *
     * Classes are not allowed through: this only answers a question about the format, so it must
     * not construct the caller's objects or run __wakeup() along the way. A serialized object is
     * still reported as serialized -- it just comes back as __PHP_Incomplete_Class here.
     *
     * 'b:0;' is checked by hand because it is the one input unserialize() rejects and accepts
     * at the same time: it returns false, which is also its failure value.
     *
     * @param  string $str
     *
     * @return bool
     */
    public static function is_serialized(string $str): bool
    {
        $value = @unserialize($str, ['allowed_classes' => false]);
        return ! ($value === false && $str !== 'b:0;');
    }

    /**
     * Build a random string.
     *
     * Every type draws from PHP's cryptographically secure system randomness. The legacy type names
     * describe their output format; they do not select weaker entropy sources.
     *
     * @param  string $type   alnum|alpha|numeric|nozero|distinct|hexdec|basic|unique|sha1|uuid
     * @param  int    $length Number of characters. 'unique' caps it at 32; 'basic', 'sha1' and
     *                        'uuid' have a fixed width and ignore it
     *
     * @return string
     * @throws InvalidArgumentException When $type is not one of the types listed above
     */
    public static function random(string $type = 'alnum', int $length = 16): string
    {
        if ( isset(self::POOLS[$type]) )
        {
            $pool = self::POOLS[$type];
            $last = strlen($pool) - 1;
            $str  = '';

            for ( $i = 0; $i < $length; $i++ )
            {
                $str .= $pool[random_int(0, $last)];
            }

            return $str;
        }

        switch ( $type )
        {
            case 'basic':
                return (string) random_int(0, PHP_INT_MAX);

            case 'unique':
                $str = bin2hex(random_bytes(16));
                return substr($str, 0, max(1, min($length, 32)));

            case 'sha1':
                return bin2hex(random_bytes(20));

            case 'uuid':
                // The variant field is 10xx, so the first character of group four is one of these
                $variant = ['8', '9', 'a', 'b'];
                return sprintf(
                    '%s-%s-4%s-%s%s-%s',
                    static::random('hexdec', 8),
                    static::random('hexdec', 4),
                    static::random('hexdec', 3),
                    $variant[random_int(0, count($variant) - 1)],
                    static::random('hexdec', 3),
                    static::random('hexdec', 12)
                );
        }

        throw new InvalidArgumentException('Unknown random string type: ' . $type);
    }

    /**
     * Build a 19-digit numeric id: `ymdHis` + process slot + counter.
     *
     * ```php
     * str::unique_id();     // '2607311530450420007'
     * ```
     *
     * Nineteen digits is the width of a mysql bigint, which is the point: an order number built
     * this way is stored and indexed as a number rather than as a string. It stays inside a
     * **signed** bigint until 2093 -- `93...` onwards is past 9223372036854775807, so a column that
     * has to outlive that has to be unsigned.
     *
     * **Unique within this process**, which is the guarantee `random()` cannot make. The counter
     * advances on every call. Once all 10000 counter values for one timestamp have been used, the
     * next call waits for a later second instead of wrapping onto an id already returned.
     *
     * **Across processes it is a probability, not a guarantee.** Three of the digits are the pid
     * modulo 1000, so two processes only share a lane when their pids do; the counters start at a
     * random point rather than at zero so that two that do share one are still unlikely to be
     * handing out the same value at the same moment. When duplicates would be an incident rather
     * than a nuisance, the unique index on the column is the thing that actually promises it.
     *
     * The timestamp follows php's default timezone, so servers set to different zones produce ids
     * that do not sort against each other.
     *
     * @return string
     */
    public static function unique_id(): string
    {
        $second = time();

        if ( self::$_counter === null )
        {
            self::$_counter        = random_int(0, 9999);
            self::$_counter_second = $second;
        }
        elseif ( $second > self::$_counter_second )
        {
            self::$_counter_second = $second;
            self::$_counter_issued = 0;
        }

        while ( self::$_counter_issued >= 10000 )
        {
            usleep(1000);
            $second = time();

            if ( $second > self::$_counter_second )
            {
                self::$_counter_second = $second;
                self::$_counter_issued = 0;
            }
        }

        self::$_counter = (self::$_counter + 1) % 10000;
        self::$_counter_issued++;

        // Read live rather than cached: a worker that forks gets a new pid, and a cached one would
        // hand both halves the same lane
        $slot = (int) getmypid() % 1000;

        return date('ymdHis', self::$_counter_second)
            . str_pad((string) $slot, 3, '0', STR_PAD_LEFT)
            . str_pad((string) self::$_counter, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Substitute `{key}` placeholders with their values.
     *
     * ```php
     * str::format('hi {name}, {n} unread', ['name' => 'plato', 'n' => 3]);   // 'hi plato, 3 unread'
     * ```
     *
     * A placeholder with no matching key is **left standing** rather than blanked, so a typo shows
     * up in the output instead of quietly deleting text. A value with no string form -- an array, a
     * resource, an object without __toString -- is skipped for the same reason, and null becomes an
     * empty string. Replacement is simultaneous: a value that itself contains `{key}` is not
     * rescanned, so one substitution cannot feed the next.
     *
     * This is not the interpolation `log` does. That one shares the syntax and answers a different
     * question -- it also hands back the context keys the message did not consume, and renders a
     * Throwable specially -- so the two are not one function with a flag.
     *
     * @param  string             $template
     * @param  array<mixed, mixed> $values Keys are placeholder names, without the braces
     *
     * @return string
     */
    public static function format(string $template, array $values = []): string
    {
        $map = [];

        foreach ( $values as $key => $value )
        {
            $stringable = $value === null
                || is_scalar($value)
                || (is_object($value) && method_exists($value, '__toString'));

            if ( ! $stringable )
            {
                continue;
            }

            $map['{' . $key . '}'] = (string) $value;
        }

        return $map === [] ? $template : strtr($template, $map);
    }

    /**
     * Hide the middle of a value, keeping a few characters at each end.
     *
     * ```php
     * str::mask('13800138000', 3, 4);          // '138****8000'
     * str::mask('nb@example.com', 2, 11);      // 'nb*example.com'
     * ```
     *
     * Counts characters and not bytes, so a Chinese name masks the way it reads. One mask character
     * per hidden character: the length is not disguised, but nothing is invented either.
     *
     * A value too short to keep that much of is masked **whole**. That is the safe direction to
     * fail in -- `str::mask('12', 3, 4)` hands back `'**'` rather than the number it was asked to
     * hide.
     *
     * @param  string $value
     * @param  int    $keep_start Characters left visible at the front; negative counts as none
     * @param  int    $keep_end   Characters left visible at the end; negative counts as none
     * @param  string $mask       Repeated once per hidden character
     *
     * @return string
     * @throws InvalidArgumentException When $mask is empty, which would drop the hidden part
     *                                  instead of covering it
     */
    public static function mask(string $value, int $keep_start = 0, int $keep_end = 0, string $mask = '*'): string
    {
        if ( $mask === '' )
        {
            throw new InvalidArgumentException('str::mask needs a mask, otherwise it truncates');
        }

        $length = mb_strlen($value);

        if ( $length === 0 )
        {
            return '';
        }

        $keep_start = max(0, $keep_start);
        $keep_end   = max(0, $keep_end);

        if ( $keep_start + $keep_end >= $length )
        {
            $keep_start = 0;
            $keep_end   = 0;
        }

        return mb_substr($value, 0, $keep_start)
            . str_repeat($mask, $length - $keep_start - $keep_end)
            // mb_substr($value, -0) is the whole string, so the tail has to be asked for by name
            . ($keep_end > 0 ? mb_substr($value, -$keep_end) : '');
    }

    /**
     * Render a byte count with a unit.
     *
     * ```php
     * str::format_size(1536);            // '1.5 kb'
     * str::format_size(memory_get_usage());
     * ```
     *
     * @param  int|float $size     Bytes. Anything under one byte -- a negative span included -- is
     *                             reported in bytes rather than scaled
     * @param  int       $decimals
     *
     * @return string
     */
    public static function format_size($size, int $decimals = 2): string
    {
        $unit = ['b', 'kb', 'mb', 'gb', 'tb', 'pb'];

        // max($size, 1) keeps the logarithm defined, and the result indexes $unit so it has to be
        // an int within range. Unguarded, a zero length span reached pow(1024, -INF) and died on
        // DivisionByZeroError, which an @ on the line cannot suppress
        $i = min(count($unit) - 1, (int) floor(log(max($size, 1), 1024)));

        return round($size / pow(1024, $i), $decimals) . ' ' . $unit[$i];
    }

    /**
     * Map a string onto one of N buckets, the same way every time.
     *
     * ```php
     * 'user_' . str::bucket($uid, 64);              // which table
     * 'avatar/' . str::bucket($name, 256) . '/';    // which directory
     * ```
     *
     * Hashed rather than taken modulo directly, because the inputs are usually ids or filenames and
     * those arrive in runs -- consecutive inputs have to land in unrelated buckets, which is the
     * whole point of sharding.
     *
     * **The mapping is part of the API.** It is stable across php versions and machines, and
     * changing it would strand data already stored by it. That cuts the other way too: an
     * application arriving with its own hash has to keep its own hash for the data it already
     * placed, because this one puts those rows somewhere else.
     *
     * @param  string $value
     * @param  int    $buckets How many buckets to spread across
     *
     * @return int 0 to $buckets - 1
     * @throws InvalidArgumentException When $buckets is below 1
     */
    public static function bucket(string $value, int $buckets): int
    {
        if ( $buckets < 1 )
        {
            throw new InvalidArgumentException('str::bucket needs at least one bucket, got ' . $buckets);
        }

        // Seven hex digits is 28 bits, which is an int and not a float on a 32-bit build too --
        // eight would overflow there and the cast back would be meaningless
        return (int) hexdec(substr(sha1($value), 0, 7)) % $buckets;
    }

    /**
     * Colorize a PHP source string for display.
     *
     * @param  string $str The source text
     *
     * @return string
     */
    public static function highlight_code(string $str): string
    {
        $str = str_replace(['&lt;', '&gt;'], ['<', '>'], $str);
        $str = str_replace(array_keys(self::MARKERS), array_column(self::MARKERS, 0), $str);

        $str = highlight_string('<?php ' . $str . ' ?>', true);

        // 8.3 changed the markup highlight_string() returns. The s modifier is required: from 8.3
        // on, newlines in the source come through as newlines rather than <br />
        $str = preg_replace(
            '/<pre><code style="color: #([a-z0-9]+)">(.*)<\/code><\/pre>/is',
            '<code><span style="color: #$1">$2</span></code>',
            $str
        );

        $str = preg_replace(
            [
                '/<span style="color: #([a-z0-9]+)">&lt;\?php(&nbsp;| )/i',
                '/(<span style="color: #[a-z0-9]+">.*?)\?&gt;<\/span>/is',
                '/<span style="color: #[a-z0-9]+"\><\/span>/i'
            ],
            [
                '<span style="color: #$1">',
                "$1</span>",
                ''
            ],
            $str
        );

        return str_replace(array_column(self::MARKERS, 0), array_column(self::MARKERS, 1), $str);
    }

    /**
     * Strip control characters, keeping newline, carriage return and horizontal tab.
     *
     * The loop repeats until a pass removes nothing, because a stripped character can bring the
     * two halves of a split sequence back together into a new one.
     *
     * @param  string $str
     * @param  bool   $url_encoded Also strip the percent-encoded forms
     *
     * @return string
     */
    public static function remove_invisible_characters(string $str, bool $url_encoded = true): string
    {
        if ( $str === '' )
        {
            return $str;
        }

        $non_displayables = [];

        if ( $url_encoded )
        {
            $non_displayables[] = '/%0[0-8bcef]/i'; // url encoded 00-08, 11, 12, 14, 15
            $non_displayables[] = '/%1[0-9a-f]/i'; // url encoded 16-31
            $non_displayables[] = '/%7f/i'; // url encoded 127
        }

        $non_displayables[] = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/S'; // 00-08, 11, 12, 14-31, 127

        do
        {
            $replaced = preg_replace($non_displayables, '', $str, -1, $count);

            // A pcre failure leaves the last good value in place rather than returning null
            if ( $replaced === null )
            {
                break;
            }

            $str = $replaced;
        }
        while ( $count && $str !== '' );

        return $str;
    }
}
