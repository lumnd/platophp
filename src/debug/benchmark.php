<?php

/**
 * Named time and memory marks used to profile a request
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\debug;

use plato\str;

/**
 * Records named points in a request and reports the span between any two of them.
 *
 * ```php
 * benchmark::mark('render_start');
 * // ...
 * benchmark::mark('render_end');
 * echo benchmark::elapsed_time('render_start', 'render_end');   // "0.0123"
 * echo benchmark::elapsed_memory('render_start', 'render_end'); // "1.25 mb"
 * ```
 *
 * Three rules that the signatures do not show:
 *
 * - Reading a span whose end point was never marked stamps that end point on the spot and
 *   keeps it. That is the only reason `total_execution_end` exists: `plato::run()` marks
 *   `total_execution_start` and nothing ever marks the end, so the first read of the span --
 *   `tpl::_replace_benchmarks()` -- fixes it. Later readers get that stored value, not a
 *   fresh reading.
 * - An empty `$point1` returns a placeholder token instead of a number.
 *   `tpl::_replace_benchmarks()` substitutes `{elapsed_time}` and `{memory_usage}` into the
 *   finished page, so a template can carry the token and still get the real figure. The token
 *   has to be spelled the way `tpl` spells it or nothing replaces it.
 * - A `$point1` that was never marked returns an empty string. Nothing else returns one: a
 *   zero-length span formats to `0.0000` / `0 b`.
 *
 * `$marker` is public because `profiler` walks it to pair up every `*_start` / `*_end` key.
 */
class benchmark
{
    /** @var array<string, array<string, float|int>> */
    public static $marker = [];

    /**
     * @param string $name
     * @param float|string $value Pre-recorded reading to store instead of sampling the
     *                            process. It is written to both `time` and `mem`, so a mark
     *                            made this way is only meaningful to elapsed_time().
     * @return void
     */
    public static function mark($name, $value = '')
    {
        self::$marker[$name]['time'] = is_float($value) ? $value : microtime(true);
        self::$marker[$name]['mem']  = is_float($value) ? $value : memory_get_usage();
    }

    /**
     * @param string $point1
     * @param string $point2
     * @param int $decimals
     * @return string Seconds, the `{elapsed_time}` token when $point1 is empty, or an empty
     *                string when $point1 was never marked
     */
    public static function elapsed_time($point1 = '', $point2 = '', $decimals = 4)
    {
        if ( $point1 === '' )
        {
            return '{elapsed_time}';
        }

        if ( ! isset(self::$marker[$point1]['time']) )
        {
            return '';
        }

        if ( ! isset(self::$marker[$point2]['time']) )
        {
            self::$marker[$point2]['time'] = microtime(true);
        }

        return number_format(self::$marker[$point2]['time'] - self::$marker[$point1]['time'], $decimals);
    }

    /**
     * @param string $point1
     * @param string $point2
     * @param int $decimals
     * @return string Size with unit, the `{memory_usage}` token when $point1 is empty, or
     *                an empty string when $point1 was never marked
     */
    public static function elapsed_memory($point1 = '', $point2 = '', $decimals = 2)
    {
        if ( $point1 === '' )
        {
            // The name tpl::_replace_benchmarks() looks for.
            return '{memory_usage}';
        }

        if ( ! isset(self::$marker[$point1]['mem']) )
        {
            return '';
        }

        if ( ! isset(self::$marker[$point2]['mem']) )
        {
            self::$marker[$point2]['mem'] = memory_get_usage();
        }

        return str::format_size(self::$marker[$point2]['mem'] - self::$marker[$point1]['mem'], $decimals);
    }
}
