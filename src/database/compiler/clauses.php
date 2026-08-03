<?php

/**
 * The individual clauses of a SELECT, and the SET list of an upsert
 *
 * One method per clause, each returning its own fragment with a leading space and appending
 * whatever it bound to the caller's bindings list. compile_select() in the grammar is then only
 * the order the fragments go in, which is what lets grammar\clickhouse reorder them (PREWHERE
 * before WHERE, SETTINGS at the end) by overriding compile_select() alone and reusing every
 * compiler here unchanged.
 *
 * A clause compiler must append to $bindings in the same order its placeholders appear in the
 * string it returns; the two are read together and nothing checks that they line up.
 *
 * Expects the using class to provide the identifiers and values traits.
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\database\compiler;

use plato\database\expression;
use plato\database\query;
use InvalidArgumentException;

trait clauses
{
    protected function _compile_aggregate(query $q): string
    {
        $function = strtoupper((string) $q->aggregate['function']);
        $column   = $q->aggregate['column'];
        $inner    = $column === '*' ? '*' : $this->_wrap_column((string) $column);
        $distinct = $q->distinct && $column !== '*' ? 'DISTINCT ' : '';

        return $function . '(' . $distinct . $inner . ') AS ' . $this->wrap('aggregate');
    }

    /**
     * The FROM target: a table, or a set of unions standing in for one.
     *
     * @param  array<int, mixed> $bindings
     */
    protected function _compile_source(query $q, array &$bindings): string
    {
        if ( $q->unions && $q->union_alias !== null )
        {
            $parts = [];
            foreach ( $q->unions as $i => $union )
            {
                foreach ( $union['bindings'] as $binding )
                {
                    $bindings[] = $binding;
                }

                $parts[] = ($i === 0 ? '' : ' UNION ' . ($union['all'] ? 'ALL ' : '')) . '(' . $union['sql'] . ')';
            }

            return '(' . implode('', $parts) . ') AS ' . $this->wrap($q->union_alias);
        }

        return $this->wrap_table($q->table);
    }

    /**
     * @param  array<int, mixed> $bindings
     */
    protected function _compile_joins(query $q, array &$bindings): string
    {
        $sql = '';
        foreach ( $q->joins as $join )
        {
            // STRAIGHT_JOIN is a join keyword in its own right, so it carries the JOIN itself and
            // MySQL rejects a second one. Every other type (INNER, LEFT OUTER, ...) is given one.
            $type = str_ends_with($join['type'], 'JOIN') ? $join['type'] : $join['type'] . ' JOIN';

            $sql .= ' ' . $type . ' ' . $this->wrap_table($join['table']);

            $conditions = [];
            foreach ( $join['conditions'] as $i => $condition )
            {
                $boolean = $i === 0 ? '' : $condition['boolean'] . ' ';
                if ( $condition['type'] === 'value' )
                {
                    $conditions[] = $boolean . $this->_wrap_column($condition['first'])
                        . ' ' . $this->_operator($condition['operator'])
                        . ' ' . $this->parameter($condition['second'], $bindings);
                    continue;
                }

                $conditions[] = $boolean . $this->_wrap_column($condition['first'])
                    . ' ' . $this->_operator($condition['operator'])
                    . ' ' . $this->_wrap_column($condition['second']);
            }

            if ( $conditions )
            {
                $sql .= ' ON (' . implode(' ', $conditions) . ')';
            }
        }

        return $sql;
    }

    /**
     * WHERE and HAVING share every clause shape, so they share the compiler.
     *
     * @param  array<int, array<string, mixed>> $wheres
     * @param  array<int, mixed>                $bindings
     * @param  string                           $keyword WHERE or HAVING
     */
    protected function _compile_wheres(array $wheres, array &$bindings, string $keyword): string
    {
        if ( !$wheres )
        {
            return '';
        }

        $sql = '';
        foreach ( $wheres as $i => $where )
        {
            $sql .= $i === 0 ? '' : ' ' . $where['boolean'] . ' ';
            $sql .= $this->_compile_where($where, $bindings);
        }

        return ' ' . $keyword . ' ' . $sql;
    }

    /**
     * @param  array<string, mixed> $where
     * @param  array<int, mixed>    $bindings
     */
    protected function _compile_where(array $where, array &$bindings): string
    {
        switch ( $where['type'] )
        {
            case 'basic':
                return $this->_wrap_column($where['column'])
                    . ' ' . $this->_operator($where['operator'])
                    . ' ' . $this->parameter($where['value'], $bindings);

            case 'column':
                return $this->_wrap_column($where['first'])
                    . ' ' . $this->_operator($where['operator'])
                    . ' ' . $this->_wrap_column($where['second']);

            case 'in':
                return $this->_compile_where_in($where, $bindings);

            case 'null':
                return $this->_wrap_column($where['column']) . ($where['not'] ? ' IS NOT NULL' : ' IS NULL');

            case 'between':
                return $this->_wrap_column($where['column'])
                    . ($where['not'] ? ' NOT BETWEEN ' : ' BETWEEN ')
                    . $this->parameter($where['min'], $bindings)
                    . ' AND ' . $this->parameter($where['max'], $bindings);

            case 'find_in_set':
                return 'FIND_IN_SET(' . $this->parameter($where['value'], $bindings)
                    . ', ' . $this->_wrap_column($where['column']) . ')';

            case 'nested':
                return '(' . $this->_compile_nested($where['wheres'], $bindings) . ')';

            case 'raw':
                foreach ( $where['bindings'] as $binding )
                {
                    $bindings[] = $binding;
                }

                return $this->substitute_prefix($where['sql']);
        }

        throw new InvalidArgumentException("Unknown where clause: {$where['type']}");
    }

    /**
     * @param  array<string, mixed> $where
     * @param  array<int, mixed>    $bindings
     */
    protected function _compile_where_in(array $where, array &$bindings): string
    {
        $values = $where['values'];
        if ( $values instanceof expression || $values instanceof query )
        {
            $rendered = $this->parameter($values, $bindings);
            $rendered = $values instanceof expression ? '(' . $rendered . ')' : $rendered;

            return $this->_wrap_column($where['column']) . ($where['not'] ? ' NOT IN ' : ' IN ') . $rendered;
        }

        if ( !$values )
        {
            // An empty IN () is a syntax error, so emit the constant it means instead. NOT IN on an
            // empty set matches everything, IN on one matches nothing
            return $where['not'] ? '1 = 1' : '1 = 0';
        }

        $placeholders = [];
        foreach ( $values as $value )
        {
            $placeholders[] = $this->parameter($value, $bindings);
        }

        return $this->_wrap_column($where['column'])
            . ($where['not'] ? ' NOT IN (' : ' IN (') . implode(', ', $placeholders) . ')';
    }

    /**
     * @param  array<int, array<string, mixed>> $wheres
     * @param  array<int, mixed>                $bindings
     */
    protected function _compile_nested(array $wheres, array &$bindings): string
    {
        $sql = '';
        foreach ( $wheres as $i => $where )
        {
            $sql .= $i === 0 ? '' : ' ' . $where['boolean'] . ' ';
            $sql .= $this->_compile_where($where, $bindings);
        }

        return $sql;
    }

    protected function _compile_groups(query $q): string
    {
        if ( !$q->groups )
        {
            return '';
        }

        return ' GROUP BY ' . implode(', ', array_map([$this, '_wrap_column'], $q->groups));
    }

    /**
     * @param  array<int, mixed> $bindings
     */
    protected function _compile_unions(query $q, array &$bindings): string
    {
        if ( !$q->unions || $q->union_alias !== null )
        {
            return '';
        }

        $sql = '';
        foreach ( $q->unions as $union )
        {
            foreach ( $union['bindings'] as $binding )
            {
                $bindings[] = $binding;
            }

            $sql .= ' UNION ' . ($union['all'] ? 'ALL ' : '') . '(' . $union['sql'] . ')';
        }

        return $sql;
    }

    protected function _compile_orders(query $q): string
    {
        if ( !$q->orders )
        {
            return '';
        }

        $sort = [];
        foreach ( $q->orders as $order )
        {
            if ( isset($order['expression']) )
            {
                $sort[] = $this->substitute_prefix((string) $order['expression']);
                continue;
            }

            $sort[] = $this->_wrap_column($order['column']) . ' ' . $order['direction'];
        }

        return ' ORDER BY ' . implode(', ', $sort);
    }

    protected function _compile_limit(query $q): string
    {
        $limit = $q->limit;
        if ( $q->max_limit !== null )
        {
            // Opt in, and off by default. The previous version forced 300 on every non CLI SELECT,
            // which truncated results with nothing in the statement to show why
            $limit = $limit === null ? $q->max_limit : min($limit, $q->max_limit);
        }

        if ( $limit === null && $q->offset === null )
        {
            return '';
        }

        $sql = '';
        if ( $limit !== null )
        {
            $sql .= ' LIMIT ' . max(0, (int) $limit);
        }
        elseif ( $q->offset !== null )
        {
            // MySQL has no OFFSET without LIMIT
            $sql .= ' LIMIT 18446744073709551615';
        }

        if ( $q->offset !== null )
        {
            $sql .= ' OFFSET ' . max(0, (int) $q->offset);
        }

        return $sql;
    }

    protected function _compile_lock(query $q): string
    {
        if ( $q->lock === null )
        {
            return '';
        }

        return $q->lock === 'share' ? ' LOCK IN SHARE MODE' : ' FOR UPDATE';
    }

    /**
     * @param  array<int|string, mixed> $columns Column name, or column => value to force a value
     * @param  array<int, mixed>        $bindings
     */
    protected function _compile_upsert_set(array $columns, array &$bindings): string
    {
        $set = [];
        foreach ( $columns as $column => $value )
        {
            if ( is_int($column) )
            {
                // Plain column name: take whatever the INSERT tried to write for it. VALUES() is
                // soft deprecated as of MySQL 8.0.20 but is the only spelling 5.7 understands
                $wrapped = $this->wrap($value);
                $set[]   = $wrapped . ' = VALUES(' . $wrapped . ')';
                continue;
            }

            $set[] = $this->wrap($column) . ' = ' . $this->parameter($value, $bindings);
        }

        return implode(', ', $set);
    }
}
