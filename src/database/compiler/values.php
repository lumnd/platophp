<?php

/**
 * Value binding and escaping for a SQL grammar
 *
 * The half of the grammar that enforces its first rule: a value never reaches the returned
 * statement. Every one of them leaves parameter() as a `?` and is appended to the bindings list in
 * the order the placeholders appear, so the driver can hand both to a server side prepared
 * statement. The previous generation of this code interpolated values into the SQL wrapped in
 * \x02 / \x03 sentinels and pulled them back out with a regex before executing; that round trip
 * silently stripped those two bytes from binary data and put the safety of every query on the
 * precision of one pattern.
 *
 * interpolate() and escape_literal() are the way back for engines with no prepared statements of
 * their own (the ClickHouse HTTP interface has none). They are not on the MySQL execution path,
 * and a caller that runs interpolate()'s output against MySQL is on its own.
 *
 * Expects the using class to provide substitute_prefix() and compile_select().
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\database\compiler;

use plato\database\expression;
use plato\database\query;

trait values
{
    /**
     * Register a value and return the placeholder that stands in for it.
     *
     * @param  mixed              $value
     * @param  array<int, mixed>  $bindings Collected in statement order
     */
    public function parameter($value, array &$bindings): string
    {
        if ( $value instanceof expression )
        {
            foreach ( $value->bindings() as $binding )
            {
                $bindings[] = $binding;
            }

            return $this->substitute_prefix($value->value());
        }

        if ( $value instanceof query )
        {
            list($sql, $sub) = $this->compile_select($value);
            foreach ( $sub as $binding )
            {
                $bindings[] = $binding;
            }

            return '(' . $sql . ')';
        }

        $bindings[] = $this->_normalise($value);
        return '?';
    }

    /**
     * Put bound values back into a statement.
     *
     * For log output and for engines with no prepared statements of their own. The MySQL driver
     * never executes the result of this, and a caller that does is on its own.
     *
     * @param  array<int, mixed> $bindings
     */
    public function interpolate(string $sql, array $bindings): string
    {
        if ( !$bindings )
        {
            return $sql;
        }

        $i = 0;
        return (string) preg_replace_callback(
            '/\?/',
            function () use (&$i, $bindings) {
                return array_key_exists($i, $bindings) ? $this->escape_literal($bindings[$i++]) : '?';
            },
            $sql
        );
    }

    /**
     * Render a value as a SQL literal.
     *
     * @param  mixed $value
     */
    public function escape_literal($value): string
    {
        if ( $value === null )
        {
            return 'NULL';
        }

        if ( is_bool($value) )
        {
            return $value ? '1' : '0';
        }

        if ( is_int($value) || is_float($value) )
        {
            return (string) $value;
        }

        return "'" . str_replace(
            ['\\', "\0", "\n", "\r", "'", '"', "\x1a"],
            ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'],
            (string) $value
        ) . "'";
    }

    /**
     * Reduce a value to something a driver can bind.
     *
     * @param  mixed $value
     * @return mixed
     */
    protected function _normalise($value)
    {
        if ( is_array($value) )
        {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if ( $value instanceof \DateTimeInterface )
        {
            return $value->format('Y-m-d H:i:s');
        }

        if ( is_bool($value) )
        {
            return (int) $value;
        }

        return $value;
    }
}
