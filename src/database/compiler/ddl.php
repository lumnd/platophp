<?php

/**
 * Schema statements: CREATE TABLE, ALTER TABLE, and the introspection behind schema::has_table()
 *
 * The DDL half of a grammar, kept apart from the DML half because the two share nothing but the
 * identifier quoter -- a blueprint never becomes bindings, and a query never becomes a column
 * definition.
 *
 * compile_schema() answers with a list rather than one string: an ALTER that adds a column and an
 * index is two statements on some engines and one on others, and the caller runs whatever it is
 * given in order.
 *
 * A dialect overrides $_types first and a compiler only when the shape differs -- ClickHouse spells
 * nullability as part of the type (`Nullable(String)`) rather than as a suffix, so it replaces
 * _compile_column() outright.
 *
 * Expects the using class to provide the identifiers and values traits.
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\database\compiler;

use plato\database\blueprint;
use plato\database\column;
use plato\database\expression;
use InvalidArgumentException;

trait ddl
{
    /**
     * Portable type name => MySQL type. A dialect overrides the map rather than the compiler.
     *
     * @var array<string, string>
     */
    protected array $_types = [
        'string'        => 'VARCHAR',
        'char'          => 'CHAR',
        'text'          => 'TEXT',
        'medium_text'   => 'MEDIUMTEXT',
        'long_text'     => 'LONGTEXT',
        'tiny_integer'  => 'TINYINT',
        'small_integer' => 'SMALLINT',
        'integer'       => 'INT',
        'big_integer'   => 'BIGINT',
        'decimal'       => 'DECIMAL',
        'float'         => 'FLOAT',
        'double'        => 'DOUBLE',
        'boolean'       => 'TINYINT(1)',
        'date'          => 'DATE',
        'datetime'      => 'DATETIME',
        'timestamp'     => 'TIMESTAMP',
        'time'          => 'TIME',
        'json'          => 'JSON',
        'binary'        => 'BLOB',
        'enum'          => 'ENUM',
    ];

    /**
     * Turn a blueprint into the statements that realise it.
     *
     * @return array<int, string>
     */
    public function compile_schema(blueprint $table): array
    {
        switch ( $table->action() )
        {
            case 'create':
                return [$this->_compile_create($table)];

            case 'drop':
                return ['DROP TABLE ' . $this->wrap_table($table->table())];

            case 'drop_if_exists':
                return ['DROP TABLE IF EXISTS ' . $this->wrap_table($table->table())];

            default:
                return $this->_compile_alter($table);
        }
    }

    /**
     * Whether a table exists, as a statement plus its bindings.
     *
     * A query and not `SHOW TABLES LIKE`: the pattern form treats `_` as a wildcard, so
     * `user_role` reports a `userxrole` as present.
     *
     * @param  string $table Already prefixed
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function compile_table_exists(string $table): array
    {
        return [
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE()'
                . ' AND table_name = ? LIMIT 1',
            [$table],
        ];
    }

    /**
     * Whether a column exists.
     *
     * @param  string $table Already prefixed
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function compile_column_exists(string $table, string $column): array
    {
        return [
            'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()'
                . ' AND table_name = ? AND column_name = ? LIMIT 1',
            [$table, $column],
        ];
    }

    /**
     * CREATE TABLE.
     */
    protected function _compile_create(blueprint $table): string
    {
        $lines = [];

        foreach ( $table->columns() as $column )
        {
            $lines[] = $this->_compile_column($column);
        }

        foreach ( $this->_inline_keys($table) as $line )
        {
            $lines[] = $line;
        }

        $sql = 'CREATE TABLE ' . $this->wrap_table($table->table()) . " (\n  "
            . implode(",\n  ", $lines) . "\n)";

        return $sql . $this->_compile_table_options($table);
    }

    /**
     * The primary key and the index lines that go inside CREATE TABLE.
     *
     * @return array<int, string>
     */
    protected function _inline_keys(blueprint $table): array
    {
        $lines   = [];
        $primary = [];

        foreach ( $table->columns() as $column )
        {
            if ( $column->get('primary') )
            {
                $primary[] = $column->name();
            }

            if ( $column->get('unique') )
            {
                $lines[] = 'UNIQUE KEY ' . $this->wrap('uniq_' . $column->name())
                    . ' (' . $this->wrap($column->name()) . ')';
            }

            if ( $column->get('index') )
            {
                $lines[] = 'KEY ' . $this->wrap('idx_' . $column->name())
                    . ' (' . $this->wrap($column->name()) . ')';
            }
        }

        foreach ( $table->commands() as $command )
        {
            switch ( $command['name'] )
            {
                case 'primary':
                    $primary = array_merge($primary, (array) $command['columns']);
                    break;

                case 'unique':
                    $lines[] = 'UNIQUE KEY ' . $this->wrap((string) $command['index'])
                        . ' (' . $this->_columnize((array) $command['columns']) . ')';
                    break;

                case 'index':
                    $lines[] = 'KEY ' . $this->wrap((string) $command['index'])
                        . ' (' . $this->_columnize((array) $command['columns']) . ')';
                    break;

                case 'fulltext':
                    $lines[] = 'FULLTEXT KEY ' . $this->wrap((string) $command['index'])
                        . ' (' . $this->_columnize((array) $command['columns']) . ')';
                    break;
            }
        }

        if ( $primary !== [] )
        {
            array_unshift($lines, 'PRIMARY KEY (' . $this->_columnize($primary) . ')');
        }

        return $lines;
    }

    /**
     * ENGINE / CHARSET / COMMENT, appended to CREATE TABLE.
     */
    protected function _compile_table_options(blueprint $table): string
    {
        $sql = ' ENGINE=' . (string) $table->options('engine', 'InnoDB');

        $charset = (string) $table->options('charset', 'utf8mb4');
        $sql    .= ' DEFAULT CHARSET=' . $charset;

        $collation = $table->options('collation');
        if ( $collation !== null )
        {
            $sql .= ' COLLATE=' . (string) $collation;
        }

        $comment = $table->options('comment');
        if ( $comment !== null )
        {
            $sql .= ' COMMENT=' . $this->escape_literal((string) $comment);
        }

        return $sql;
    }

    /**
     * ALTER TABLE, as one statement per group of changes.
     *
     * @return array<int, string>
     */
    protected function _compile_alter(blueprint $table): array
    {
        $target     = $this->wrap_table($table->table());
        $statements = [];
        $changes    = [];

        foreach ( $table->columns() as $column )
        {
            $changes[] = ($column->get('change') ? 'MODIFY COLUMN ' : 'ADD COLUMN ')
                . $this->_compile_column($column);
        }

        foreach ( $table->commands() as $command )
        {
            switch ( $command['name'] )
            {
                case 'primary':
                    $changes[] = 'ADD PRIMARY KEY (' . $this->_columnize((array) $command['columns']) . ')';
                    break;

                case 'unique':
                    $changes[] = 'ADD UNIQUE KEY ' . $this->wrap((string) $command['index'])
                        . ' (' . $this->_columnize((array) $command['columns']) . ')';
                    break;

                case 'index':
                    $changes[] = 'ADD KEY ' . $this->wrap((string) $command['index'])
                        . ' (' . $this->_columnize((array) $command['columns']) . ')';
                    break;

                case 'fulltext':
                    $changes[] = 'ADD FULLTEXT KEY ' . $this->wrap((string) $command['index'])
                        . ' (' . $this->_columnize((array) $command['columns']) . ')';
                    break;

                case 'drop_column':
                    foreach ( (array) $command['columns'] as $name )
                    {
                        $changes[] = 'DROP COLUMN ' . $this->wrap((string) $name);
                    }
                    break;

                case 'rename_column':
                    // 8.0 syntax. MySQL 5.7 needs CHANGE with the whole definition repeated, which
                    // this builder cannot produce without reading the schema back
                    $changes[] = 'RENAME COLUMN ' . $this->wrap((string) $command['from'])
                        . ' TO ' . $this->wrap((string) $command['to']);
                    break;

                case 'drop_index':
                    $changes[] = 'DROP INDEX ' . $this->wrap((string) $command['index']);
                    break;

                case 'drop_primary':
                    $changes[] = 'DROP PRIMARY KEY';
                    break;

                case 'rename':
                    // Its own statement: RENAME TO does not combine with other changes
                    $statements[] = 'ALTER TABLE ' . $target . ' RENAME TO '
                        . $this->wrap_table((string) $command['to']);
                    break;
            }
        }

        if ( $changes !== [] )
        {
            array_unshift($statements, 'ALTER TABLE ' . $target . ' ' . implode(', ', $changes));
        }

        return $statements;
    }

    /**
     * One column definition.
     */
    protected function _compile_column(column $column): string
    {
        $sql = $this->wrap($column->name()) . ' ' . $this->_compile_type($column);

        if ( $column->get('unsigned') )
        {
            $sql .= ' UNSIGNED';
        }

        $charset = $column->get('charset');
        if ( $charset !== null )
        {
            $sql .= ' CHARACTER SET ' . (string) $charset;
        }

        $collation = $column->get('collation');
        if ( $collation !== null )
        {
            $sql .= ' COLLATE ' . (string) $collation;
        }

        $sql .= $column->get('nullable') ? ' NULL' : ' NOT NULL';

        if ( $column->get('use_current') )
        {
            $sql .= ' DEFAULT CURRENT_TIMESTAMP';
        }
        elseif ( $column->has('default') )
        {
            $default = $column->get('default');
            $sql    .= ' DEFAULT ' . ($default instanceof expression
                ? $default->value()
                : $this->escape_literal($default));
        }

        if ( $column->get('on_update_current') )
        {
            $sql .= ' ON UPDATE CURRENT_TIMESTAMP';
        }

        if ( $column->get('auto_increment') )
        {
            $sql .= ' AUTO_INCREMENT';
        }

        $comment = $column->get('comment');
        if ( $comment !== null )
        {
            $sql .= ' COMMENT ' . $this->escape_literal((string) $comment);
        }

        if ( $column->get('first') )
        {
            $sql .= ' FIRST';
        }
        elseif ( $column->get('after') !== null )
        {
            $sql .= ' AFTER ' . $this->wrap((string) $column->get('after'));
        }

        return $sql;
    }

    /**
     * The engine's type for a portable one, with its length or precision.
     */
    protected function _compile_type(column $column): string
    {
        $type = $this->_types[$column->type()] ?? null;

        if ( $type === null )
        {
            throw new InvalidArgumentException(
                static::class . ' has no type for ' . $column->type()
            );
        }

        switch ( $column->type() )
        {
            case 'string':
            case 'char':
                return $type . '(' . (int) $column->get('length', 191) . ')';

            case 'decimal':
                return $type . '(' . (int) $column->get('precision', 10)
                    . ',' . (int) $column->get('scale', 2) . ')';

            case 'enum':
                $values = array_map([$this, 'escape_literal'], (array) $column->get('values', []));

                return $type . '(' . implode(', ', $values) . ')';

            default:
                return $type;
        }
    }

    /**
     * A comma separated, quoted column list.
     *
     * @param  array<int, string> $columns
     */
    protected function _columnize(array $columns): string
    {
        return implode(', ', array_map([$this, 'wrap'], $columns));
    }
}
