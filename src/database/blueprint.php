<?php

/**
 * The shape of a table, collected by a callback and compiled by the grammar
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\database;

use InvalidArgumentException;

/**
 * What a migration describes, before any dialect has seen it.
 *
 *     schema::create('user', function (blueprint $table)
 *     {
 *         $table->id();
 *         $table->string('email', 190)->unique();
 *         $table->string('name')->default('');
 *         $table->tiny_integer('status')->unsigned()->default(1);
 *         $table->json('profile')->nullable();
 *         $table->timestamps();
 *
 *         $table->index(['status', 'created_at'], 'idx_status_created');
 *         $table->comment('Accounts');
 *     });
 *
 * A blueprint holds **no SQL**. It is a list of columns and a list of commands, and the connection's
 * grammar turns those into statements -- which is the whole point: the same migration has to compile
 * for MySQL and for ClickHouse, and those two do not even agree on whether `ALTER TABLE ... MODIFY`
 * exists. A dialect that cannot express something says so by throwing when it is asked to compile
 * it, rather than by this class refusing to record it.
 *
 * Column methods return a `column`, so modifiers chain off the definition:
 * `->nullable()`, `->default()`, `->unsigned()`, `->comment()`, `->after()`, `->use_current()`,
 * `->change()`, `->primary()`, `->unique()`, `->index()`.
 *
 * The type names are the portable ones. `string` is a VARCHAR of a length you give (191 by default,
 * which is what fits a MySQL utf8mb4 index on an old row format), `text` / `medium_text` / `long_text`
 * are the three sizes, `integer` and its siblings carry no display width because MySQL 8 ignores one,
 * and `decimal($p, $s)` is the one to use for money.
 */
class blueprint
{
    /**
     * Table this describes, before the prefix is expanded
     *
     * @var string
     */
    private $_table;

    /**
     * create, alter or drop
     *
     * @var string
     */
    private $_action;

    /**
     * Columns, in the order they were declared
     *
     * @var array<int, column>
     */
    private $_columns = [];

    /**
     * Everything that is not a column: indexes, drops, renames
     *
     * @var array<int, array<string, mixed>>
     */
    private $_commands = [];

    /**
     * Table level options: engine, charset, collation, comment, and whatever a dialect adds
     *
     * @var array<string, mixed>
     */
    private $_options = [];

    /**
     * @param string $action create|alter|drop|drop_if_exists
     */
    public function __construct(string $table, string $action = 'create')
    {
        $this->_table  = $table;
        $this->_action = $action;
    }

    public function table(): string
    {
        return $this->_table;
    }

    public function action(): string
    {
        return $this->_action;
    }

