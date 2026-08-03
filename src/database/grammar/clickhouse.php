<?php

/**
 * ClickHouse dialect
 *
 * Close enough to MySQL that the base grammar does most of the work: backtick quoted identifiers,
 * the same joins, the same LIMIT / OFFSET. What differs is what an analytical column store does
 * not have, and it is better to refuse than to emit something that means the wrong thing:
 *
 *   - UPDATE and DELETE are mutations, spelled ALTER TABLE ... UPDATE / DELETE WHERE. They run in
 *     the background and are not atomic, so a caller must not read them as a row store's writes.
 *     A mutation with no WHERE would rewrite every part on disk, so one is required.
 *   - There is no ON DUPLICATE KEY UPDATE, no INSERT IGNORE and no row locking.
 *   - Values cannot be bound: the HTTP interface has no prepared statements, so the driver asks
 *     interpolate() to fold them in and escape_literal() below is what stands between a value and
 *     the statement.
 *
 * Recognised query options (query::option()):
 *
 *     final     bool          append FINAL, merging parts before reading
 *     sample    string|float  SAMPLE clause
 *     prewhere  expression    filter applied before the other columns are read
 *     settings  array         per query settings, appended as SETTINGS k = v
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\database\grammar;

use plato\database\blueprint;
use plato\database\column;
use plato\database\expression;
use plato\database\grammar;
use plato\database\query;
use InvalidArgumentException;
use RuntimeException;

class clickhouse extends grammar
{
    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function compile_select(query $q): array
    {
        if ( $q->lock !== null )
        {
            throw new RuntimeException('ClickHouse has no row locks');
        }

        $bindings = [];

        $sql = 'SELECT ';
        if ( $q->aggregate !== null )
        {
            $sql .= $this->_compile_aggregate($q);
        }
        else
        {
            $sql .= $q->distinct ? 'DISTINCT ' : '';
            $sql .= $q->columns ? implode(', ', array_map([$this, 'wrap_aliased'], $q->columns)) : '*';
        }

        $sql .= ' FROM ' . $this->_compile_source($q, $bindings);

        if ( !empty($q->options['final']) )
        {
            $sql .= ' FINAL';
        }

        if ( isset($q->options['sample']) )
        {
            $sql .= ' SAMPLE ' . $this->_number((string) $q->options['sample']);
        }

        $sql .= $this->_compile_joins($q, $bindings);

        if ( isset($q->options['prewhere']) )
        {
            // Read the filtered columns first and the rest only for the rows that survive.
            // Through parameter() rather than a cast, or an expression's own bindings would be
            // dropped while its placeholders stayed in the statement, shifting every value after it
            $prewhere = $q->options['prewhere'];
            $sql     .= ' PREWHERE ' . ($prewhere instanceof expression
                ? $this->parameter($prewhere, $bindings)
                : $this->substitute_prefix((string) $prewhere));
        }

        $sql .= $this->_compile_wheres($q->wheres, $bindings, 'WHERE');
        $sql .= $this->_compile_groups($q);
        $sql .= $this->_compile_wheres($q->havings, $bindings, 'HAVING');
        $sql .= $this->_compile_unions($q, $bindings);
        $sql .= $this->_compile_orders($q);
        $sql .= $this->_compile_limit($q);
        $sql .= $this->_compile_settings($q);

        return [$sql, $bindings];
    }

    /**
     * @param  array<int, array<string, mixed>> $rows
     * @param  array<int|string, mixed>         $update
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function compile_insert(query $q, array $rows, array $update = []): array
    {
        if ( $update )
        {
            throw new RuntimeException(
                'ClickHouse has no ON DUPLICATE KEY UPDATE. Use a ReplacingMergeTree and read with '
                . "->option('final', true), or write a new row"
            );
        }

        if ( $q->ignore )
        {
            throw new RuntimeException('ClickHouse has no INSERT IGNORE');
        }

        return parent::compile_insert($q, $rows);
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

        $this->_require_where($q, 'UPDATE');

        $bindings = [];
        $set      = [];
        foreach ( $values as $column => $value )
        {
            $set[] = $this->wrap($column) . ' = ' . $this->parameter($value, $bindings);
        }

        $sql  = 'ALTER TABLE ' . $this->wrap_table($q->table) . ' UPDATE ' . implode(', ', $set);
        $sql .= $this->_compile_wheres($q->wheres, $bindings, 'WHERE');

        return [$sql, $bindings];
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function compile_delete(query $q): array
    {
        $this->_require_where($q, 'DELETE');

        $bindings = [];
        $sql      = 'ALTER TABLE ' . $this->wrap_table($q->table) . ' DELETE';
        $sql     .= $this->_compile_wheres($q->wheres, $bindings, 'WHERE');

        return [$sql, $bindings];
    }

    /**
     * @param  array<int, array<string, mixed>> $rows
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function compile_update_batch(query $q, array $rows, string $key): array
    {
        throw new RuntimeException(
            'ClickHouse mutations cannot be batched by key. Insert the new rows and let a '
            . 'ReplacingMergeTree collapse them'
        );
    }

    /**
     * @param  array<string,mixed> $pairs
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function compile_update_json(query $q, string $column, array $pairs): array
    {
        throw new RuntimeException('ClickHouse has no JSON_SET; write the whole column');
    }

    /**
     * Escape a value for a statement that has nowhere to bind it.
     *
     * ClickHouse takes backslash escapes inside single quotes, but not MySQL's \Z, so control
     * bytes are spelled out rather than passed through.
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
            ['\\', "\0", "\n", "\r", "\t", "'"],
            ['\\\\', '\\0', '\\n', '\\r', '\\t', "\\'"],
            (string) $value
        ) . "'";
    }

    /**
     * Portable type => ClickHouse type.
     *
     * Nullability is part of the type here (`Nullable(String)`), not a NULL / NOT NULL suffix, so
     * _compile_column() below is a rewrite rather than a tweak of the base one.
     *
     * @var array<string, string>
     */
    protected array $_types = [
        'string'        => 'String',
        'char'          => 'FixedString',
        'text'          => 'String',
        'medium_text'   => 'String',
        'long_text'     => 'String',
        'tiny_integer'  => 'Int8',
        'small_integer' => 'Int16',
        'integer'       => 'Int32',
        'big_integer'   => 'Int64',
        'decimal'       => 'Decimal',
        'float'         => 'Float32',
        'double'        => 'Float64',
        'boolean'       => 'UInt8',
        'date'          => 'Date',
        'datetime'      => 'DateTime',
        'timestamp'     => 'DateTime',
        'time'          => 'String',
        'json'          => 'String',
        'binary'        => 'String',
        'enum'          => 'Enum16',
    ];

    /**
     * Unsigned integers are their own types, not a modifier.
     *
     * @var array<string, string>
     */
    protected array $_unsigned_types = [
        'tiny_integer'  => 'UInt8',
        'small_integer' => 'UInt16',
        'integer'       => 'UInt32',
        'big_integer'   => 'UInt64',
    ];

    /**
     * information_schema exists here, but there is no DATABASE() -- currentDatabase() is the
     * ClickHouse spelling.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function compile_table_exists(string $table): array
    {
        return [
            'SELECT 1 FROM system.tables WHERE database = currentDatabase() AND name = ? LIMIT 1',
            [$table],
        ];
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function compile_column_exists(string $table, string $column): array
    {
        return [
            'SELECT 1 FROM system.columns WHERE database = currentDatabase()'
                . ' AND table = ? AND name = ? LIMIT 1',
            [$table, $column],
        ];
    }

    /**
     * CREATE TABLE, with the engine clause a MergeTree family table cannot do without.
     *
     * `ORDER BY` is the sorting key and, in the absence of an explicit primary key, the primary
     * key too. There is no sensible default for it -- picking one silently would decide the
     * physical layout of somebody's table for them -- so a blueprint has to say either
     * `option('order_by', [...])` or `primary(...)`.
     */
    protected function _compile_create(blueprint $table): string
    {
        $lines = [];

        foreach ( $table->columns() as $column )
        {
            $lines[] = $this->_compile_column($column);
        }

        $sql = 'CREATE TABLE ' . $this->wrap_table($table->table()) . " (\n  "
            . implode(",\n  ", $lines) . "\n)";

        $sql .= ' ENGINE = ' . (string) $table->options('engine', 'MergeTree');

        $order = (array) $table->options('order_by', $this->_primary_columns($table));

        if ( $order === [] )
        {
            throw new RuntimeException(
                'a ClickHouse table needs a sorting key: give the blueprint option(\'order_by\', [...])'
                . ' or a primary key'
            );
        }

        $partition = $table->options('partition_by');
        if ( $partition !== null )
        {
            $sql .= ' PARTITION BY ' . (string) $partition;
        }

        $sql .= ' ORDER BY (' . $this->_columnize($order) . ')';

        $ttl = $table->options('ttl');
        if ( $ttl !== null )
        {
            $sql .= ' TTL ' . (string) $ttl;
        }

        $comment = $table->options('comment');
        if ( $comment !== null )
        {
            $sql .= ' COMMENT ' . $this->escape_literal((string) $comment);
        }

        return $sql;
    }

    /**
     * ALTER TABLE. Adding and dropping columns is supported; the rest is not.
     *
     * @return array<int, string>
     */
    protected function _compile_alter(blueprint $table): array
    {
        $target     = $this->wrap_table($table->table());
        $statements = [];

        foreach ( $table->columns() as $column )
        {
            $statements[] = 'ALTER TABLE ' . $target
                . ($column->get('change') ? ' MODIFY COLUMN ' : ' ADD COLUMN ')
                . $this->_compile_column($column);
        }

        foreach ( $table->commands() as $command )
        {
            switch ( $command['name'] )
            {
                case 'drop_column':
                    foreach ( (array) $command['columns'] as $name )
                    {
                        $statements[] = 'ALTER TABLE ' . $target . ' DROP COLUMN ' . $this->wrap((string) $name);
                    }
                    break;

                case 'rename_column':
                    $statements[] = 'ALTER TABLE ' . $target . ' RENAME COLUMN '
                        . $this->wrap((string) $command['from']) . ' TO ' . $this->wrap((string) $command['to']);
                    break;

                case 'rename':
                    $statements[] = 'RENAME TABLE ' . $target . ' TO '
                        . $this->wrap_table((string) $command['to']);
                    break;

                case 'index':
                    // A data skipping index, which is not the same thing as a secondary index and
                    // needs a type and a granularity the blueprint has no way to carry
                    throw new RuntimeException(
                        'ClickHouse has no secondary indexes; the sorting key is the index.'
                        . ' Use a raw statement for a data skipping index'
                    );

                default:
                    throw new RuntimeException(
                        'ClickHouse cannot ' . str_replace('_', ' ', (string) $command['name'])
                    );
            }
        }

        return $statements;
    }

    /**
     * One column. Nullability wraps the type instead of following it, and there is no
     * AUTO_INCREMENT anywhere in ClickHouse.
     */
    protected function _compile_column(column $column): string
    {
        if ( $column->get('auto_increment') )
        {
            throw new RuntimeException(
                'ClickHouse has no AUTO_INCREMENT; generate the id yourself or use a UUID column'
            );
        }

        $type = $this->_compile_type($column);

        if ( $column->get('nullable') )
        {
            $type = 'Nullable(' . $type . ')';
        }

        $sql = $this->wrap($column->name()) . ' ' . $type;

        if ( $column->has('default') )
        {
            $default = $column->get('default');
            $sql    .= ' DEFAULT ' . ($default instanceof expression
                ? $default->value()
                : $this->escape_literal($default));
        }
        elseif ( $column->get('use_current') )
        {
            $sql .= ' DEFAULT now()';
        }

        $comment = $column->get('comment');
        if ( $comment !== null )
        {
            $sql .= ' COMMENT ' . $this->escape_literal((string) $comment);
        }

        return $sql;
    }

    /**
     * The engine type of a column, taking the unsigned integer types into account.
     */
    protected function _compile_type(column $column): string
    {
        if ( $column->get('unsigned') && isset($this->_unsigned_types[$column->type()]) )
        {
            return $this->_unsigned_types[$column->type()];
        }

        if ( $column->type() === 'char' )
        {
            return 'FixedString(' . (int) $column->get('length', 32) . ')';
        }

        if ( $column->type() === 'decimal' )
        {
            return 'Decimal(' . (int) $column->get('precision', 10)
                . ', ' . (int) $column->get('scale', 2) . ')';
        }

        if ( $column->type() === 'enum' )
        {
            $pairs = [];
            foreach ( array_values((array) $column->get('values', [])) as $i => $value )
            {
                $pairs[] = $this->escape_literal((string) $value) . ' = ' . ($i + 1);
            }

            return 'Enum16(' . implode(', ', $pairs) . ')';
        }

        // String and the rest carry no length in ClickHouse, so the map answers on its own --
        // parent would append the VARCHAR length to it
        $type = $this->_types[$column->type()] ?? null;

        if ( $type === null )
        {
            throw new InvalidArgumentException(static::class . ' has no type for ' . $column->type());
        }

        return $type;
    }

    /**
     * Columns the blueprint marked as the primary key, in declaration order.
     *
     * @return array<int, string>
     */
    private function _primary_columns(blueprint $table): array
    {
        $columns = [];

        foreach ( $table->columns() as $column )
        {
            if ( $column->get('primary') )
            {
                $columns[] = $column->name();
            }
        }

        foreach ( $table->commands() as $command )
        {
            if ( $command['name'] === 'primary' )
            {
                $columns = array_merge($columns, (array) $command['columns']);
            }
        }

        return $columns;
    }

    /**
     * FORCE INDEX has no ClickHouse equivalent; the primary key is the index and it is always used.
     *
     * @param  array<int, mixed> $bindings
     */
    protected function _compile_source(query $q, array &$bindings): string
    {
        if ( $q->index_hint !== null )
        {
            throw new RuntimeException('ClickHouse has no index hints');
        }

        return parent::_compile_source($q, $bindings);
    }

    protected function _compile_settings(query $q): string
    {
        if ( empty($q->options['settings']) || !is_array($q->options['settings']) )
        {
            return '';
        }

        $parts = [];
        foreach ( $q->options['settings'] as $key => $value )
        {
            if ( !preg_match('/^[a-z_][a-z0-9_]*$/i', (string) $key) )
            {
                throw new InvalidArgumentException("'{$key}' is not a setting name");
            }

            $parts[] = $key . ' = ' . (is_int($value) || is_float($value)
                ? (string) $value
                : $this->escape_literal($value));
        }

        return ' SETTINGS ' . implode(', ', $parts);
    }

    protected function _require_where(query $q, string $what): void
    {
        if ( !$q->wheres )
        {
            throw new RuntimeException(
                "A ClickHouse {$what} mutation with no WHERE would rewrite the whole table. "
                . 'Pass where_raw(\'1 = 1\') if that is really the intent'
            );
        }
    }

    protected function _number(string $value): string
    {
        if ( !preg_match('/^[0-9]+(\.[0-9]+)?$/', $value) )
        {
            throw new InvalidArgumentException("'{$value}' is not a number");
        }

        return $value;
    }
}
