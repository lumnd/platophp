<?php

/**
 * Query builder: the fluent surface, and nothing else
 *
 * A query holds its own builder state and hands it to a grammar to compile and to a connection to
 * run. It opens no socket of its own and keeps no result, so it can be built and asserted on
 * without a reachable server.
 *
 * Every db::table() call returns a fresh instance. Terminal methods (get, first, insert, update,
 * delete, ...) run the query and say in their return type what they hand back; there is no
 * execute() and no as_row() / as_field() mode flags to set beforehand.
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\database;

use Closure;
use Generator;
use InvalidArgumentException;

class query
{
    /**
     * Table this query reads or writes, #PB# not yet expanded
     *
     * @var string|array|expression
     */
    public $table = '';

    /**
     * Columns to select; empty means every one
     *
     * @var array<int, string|array|expression>
     */
    public array $columns = [];

    public bool $distinct = false;

    /**
     * Aggregate to select instead of columns: ['function' => 'COUNT', 'column' => '*']
     *
     * @var array<string, mixed>|null
     */
    public ?array $aggregate = null;

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $joins = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $wheres = [];

    /**
     * @var array<int, string|expression>
     */
    public array $groups = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $havings = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $orders = [];

    public ?int $limit = null;

    public ?int $offset = null;

    /**
     * Ceiling the connection's max_select_limit puts on this query, null when unset
     */
    public ?int $max_limit = null;

    /**
     * @var array<int, array{sql: string, bindings: array<int, mixed>, all: bool}>
     */
    public array $unions = [];

    /**
     * Alias the unions take when they stand in for the FROM target
     */
    public ?string $union_alias = null;

    /**
     * @var array<int, string>|null
     */
    public ?array $index_hint = null;

    /**
     * 'update' for FOR UPDATE, 'share' for a shared read lock, null for neither
     */
    public ?string $lock = null;

    public bool $ignore = false;

    /**
     * Whether to read from the write connection
     */
    public bool $use_master = false;

    /**
     * Settings only some dialects understand, so the fluent surface stays the same everywhere.
     * See the dialect's grammar for the keys it reads (grammar\clickhouse: final, prewhere,
     * sample, settings).
     *
     * @var array<string, mixed>
     */
    public array $options = [];

    protected connection $_connection;

    public function __construct(connection $connection, string $table = '')
    {
        $this->_connection = $connection;
        $this->table       = $table;
        $this->max_limit   = $connection->config('max_select_limit');
    }

    public function connection(): connection
    {
        return $this->_connection;
    }

    /**
     * Columns to read. A string may carry an alias ('a.id AS uid') but not a function call --
     * wrap those in db::raw().
     *
     * @param  string|array|expression ...$columns
     * @return $this
     */
    public function select(...$columns)
    {
        foreach ( $columns as $column )
        {
            if ( is_array($column) && !isset($column[1]) )
            {
                foreach ( $column as $one )
                {
                    $this->columns[] = $one;
                }

                continue;
            }

            $this->columns[] = $column;
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function distinct(bool $value = true)
    {
        $this->distinct = $value;

        return $this;
    }

    /**
     * @param  string|array|expression $table
     * @return $this
     */
    public function from($table)
    {
        $this->table = $table;

        return $this;
    }

    /**
     * Add a condition.
     *
     *     ->where('id', 5)                      id = 5
     *     ->where('age', '>', 18)               age > 18
     *     ->where(['status' => 1, 'type' => 2]) both, AND
     *     ->where([['a', '>', 1], ['b', '=', 2, 'OR']])
     *     ->where(fn($q) => $q->where('a', 1)->or_where('b', 2))    ( ... )
     *     ->where('id', null)                   id IS NULL
     *     ->where('id', [1, 2, 3])              id IN (1, 2, 3)
     *
     * @param  string|array|Closure|expression $column
     * @param  mixed                           $operator
     * @param  mixed                           $value
     * @param  string                          $boolean
     * @return $this
     */
    public function where($column, $operator = null, $value = null, string $boolean = 'AND')
    {
        if ( $column instanceof Closure )
        {
            return $this->_add_nested($column, $boolean);
        }

        if ( $column instanceof expression )
        {
            return $this->where_raw($column->value(), $column->bindings(), $boolean);
        }

        if ( is_array($column) )
        {
            foreach ( $column as $key => $condition )
            {
                if ( is_array($condition) )
                {
                    $this->where(
                        $condition[0],
                        $condition[1] ?? null,
                        $condition[2] ?? null,
                        isset($condition[3]) ? strtoupper((string) $condition[3]) : 'AND'
                    );

                    continue;
                }

                $this->where($key, '=', $condition, $boolean);
            }

            return $this;
        }

        // where('id', 5): two arguments mean the operator was left out
        if ( func_num_args() === 2 )
        {
            $value    = $operator;
            $operator = '=';
        }

        return $this->_add_basic((string) $column, (string) $operator, $value, $boolean);
    }

    /**
     * @param  string|array|Closure|expression $column
     * @param  mixed                           $operator
     * @param  mixed                           $value
     * @return $this
     */
    public function or_where($column, $operator = null, $value = null)
    {
        if ( func_num_args() === 2 )
        {
            return $this->where($column, '=', $operator, 'OR');
        }

        return $this->where($column, $operator, $value, 'OR');
    }

    /**
     * @param  array|expression|query $values
     * @param  bool               $not
     * @return $this
     */
    public function where_in(string $column, $values, string $boolean = 'AND', bool $not = false)
    {
        $this->wheres[] = [
            'type'    => 'in',
            'boolean' => $boolean,
            'column'  => $column,
            'values'  => is_array($values) ? array_values($values) : $values,
            'not'     => $not,
        ];

        return $this;
    }

    /**
     * @param  array|expression|query $values
     * @return $this
     */
    public function where_not_in(string $column, $values, string $boolean = 'AND')
    {
        return $this->where_in($column, $values, $boolean, true);
    }

    /**
     * @param  bool   $not
     * @return $this
     */
    public function where_null(string $column, string $boolean = 'AND', bool $not = false)
    {
        $this->wheres[] = [
            'type'    => 'null',
            'boolean' => $boolean,
            'column'  => $column,
            'not'     => $not,
        ];

        return $this;
    }

    /**
     * @return $this
     */
    public function where_not_null(string $column, string $boolean = 'AND')
    {
        return $this->where_null($column, $boolean, true);
    }

    /**
     * @param  mixed  $min
     * @param  mixed  $max
     * @param  bool   $not
     * @return $this
     */
    public function where_between(string $column, $min, $max, string $boolean = 'AND', bool $not = false)
    {
        $this->wheres[] = [
            'type'    => 'between',
            'boolean' => $boolean,
            'column'  => $column,
            'min'     => $min,
            'max'     => $max,
            'not'     => $not,
        ];

        return $this;
    }

    /**
     * @param  mixed  $min
     * @param  mixed  $max
     * @return $this
     */
    public function where_not_between(string $column, $min, $max, string $boolean = 'AND')
    {
        return $this->where_between($column, $min, $max, $boolean, true);
    }

    /**
     * Compare two columns rather than a column and a value.
     *
     * @return $this
     */
    public function where_column(string $first, string $operator, string $second, string $boolean = 'AND')
    {
        $this->wheres[] = [
            'type'     => 'column',
            'boolean'  => $boolean,
            'first'    => $first,
            'operator' => $operator,
            'second'   => $second,
        ];

        return $this;
    }

    /**
     * @param  string $column Column holding the set
     * @param  mixed  $value  Member to look for
     * @return $this
     */
    public function where_find_in_set(string $column, $value, string $boolean = 'AND')
    {
        $this->wheres[] = [
            'type'    => 'find_in_set',
            'boolean' => $boolean,
            'column'  => $column,
            'value'   => $value,
        ];

        return $this;
    }

    /**
     * A condition the compiler passes through, placeholders and all.
     *
     * @param  array<int, mixed> $bindings
     * @param  string            $boolean
     * @return $this
     */
    public function where_raw(string $sql, array $bindings = [], string $boolean = 'AND')
    {
        $this->wheres[] = [
            'type'     => 'raw',
            'boolean'  => $boolean,
            'sql'      => $sql,
            'bindings' => array_values($bindings),
        ];

        return $this;
    }

    /**
     * Join a table.
     *
     *     ->join('#PB#_profile AS p', 'p.uid', '=', 'u.uid')
     *     ->join('#PB#_profile AS p', [['p.uid', '=', 'u.uid'], ['p.status', '=', 1, 'AND', 'value']])
     *
     * Both sides of a condition are read as column names. To compare against a value, pass
     * 'value' as the fifth element of the condition, or add a where().
     *
     * @param  string|array|expression $table
     * @param  string|array            $first
     * @param  string|null             $second
     * @param  string                  $type
     * @return $this
     */
    public function join($table, $first, ?string $operator = null, ?string $second = null, string $type = 'INNER')
    {
        $type = strtoupper(trim($type));
        if ( !in_array($type, ['INNER', 'LEFT', 'RIGHT', 'LEFT OUTER', 'RIGHT OUTER', 'CROSS', 'STRAIGHT_JOIN'], true) )
        {
            throw new InvalidArgumentException("'{$type}' is not a join type");
        }

        $conditions = [];
        foreach ( is_array($first) ? $first : [[$first, $operator, $second]] as $condition )
        {
            $conditions[] = [
                'first'    => $condition[0],
                'operator' => $condition[1] ?? '=',
                'second'   => $condition[2] ?? null,
                'boolean'  => isset($condition[3]) ? strtoupper((string) $condition[3]) : 'AND',
                'type'     => ($condition[4] ?? 'column') === 'value' ? 'value' : 'column',
            ];
        }

        $this->joins[] = [
            'type'       => $type,
            'table'      => $table,
            'conditions' => $conditions,
        ];

        return $this;
    }

    /**
     * @param  string|array|expression $table
     * @param  string|array            $first
     * @param  string|null             $second
     * @return $this
     */
    public function left_join($table, $first, ?string $operator = null, ?string $second = null)
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    /**
     * @param  string|array|expression $table
     * @param  string|array            $first
     * @param  string|null             $second
     * @return $this
     */
    public function right_join($table, $first, ?string $operator = null, ?string $second = null)
    {
        return $this->join($table, $first, $operator, $second, 'RIGHT');
    }

    /**
     * @param  string|array<int, string|expression>|expression ...$columns
     * @return $this
     */
    public function group_by(...$columns)
    {
        foreach ( $columns as $column )
        {
            foreach ( is_array($column) ? $column : [$column] as $one )
            {
                $this->groups[] = $one;
            }
        }

        return $this;
    }

    /**
     * @param  string|array|Closure|expression $column
     * @param  mixed                           $operator
     * @param  mixed                           $value
     * @param  string                          $boolean
     * @return $this
     */
    public function having($column, $operator = null, $value = null, string $boolean = 'AND')
    {
        $wheres       = $this->wheres;
        $this->wheres = [];

        // Reuse the where builder, then move what it produced over to the havings
        func_num_args() === 2
            ? $this->where($column, $operator)
            : $this->where($column, $operator, $value, $boolean);

        foreach ( $this->wheres as $having )
        {
            $this->havings[] = $having;
        }

        $this->wheres = $wheres;

        return $this;
    }

    /**
     * @param  string|array|Closure|expression $column
     * @param  mixed                           $operator
     * @param  mixed                           $value
     * @return $this
     */
    public function or_having($column, $operator = null, $value = null)
    {
        if ( func_num_args() === 2 )
        {
            return $this->having($column, '=', $operator, 'OR');
        }

        return $this->having($column, $operator, $value, 'OR');
    }

    /**
     * @param  array<int, mixed> $bindings
     * @param  string            $boolean
     * @return $this
     */
    public function having_raw(string $sql, array $bindings = [], string $boolean = 'AND')
    {
        $this->havings[] = [
            'type'     => 'raw',
            'boolean'  => $boolean,
            'sql'      => $sql,
            'bindings' => array_values($bindings),
        ];

        return $this;
    }

    /**
     * @param  string|array|expression $column    Column name, or a list of [column, direction]
     * @return $this
     */
    public function order_by($column, string $direction = 'asc')
    {
        if ( is_array($column) )
        {
            foreach ( $column as $key => $one )
            {
                is_array($one)
                    ? $this->order_by($one[0], $one[1] ?? 'asc')
                    : $this->order_by(is_int($key) ? $one : $key, is_int($key) ? 'asc' : (string) $one);
            }

            return $this;
        }

        if ( $column instanceof expression )
        {
            return $this->order_by_raw($column->value());
        }

        $direction = strtolower(trim($direction)) === 'desc' ? 'DESC' : 'ASC';

        $this->orders[] = ['column' => $column, 'direction' => $direction];

        return $this;
    }

    /**
     * @return $this
     */
    public function order_by_raw(string $sql)
    {
        $this->orders[] = ['expression' => $sql];

        return $this;
    }

    /**
     * @return $this
     */
    public function limit(?int $number)
    {
        $this->limit = $number === null ? null : max(0, $number);

        return $this;
    }

    /**
     * @return $this
     */
    public function offset(?int $number)
    {
        $this->offset = $number === null ? null : max(0, $number);

        return $this;
    }

    /**
     * @param  int $page One based
     * @return $this
     */
    public function page(int $page, int $size)
    {
        return $this->limit($size)->offset((max(1, $page) - 1) * $size);
    }

    /**
     * Raise, or lift, the ceiling the connection puts on this query's LIMIT.
     *
     * @return $this
     */
    public function max_select_limit(?int $number)
    {
        $this->max_limit = $number;

        return $this;
    }

    /**
     * A non primary key index the optimiser would otherwise skip when too much of the table
     * matches. Row level locks taken through such an index need it, or they deadlock.
     *
     * @param  string ...$names
     * @return $this
     */
    public function force_index(string ...$names)
    {
        $this->index_hint = $names ?: null;

        return $this;
    }

    /**
     * Exclusive row lock: no other transaction reads or writes these rows.
     *
     * @return $this
     */
    public function lock_for_update()
    {
        $this->lock       = 'update';
        $this->use_master = true;

        return $this;
    }

    /**
     * Shared row lock: other transactions may take the same lock, none may write.
     *
     * @return $this
     */
    public function lock_shared()
    {
        $this->lock       = 'share';
        $this->use_master = true;

        return $this;
    }

    /**
     * @return $this
     */
    public function ignore(bool $value = true)
    {
        $this->ignore = $value;

        return $this;
    }

    /**
     * Read from the write connection, for the read your own writes case.
     *
     * @return $this
     */
    public function on_master(bool $value = true)
    {
        $this->use_master = $value;

        return $this;
    }

    /**
     * Set something only one dialect understands. A dialect that does not know the key ignores it.
     *
     * @param  mixed  $value
     * @return $this
     */
    public function option(string $key, $value = true)
    {
        $this->options[$key] = $value;

        return $this;
    }

    /**
     * @param  query|string      $query
     * @param  array<int, mixed> $bindings Only used when $query is a string
     * @return $this
     */
    public function union($query, bool $all = false, array $bindings = [])
    {
        if ( $query instanceof query )
        {
            list($sql, $bindings) = $query->to_sql();
        }
        else
        {
            $sql = $query;
        }

        $this->unions[] = ['sql' => $sql, 'bindings' => array_values($bindings), 'all' => $all];

        return $this;
    }

    /**
     * @param  query|string      $query
     * @param  array<int, mixed> $bindings
     * @return $this
     */
    public function union_all($query, array $bindings = [])
    {
        return $this->union($query, true, $bindings);
    }

    /**
     * Select from the unions instead of from a table.
     *
     * @return $this
     */
    public function as_union_table(string $alias = 'u')
    {
        $this->union_alias = $alias;

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get(): array
    {
        return $this->_connection->select($this);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        $rows = $this->limit(1)->get();

        return $rows ? reset($rows) : null;
    }

    /**
     * One column of one row, or null when there is no row.
     *
     * @param  string|expression $column
     * @return mixed
     */
    public function value($column)
    {
        // Whatever the caller selected before does not matter here, and leaving it in place would
        // make reset() below return the wrong column
        $this->columns = [$column];

        $row = $this->first();

        return $row ? reset($row) : null;
    }

    /**
     * @param  string      $column Column to take the value from
     * @param  string|null $key    Column to key the result by
     * @return array<int|string, mixed>
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $rows = $key === null ? $this->select($column)->get() : $this->select($column, $key)->get();

        $out = [];
        foreach ( $rows as $row )
        {
            $value = $row[$this->_bare($column)] ?? null;
            if ( $key === null )
            {
                $out[] = $value;
                continue;
            }

            $out[$row[$this->_bare($key)] ?? null] = $value;
        }

        return $out;
    }

    public function exists(): bool
    {
        $columns       = $this->columns;
        $this->columns = [new expression('1')];

        $rows = $this->limit(1)->get();

        $this->columns = $columns;

        return $rows !== [];
    }

    /**
     * @return int
     */
    public function count(string $column = '*'): int
    {
        return (int) $this->_aggregate('COUNT', $column);
    }

    /**
     * @return mixed
     */
    public function sum(string $column)
    {
        return $this->_aggregate('SUM', $column);
    }

    /**
     * @return mixed
     */
    public function min(string $column)
    {
        return $this->_aggregate('MIN', $column);
    }

    /**
     * @return mixed
     */
    public function max(string $column)
    {
        return $this->_aggregate('MAX', $column);
    }

    /**
     * @return mixed
     */
    public function avg(string $column)
    {
        return $this->_aggregate('AVG', $column);
    }

    /**
     * Walk the result one row at a time instead of building the whole array.
     */
    public function cursor(): Generator
    {
        return $this->_connection->cursor($this);
    }

    /**
     * Walk the result a page at a time. Add an order_by on something unique, or a row written
     * between two pages will shift the window and a row will be seen twice or not at all.
     *
     * @param  callable $callback Receives each page; returning false stops the walk
     * @return bool               Whether every page was handed over
     */
    public function chunk(int $size, callable $callback): bool
    {
        $size   = max(1, $size);
        $offset = $this->offset ?? 0;
        $page   = 0;

        while ( true )
        {
            $this->limit($size)->offset($offset + $page * $size);

            $rows = $this->get();
            if ( !$rows )
            {
                return true;
            }

            if ( $callback($rows, $page) === false )
            {
                return false;
            }

            if ( count($rows) < $size )
            {
                return true;
            }

            $page++;
        }
    }

    /**
     * Write one row, or a list of them.
     *
     * @param  array<string, mixed>|array<int, array<string, mixed>> $values
     * @return int|string Auto increment value of the first row written, 0 when there is none
     */
    public function insert(array $values)
    {
        return $this->_connection->insert($this, $this->_rows($values));
    }

    /**
     * Write rows, overwriting the listed columns when a unique key already holds one.
     *
     * @param  array<string, mixed>|array<int, array<string, mixed>> $values
     * @param  array<int|string, mixed>                              $update Column names, or
     *                                                                       column => value
     * @return int Rows affected: 1 per insert, 2 per update that changed something
     */
    public function upsert(array $values, array $update): int
    {
        return $this->_connection->upsert($this, $this->_rows($values), $update);
    }

    /**
     * @param  array<string, mixed> $values
     * @return int Rows affected
     */
    public function update(array $values): int
    {
        return $this->_connection->update($this, $values);
    }

    /**
     * Write several rows in one statement, matching them up on $key.
     *
     * @param  array<int, array<string, mixed>> $rows Each row must carry $key
     * @return int Rows affected
     */
    public function update_batch(array $rows, string $key): int
    {
        return $this->_connection->update_batch($this, array_values($rows), $key);
    }

    /**
     * Merge values into a JSON column, leaving the rest of the document alone.
     *
     * @param  array<string,mixed> $pairs Path inside the document => value
     * @return int Rows affected
     */
    public function update_json(string $column, array $pairs): int
    {
        return $this->_connection->update_json($this, $column, $pairs);
    }

    /**
     * @param  int|string|null $id Shorthand for where on the primary key
     * @param  string          $key
     * @return int Rows affected
     */
    public function delete($id = null, string $key = 'id'): int
    {
        if ( $id !== null )
        {
            $this->where($key, '=', $id);
        }

        return $this->_connection->delete($this);
    }

    /**
     * @return array{0: string, 1: array<int, mixed>} Statement and its bindings
     */
    public function to_sql(): array
    {
        return $this->_connection->grammar()->compile_select($this);
    }

    /**
     * The statement with its values put back in. For reading, not for running.
     */
    public function to_raw_sql(): string
    {
        list($sql, $bindings) = $this->to_sql();

        return $this->_connection->grammar()->interpolate($sql, $bindings);
    }

    /**
     * @param  mixed  $value
     * @return $this
     */
    protected function _add_basic(string $column, string $operator, $value, string $boolean)
    {
        $operator = trim($operator);
        $lower    = strtolower($operator);

        if ( $lower === 'in' || $lower === 'not in' )
        {
            return $this->where_in($column, $value, $boolean, $lower === 'not in');
        }

        if ( $lower === 'between' || $lower === 'not between' )
        {
            if ( !is_array($value) || count($value) !== 2 )
            {
                throw new InvalidArgumentException('BETWEEN takes exactly two values');
            }

            $bounds = array_values($value);

            return $this->where_between($column, $bounds[0], $bounds[1], $boolean, $lower === 'not between');
        }

        if ( $lower === 'find_in_set' )
        {
            return $this->where_find_in_set($column, $value, $boolean);
        }

        // A null on either side of = is asking for IS NULL, not for a comparison that never matches
        if ( $value === null && ($operator === '=' || $lower === 'is') )
        {
            return $this->where_null($column, $boolean);
        }

        if ( $value === null && ($operator === '!=' || $operator === '<>' || $lower === 'is not') )
        {
            return $this->where_null($column, $boolean, true);
        }

        // An array against = means the caller wants any of them
        if ( is_array($value) && ($operator === '=' || $operator === '!=' || $operator === '<>') )
        {
            return $this->where_in($column, $value, $boolean, $operator !== '=');
        }

        $this->wheres[] = [
            'type'     => 'basic',
            'boolean'  => $boolean,
            'column'   => $column,
            'operator' => $operator,
            'value'    => $value,
        ];

        return $this;
    }

    /**
     * @return $this
     */
    protected function _add_nested(Closure $callback, string $boolean)
    {
        $nested = new self($this->_connection, is_string($this->table) ? $this->table : '');
        $callback($nested);

        if ( $nested->wheres )
        {
            $this->wheres[] = [
                'type'    => 'nested',
                'boolean' => $boolean,
                'wheres'  => $nested->wheres,
            ];
        }

        return $this;
    }

    /**
     * Run an aggregate without disturbing the columns the caller asked for.
     *
     * @return mixed
     */
    protected function _aggregate(string $function, string $column)
    {
        $columns   = $this->columns;
        $aggregate = $this->aggregate;
        $orders    = $this->orders;

        $this->columns   = [];
        $this->aggregate = ['function' => $function, 'column' => $column];
        // ORDER BY over an aggregate is work the server does for nothing
        $this->orders = [];

        try
        {
            $rows = $this->get();
        }
        finally
        {
            $this->columns   = $columns;
            $this->aggregate = $aggregate;
            $this->orders    = $orders;
        }

        $row = $rows ? reset($rows) : null;

        return is_array($row) ? ($row['aggregate'] ?? null) : null;
    }

    /**
     * The key a column arrives back under: the alias if it has one, else the last segment.
     */
    protected function _bare(string $column): string
    {
        if ( stripos($column, ' as ') !== false )
        {
            $parts = preg_split('/\s+as\s+/i', trim($column), 2);
            return trim((string) $parts[1]);
        }

        $parts = explode('.', trim($column));

        return (string) end($parts);
    }

    /**
     * Accept one row or a list of them, and hand back a list either way.
     *
     * @param  array<mixed> $values
     * @return array<int, array<string, mixed>>
     */
    protected function _rows(array $values): array
    {
        if ( !$values )
        {
            return [];
        }

        // A list of rows has a leading 0 key holding an array; anything else is one row
        return isset($values[0]) && is_array($values[0]) ? array_values($values) : [$values];
    }
}
