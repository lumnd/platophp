<?php

/**
 * A fragment of SQL the compiler must not touch
 *
 * Wrapping a string in an expression is the one way to get a function call, an operator or a
 * dialect specific keyword past the identifier quoter, which otherwise rejects anything that is
 * not a plain identifier. Nothing inside is escaped or bound, so an expression must never be
 * built out of request data.
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\database;

class expression
{
    /**
     * The fragment, verbatim
     */
    protected string $_value;

    /**
     * Values bound to placeholders inside the fragment, in order
     *
     * @var array<int, mixed>
     */
    protected array $_bindings;

    /**
     * A fragment may carry its own placeholders, so `db::raw('IF(a > ?, ?, ?)', [1, 'y', 'n'])`
     * stays parameterised instead of forcing the caller to interpolate by hand.
     *
     * @param string             $value    SQL fragment
     * @param array<int, mixed>  $bindings Values for the fragment's own placeholders
     */
    public function __construct(string $value, array $bindings = [])
    {
        $this->_value    = $value;
        $this->_bindings = array_values($bindings);
    }

    public function value(): string
    {
        return $this->_value;
    }

    /**
     * @return array<int, mixed>
     */
    public function bindings(): array
    {
        return $this->_bindings;
    }

    public function __toString(): string
    {
        return $this->_value;
    }
}
