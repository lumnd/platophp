<?php

/**
 * SQL grammar: turns a query object into a statement plus the values it binds
 *
 * MySQL is the baseline dialect; another SQL engine subclasses this and overrides only what
 * differs (see grammar\clickhouse). What is left in this file is statement assembly -- the order
 * the clauses go in and the keywords around them. The pieces live in four traits, split by what
 * they know about rather than by which statement uses them:
 *
 *   compiler\identifiers  quoting a name, and the operator whitelist
 *   compiler\values       turning a value into a placeholder, and back again for log output
 *   compiler\clauses      one method per SELECT clause
 *   compiler\ddl          CREATE / ALTER TABLE and the schema introspection queries
 *
 * Traits and not collaborator objects: a dialect overrides across all four at once -- clickhouse
 * replaces escape_literal(), _compile_column() and compile_select() in one class -- and PHP has no
 * multiple inheritance to build that out of separate hierarchies. Flattened into this class they
 * keep `$this`, so both `parent::` and a subclass override behave exactly as they did when every
 * method was written out here.
 *
 * Two rules hold everywhere in here:
 *
 * 1. A value never reaches the returned statement. Every one of them leaves as a `?` and is
 *    appended to the bindings list in the order the placeholders appear, so the driver can hand
 *    both to a server side prepared statement.
 * 2. An identifier is a plain identifier. The quoter rejects parentheses, quotes and operators
 *    instead of trying to parse them. Anything with a function call in it goes through db::raw().
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\database;

use plato\database\compiler\clauses;
use plato\database\compiler\ddl;
use plato\database\compiler\identifiers;
use plato\database\compiler\values;
use InvalidArgumentException;

class grammar
{
    use identifiers;
    use values;
    use clauses;
    use ddl;

    /**
     * Value substituted for the #PB# table prefix placeholder
     */
    protected string $_prefix = '';

    /**
     * @param string $prefix Value #PB# expands to
     */
    public function __construct(string $prefix = '')
    {
        $this->_prefix = $prefix;
    }

    public function prefix(): string
    {
        return $this->_prefix;
    }

    /**
     * Expand the #PB# table prefix placeholder.
     *
     * #!PB# is the escape for the rare case where the literal string has to survive.
     *
     * @param  string $sql Statement or table name
     */
    public function substitute_prefix(string $sql): string
    {
        if ( strpos($sql, '#PB#') === false && strpos($sql, '#!PB#') === false )
        {
            return $sql;
        }

        // Park the escaped form out of the way while the real one expands, then put it back
        $sql = str_replace('#!PB#', "\x00PB\x00", $sql);
        $sql = str_replace('#PB#', $this->_prefix, $sql);

        return str_replace("\x00PB\x00", '#PB#', $sql);
    }

    /**
     * @return array{0: string, 1: array<int, mixed>} Statement and its bindings
     */
    public function compile_select(query $q): array
    {
        $bindings = [];

        $sql = 'SELECT ';
        if ( $q->aggregate !== null )
        {
            $sql .= $this->_compile_aggregate($q);
        }
        else
        {
            $sql .= $q->distinct ? 'DISTINCT ' : '';
            $sql .= $q->columns
                ? implode(', ', array_map([$this, 'wrap_aliased'], $q->columns))
                : '*';
        }

        $sql .= ' FROM ' . $this->_compile_source($q, $bindings);

        if ( $q->index_hint !== null )
        {
            $sql .= ' FORCE INDEX (' . implode(', ', array_map([$this, 'wrap'], $q->index_hint)) . ')';
        }

        $sql .= $this->_compile_joins($q, $bindings);
        $sql .= $this->_compile_wheres($q->wheres, $bindings, 'WHERE');
        $sql .= $this->_compile_groups($q);
        $sql .= $this->_compile_wheres($q->havings, $bindings, 'HAVING');
        $sql .= $this->_compile_unions($q, $bindings);
        $sql .= $this->_compile_orders($q);
        $sql .= $this->_compile_limit($q);
        $sql .= $this->_compile_lock($q);

        return [$sql, $bindings];
    }

    /**
     * @param  array<int, array<string, mixed>> $rows   One or more column => value maps
     * @param  array<int, string>               $update Columns to overwrite on a duplicate key
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function compile_insert(query $q, array $rows, array $update = []): array
    {
        if ( !$rows )
        {
            throw new InvalidArgumentException('insert() needs at least one row');
        }

        $columns  = array_keys($rows[0]);
        $bindings = [];
        $groups   = [];
        foreach ( $rows as $row )
        {
            $values = [];
            foreach ( $columns as $column )
            {
                // A row that is missing one of the first row's columns writes NULL there rather
                // than shifting every later value one column to the left
                $values[] = $this->parameter($row[$column] ?? null, $bindings);
            }

            $groups[] = '(' . implode(', ', $values) . ')';
        }

        $sql = 'INSERT ' . ($q->ignore ? 'IGNORE ' : '') . 'INTO ' . $this->wrap_table($q->table)
            . ' (' . implode(', ', array_map([$this, 'wrap'], $columns)) . ')'
            . ' VALUES ' . implode(', ', $groups);

        if ( $update )
        {
            $sql .= ' ON DUPLICATE KEY UPDATE ' . $this->_compile_upsert_set($update, $bindings);
        }

        return [$sql, $bindings];
    }

    /**
     * @param  array<string,mixed> $values
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function compile_update(query $q, array $values): array
    {
        if ( !$values )
        {
            throw new InvalidArgumentException('update() needs at least one column');
        }

        $bindings = [];
        $sql      = 'UPDATE ' . ($q->ignore ? 'IGNORE ' : '') . $this->wrap_table($q->table);
        $sql     .= $this->_compile_joins($q, $bindings);

        $set = [];
        foreach ( $values as $column => $value )
        {
            $set[] = $this->_wrap_column($column) . ' = ' . $this->parameter($value, $bindings);
        }

        $sql .= ' SET ' . implode(', ', $set);
        $sql .= $this->_compile_wheres($q->wheres, $bindings, 'WHERE');
        $sql .= $this->_compile_orders($q);
        $sql .= $q->limit !== null ? ' LIMIT ' . (int) $q->limit : '';

        return [$sql, $bindings];
    }

    /**
     * Write several rows in one statement, each column becoming a CASE over the key column.
     *
     * Per column rather than per row: rows that set different subsets of the columns would
     * otherwise blank out whatever a given row left unset, which is what the ELSE arm guards.
     *
     * @param  array<int, array<string, mixed>> $rows
     * @param  string                           $key Column the CASE compares on
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function compile_update_batch(query $q, array $rows, string $key): array
    {
        $columns = [];
        $keys    = [];
        foreach ( $rows as $row )
        {
            if ( !array_key_exists($key, $row) )
            {
                throw new InvalidArgumentException("update_batch(): every row needs a '{$key}' value");
            }

            $keys[] = $row[$key];
            foreach ( $row as $column => $value )
            {
                if ( $column !== $key )
                {
                    $columns[$column][] = [$row[$key], $value];
                }
            }
        }

        if ( !$columns )
        {
            throw new InvalidArgumentException('update_batch() needs at least one column besides the key');
        }

        $bindings = [];
        $set      = [];
        foreach ( $columns as $column => $pairs )
        {
            $wrapped = $this->_wrap_column($column);
            $case    = $wrapped . ' = (CASE ' . $this->_wrap_column($key);
            foreach ( $pairs as $pair )
            {
                $case .= ' WHEN ' . $this->parameter($pair[0], $bindings)
                    . ' THEN ' . $this->parameter($pair[1], $bindings);
            }

            $set[] = $case . ' ELSE ' . $wrapped . ' END)';
        }

        $sql = 'UPDATE ' . $this->wrap_table($q->table) . ' SET ' . implode(', ', $set);

        // The key list is the statement's own WHERE, added after whatever the caller asked for
        $wheres   = $q->wheres;
        $wheres[] = [
            'type'    => 'in',
            'boolean' => 'AND',
            'column'  => $key,
            'values'  => array_values(array_unique($keys)),
            'not'     => false,
        ];

        $sql .= $this->_compile_wheres($wheres, $bindings, 'WHERE');

        return [$sql, $bindings];
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function compile_delete(query $q): array
    {
        $bindings = [];
        $sql      = 'DELETE ' . ($q->ignore ? 'IGNORE ' : '') . 'FROM ' . $this->wrap_table($q->table);
        $sql     .= $this->_compile_wheres($q->wheres, $bindings, 'WHERE');
        $sql     .= $this->_compile_orders($q);
        $sql     .= $q->limit !== null ? ' LIMIT ' . (int) $q->limit : '';

        return [$sql, $bindings];
    }

    /**
     * Merge values into a JSON column without reading it first.
     *
     * @param  array<string,mixed> $pairs  Path => value, the path relative to the document root
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function compile_update_json(query $q, string $column, array $pairs): array
    {
        if ( !$pairs )
        {
            throw new InvalidArgumentException('update_json() needs at least one path');
        }

        $bindings = [];
        $wrapped  = $this->wrap($column);
        $args     = [$wrapped];
        foreach ( $pairs as $path => $value )
        {
            // An array becomes a document rather than a string, so JSON_SET nests it
            $value = is_array($value) ? new expression('CAST(? AS JSON)', [
                json_encode($value, JSON_UNESCAPED_UNICODE),
            ]) : $value;

            $args[] = "'$." . $this->_json_path($path) . "'";
            $args[] = $this->parameter($value, $bindings);
        }

        $sql  = 'UPDATE ' . $this->wrap_table($q->table)
            . ' SET ' . $wrapped . ' = JSON_SET(' . implode(', ', $args) . ')';
        $sql .= $this->_compile_wheres($q->wheres, $bindings, 'WHERE');

        return [$sql, $bindings];
    }
}