    /**
     * @return array<int, column>
     */
    public function columns(): array
    {
        return $this->_columns;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function commands(): array
    {
        return $this->_commands;
    }

    /**
     * @param  mixed       $default
     * @return mixed
     */
    public function options(?string $key = null, $default = null)
    {
        if ( $key === null )
        {
            return $this->_options;
        }

        return $this->_options[$key] ?? $default;
    }

    /**
     * Storage engine, MySQL only. Ignored by a dialect that has no such concept.
     *
     * @return $this
     */
    public function engine(string $engine)
    {
        $this->_options['engine'] = $engine;

        return $this;
    }

    /**
     * @return $this
     */
    public function charset(string $charset, ?string $collation = null)
    {
        $this->_options['charset'] = $charset;

        if ( $collation !== null )
        {
            $this->_options['collation'] = $collation;
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function comment(string $comment)
    {
        $this->_options['comment'] = $comment;

        return $this;
    }

    /**
     * A dialect specific option: ClickHouse's engine parameters, order by, partition key.
     *
     * @param  mixed  $value
     * @return $this
     */
    public function option(string $key, $value)
    {
        $this->_options[$key] = $value;

        return $this;
    }

    /**
     * An auto incrementing primary key, which is what almost every table starts with.
     *
     * @return column
     */
    public function id(string $name = 'id')
    {
        return $this->big_integer($name)->unsigned()->auto_increment()->primary();
    }

    /**
     * @return column
     */
    public function string(string $name, int $length = 191)
    {
        return $this->_column($name, 'string', ['length' => $length]);
    }

    /**
     * A fixed width string. Padded to $length by the server, which is only worth it for values that
     * really are all the same width -- a hash, a country code.
     *
     * @return column
     */
    public function char(string $name, int $length = 32)
    {
        return $this->_column($name, 'char', ['length' => $length]);
    }

    /**
     * @return column
     */
    public function text(string $name)
    {
        return $this->_column($name, 'text');
    }

    /**
     * @return column
     */
    public function medium_text(string $name)
    {
        return $this->_column($name, 'medium_text');
    }

    /**
     * @return column
     */
    public function long_text(string $name)
    {
        return $this->_column($name, 'long_text');
    }

    /**
     * @return column
     */
    public function tiny_integer(string $name)
    {
        return $this->_column($name, 'tiny_integer');
    }

    /**
     * @return column
     */
    public function small_integer(string $name)
    {
        return $this->_column($name, 'small_integer');
    }

    /**
     * @return column
     */
    public function integer(string $name)
    {
        return $this->_column($name, 'integer');
    }

    /**
     * @return column
     */
    public function big_integer(string $name)
    {
        return $this->_column($name, 'big_integer');
    }

    /**
     * Fixed point. The type for money: a float cannot hold 0.10 and adding a thousand of them
     * does not give 100.
     *
     * @param  int    $precision Total digits
     * @param  int    $scale     Digits after the point
     * @return column
     */
    public function decimal(string $name, int $precision = 10, int $scale = 2)
    {
        return $this->_column($name, 'decimal', ['precision' => $precision, 'scale' => $scale]);
    }

    /**
     * @return column
     */
    public function float(string $name)
    {
        return $this->_column($name, 'float');
    }

    /**
     * @return column
     */
    public function double(string $name)
    {
        return $this->_column($name, 'double');
    }

    /**
     * A one byte flag. MySQL has no boolean type; this is TINYINT(1), which is what everything
     * else calls a boolean too.
     *
     * @return column
     */
    public function boolean(string $name)
    {
        return $this->_column($name, 'boolean');
    }

    /**
     * @return column
     */
    public function date(string $name)
    {
        return $this->_column($name, 'date');
    }

    /**
     * @return column
     */
    public function datetime(string $name)
    {
        return $this->_column($name, 'datetime');
    }

    /**
     * @return column
     */
    public function timestamp(string $name)
    {
        return $this->_column($name, 'timestamp');
    }

    /**
     * @return column
     */
    public function time(string $name)
    {
        return $this->_column($name, 'time');
    }

    /**
     * JSON, where the engine has it. On an engine that does not, the dialect falls back to text --
     * the value round trips either way, only the server side functions differ.
     *
     * @return column
     */
    public function json(string $name)
    {
        return $this->_column($name, 'json');
    }

    /**
     * Raw bytes. Use for a key or a digest, never for a string.
     *
     * @return column
     */
    public function binary(string $name)
    {
        return $this->_column($name, 'binary');
    }

    /**
     * One of a fixed set of strings.
     *
     * @param  array<int, string> $values
     * @return column
     */
    public function enum(string $name, array $values)
    {
        if ( $values === [] )
        {
            throw new InvalidArgumentException('an enum column needs at least one value');
        }

        return $this->_column($name, 'enum', ['values' => array_values($values)]);
    }

    /**
     * `created_at` and `updated_at`, the pair almost every table wants.
     *
     * Both are nullable and default to null rather than to the current time: a row written by a
     * migration or by a bulk load has no meaningful created_at, and a column that quietly fills
     * itself in hides that.
     */
    public function timestamps(): void
    {
        $this->timestamp('created_at')->nullable();
        $this->timestamp('updated_at')->nullable();
    }

    /**
     * A `deleted_at` for soft deletes.
     *
     * @return column
     */
    public function soft_deletes(string $name = 'deleted_at')
    {
        return $this->timestamp($name)->nullable();
    }

    /**
     * @param  string|array<int, string> $columns
     * @param  string|null               $name Generated from the columns when omitted
     * @return $this
     */
    public function primary($columns, ?string $name = null)
    {
        return $this->_command('primary', ['columns' => (array) $columns, 'index' => $name]);
    }

    /**
     * @param  string|array<int, string> $columns
     * @return $this
     */
    public function unique($columns, ?string $name = null)
    {
        return $this->_command('unique', [
            'columns' => (array) $columns,
            'index'   => $name ?? $this->_index_name('unique', (array) $columns),
        ]);
    }

    /**
     * @param  string|array<int, string> $columns
     * @return $this
     */
    public function index($columns, ?string $name = null)
    {
        return $this->_command('index', [
            'columns' => (array) $columns,
            'index'   => $name ?? $this->_index_name('index', (array) $columns),
        ]);
    }

    /**
     * A full text index. MySQL only; another dialect throws when asked to compile it.
     *
     * @param  string|array<int, string> $columns
     * @return $this
     */
    public function fulltext($columns, ?string $name = null)
    {
        return $this->_command('fulltext', [
            'columns' => (array) $columns,
            'index'   => $name ?? $this->_index_name('fulltext', (array) $columns),
        ]);
    }

    /**
     * @param  string|array<int, string> $columns
     * @return $this
     */
    public function drop_column($columns)
    {
        return $this->_command('drop_column', ['columns' => (array) $columns]);
    }

    /**
     * @return $this
     */
    public function rename_column(string $from, string $to)
    {
        return $this->_command('rename_column', ['from' => $from, 'to' => $to]);
    }

    /**
     * @param  string $name Index name, not a column name
     * @return $this
     */
    public function drop_index(string $name)
    {
        return $this->_command('drop_index', ['index' => $name]);
    }

    /**
     * @param  string $name Index name, not a column name
     * @return $this
     */
    public function drop_unique(string $name)
    {
        return $this->_command('drop_index', ['index' => $name]);
    }

    /**
     * @return $this
     */
    public function drop_primary()
    {
        return $this->_command('drop_primary', []);
    }

    /**
     * @return $this
     */
    public function rename(string $to)
    {
        return $this->_command('rename', ['to' => $to]);
    }

    /**
     * Record a column.
     *
     * @param  array<string, mixed> $attributes
     * @return column
     */
    private function _column(string $name, string $type, array $attributes = [])
    {
        $column = new column($name, $type, $attributes);

        $this->_columns[] = $column;

        return $column;
    }

    /**
     * Record a command.
     *
     * @param  array<string, mixed> $parameters
     * @return $this
     */
    private function _command(string $name, array $parameters)
    {
        $this->_commands[] = ['name' => $name] + $parameters;

        return $this;
    }

    /**
     * The name an index gets when the caller did not give one.
     *
     * @param  array<int, string> $columns
     */
    private function _index_name(string $kind, array $columns): string
    {
        $prefix = $kind === 'unique' ? 'uniq' : ($kind === 'fulltext' ? 'ft' : 'idx');
        $name   = $prefix . '_' . $this->_table . '_' . implode('_', $columns);
        $name   = strtolower((string) preg_replace('/[^0-9a-zA-Z_]+/', '_', $name));

        // MySQL stops at 64 characters, and a truncated name still has to be unique
        if ( strlen($name) > 64 )
        {
            $name = substr($name, 0, 55) . '_' . substr(md5($name), 0, 8);
        }

        return $name;
    }
}
