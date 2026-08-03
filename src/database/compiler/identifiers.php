<?php

/**
 * Identifier quoting for a SQL grammar
 *
 * The half of the grammar that enforces its second rule: an identifier is a plain identifier. The
 * quoter rejects parentheses, quotes and operators instead of trying to parse them, which is what
 * the old quote_field() spent three hundred lines doing. Anything with a function call in it goes
 * through db::raw() and arrives here as an expression, which is passed through untouched.
 *
 * A trait rather than a collaborator object because a dialect overrides across every half of the
 * grammar at once (grammar\clickhouse replaces escape_literal, _compile_column and compile_select
 * in the same class), and PHP has no multiple inheritance to express that with.
 *
 * Expects the using class to provide substitute_prefix().
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\database\compiler;

use plato\database\expression;
use InvalidArgumentException;

trait identifiers
{
    /**
     * Operators the compiler will emit.
     *
     * An operator arrives as a string from the caller and lands in the statement unquoted, so it
     * is checked against this list rather than passed through. A dialect adds its own by
     * overriding the property.
     *
     * @var array<int, string>
     */
    protected array $_operators = [
        '=', '<', '>', '<=', '>=', '<>', '!=', '<=>',
        'like', 'not like', 'like binary', 'rlike', 'regexp', 'not regexp',
        'is', 'is not', '&', '|', '^', '<<', '>>', '->', 'find_in_set',
    ];

    /**
     * Quote an identifier, one segment at a time.
     *
     * @param  string|expression $value
     */
    public function wrap($value): string
    {
        if ( $value instanceof expression )
        {
            return $this->substitute_prefix($value->value());
        }

        $value = trim((string) $value);
        if ( $value === '*' )
        {
            return '*';
        }

        $segments = explode('.', $value);
        foreach ( $segments as $i => $segment )
        {
            $segments[$i] = $this->_wrap_segment(trim($segment));
        }

        return implode('.', $segments);
    }

    /**
     * Quote an identifier that may carry an alias: 'a.id AS uid', or ['a.id', 'uid'].
     *
     * @param  string|array|expression $value
     */
    public function wrap_aliased($value): string
    {
        if ( is_array($value) )
        {
            return $this->_wrap_column((string) $value[0]) . ' AS ' . $this->wrap((string) $value[1]);
        }

        if ( !$value instanceof expression && stripos((string) $value, ' as ') !== false )
        {
            $parts = preg_split('/\s+as\s+/i', trim((string) $value), 2);
            return $this->_wrap_column((string) $parts[0]) . ' AS ' . $this->wrap((string) $parts[1]);
        }

        return $value instanceof expression ? $this->wrap($value) : $this->_wrap_column((string) $value);
    }

    /**
     * Quote a table name, expanding #PB# first.
     *
     * @param  string|array|expression $table
     */
    public function wrap_table($table): string
    {
        if ( $table instanceof expression )
        {
            return $this->wrap($table);
        }

        if ( is_array($table) )
        {
            return $this->wrap_table((string) $table[0]) . ' AS ' . $this->wrap((string) $table[1]);
        }

        $table = $this->substitute_prefix(trim((string) $table));
        if ( stripos($table, ' as ') !== false )
        {
            $parts = preg_split('/\s+as\s+/i', $table, 2);
            return $this->wrap((string) $parts[0]) . ' AS ' . $this->wrap((string) $parts[1]);
        }

        return $this->wrap($table);
    }

    /**
     * Quote one segment of an identifier, rejecting anything that is not one.
     */
    protected function _wrap_segment(string $segment): string
    {
        if ( $segment === '*' )
        {
            return '*';
        }

        if ( $segment === '' || !preg_match('/^[A-Za-z0-9_$\x80-\xff]+$/', $segment) )
        {
            throw new InvalidArgumentException(
                "'{$segment}' is not an identifier. Wrap SQL fragments in db::raw() instead."
            );
        }

        return '`' . $segment . '`';
    }

    /**
     * Quote a column, which unlike a table may address a path inside a JSON document.
     *
     * @param  string|expression $column
     */
    protected function _wrap_column($column): string
    {
        if ( !$column instanceof expression && strpos((string) $column, '->') !== false )
        {
            $parts = explode('->', (string) $column, 2);
            return $this->wrap($parts[0]) . "->'$." . $this->_json_path($parts[1]) . "'";
        }

        return $this->wrap($column);
    }

    protected function _json_path(string $path): string
    {
        $path = trim($path, '$.');
        if ( $path === '' || !preg_match('/^[A-Za-z0-9_.\[\]*$\x80-\xff]+$/', $path) )
        {
            throw new InvalidArgumentException("'{$path}' is not a JSON path");
        }

        return $path;
    }

    protected function _operator(string $operator): string
    {
        $operator = trim($operator);
        if ( !in_array(strtolower($operator), $this->_operators, true) )
        {
            throw new InvalidArgumentException("'{$operator}' is not a known operator");
        }

        return strtoupper($operator);
    }
}
