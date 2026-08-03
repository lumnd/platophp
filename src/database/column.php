<?php

/**
 * One column of a blueprint, and the modifiers chained off it
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\database;

/**
 * A column definition: a name, a portable type, and a bag of attributes.
 *
 * Nothing here knows any SQL. Every modifier writes into the same array the grammar reads, so a
 * dialect that has no concept of, say, `unsigned` simply never looks at that key -- which is
 * cheaper and clearer than a type hierarchy per engine.
 *
 *     $table->string('email', 190)->unique()->comment('login');
 *     $table->integer('views')->unsigned()->default(0);
 *     $table->timestamp('created_at')->use_current();
 *     $table->string('nickname')->nullable()->change();   // alter an existing column
 */
class column
{
    /**
     * @var string
     */
    private $_name;

    /**
     * Portable type name, one of the set blueprint declares
     *
     * @var string
     */
    private $_type;

    /**
     * @var array<string, mixed>
     */
    private $_attributes;

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(string $name, string $type, array $attributes = [])
    {
        $this->_name       = $name;
        $this->_type       = $type;
        $this->_attributes = $attributes;
    }

    public function name(): string
    {
        return $this->_name;
    }

    public function type(): string
    {
        return $this->_type;
    }

    /**
     * @param  mixed       $default
     * @return mixed
     */
    public function get(?string $key = null, $default = null)
    {
        if ( $key === null )
        {
            return $this->_attributes;
        }

        return $this->_attributes[$key] ?? $default;
    }

    /**
     * Whether an attribute was set at all, which is not the same as its value being truthy:
     * `default(0)` and no default at all have to be told apart.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->_attributes);
    }

    /**
     * @return $this
     */
    public function nullable(bool $value = true)
    {
        return $this->_set('nullable', $value);
    }

    /**
     * The default. Pass an expression for something the server evaluates.
     *
     * @param  mixed $value
     * @return $this
     */
    public function default($value)
    {
        return $this->_set('default', $value);
    }

    /**
     * DEFAULT CURRENT_TIMESTAMP, for a timestamp column.
     *
     * @param  bool $on_update Also ON UPDATE CURRENT_TIMESTAMP
     * @return $this
     */
    public function use_current(bool $on_update = false)
    {
        $this->_set('use_current', true);

        return $on_update ? $this->_set('on_update_current', true) : $this;
    }

    /**
     * @return $this
     */
    public function unsigned(bool $value = true)
    {
        return $this->_set('unsigned', $value);
    }

    /**
     * @return $this
     */
    public function auto_increment(bool $value = true)
    {
        return $this->_set('auto_increment', $value);
    }

    /**
     * @return $this
     */
    public function comment(string $comment)
    {
        return $this->_set('comment', $comment);
    }

    /**
     * Place this column after another one, in an ALTER. MySQL only.
     *
     * @return $this
     */
    public function after(string $column)
    {
        return $this->_set('after', $column);
    }

    /**
     * Put this column first, in an ALTER. MySQL only.
     *
     * @return $this
     */
    public function first()
    {
        return $this->_set('first', true);
    }

    /**
     * @return $this
     */
    public function charset(string $charset)
    {
        return $this->_set('charset', $charset);
    }

    /**
     * @return $this
     */
    public function collation(string $collation)
    {
        return $this->_set('collation', $collation);
    }

    /**
     * Modify an existing column rather than add one.
     *
     * The definition **replaces** the old one whole: a column changed to `string(255)` without
     * `nullable()` becomes NOT NULL even if it was nullable before, because that is what MySQL's
     * MODIFY does and pretending otherwise would need a schema read the builder does not do.
     *
     * @return $this
     */
    public function change()
    {
        return $this->_set('change', true);
    }

    /**
     * Make this column the primary key.
     *
     * @return $this
     */
    public function primary()
    {
        return $this->_set('primary', true);
    }

    /**
     * Give this column a unique index of its own.
     *
     * @return $this
     */
    public function unique()
    {
        return $this->_set('unique', true);
    }

    /**
     * Give this column an index of its own.
     *
     * @return $this
     */
    public function index()
    {
        return $this->_set('index', true);
    }

    /**
     * @param  mixed  $value
     * @return $this
     */
    private function _set(string $key, $value)
    {
        $this->_attributes[$key] = $value;

        return $this;
    }
}
