<?php

/**
 * Base class for a migration: a file returns one of these with up() and down() filled in
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\database;

abstract class migration
{
    /**
     * Apply the change.
     */
    abstract public function up(): void;

    /**
     * Undo what up() did.
     */
    abstract public function down(): void;
}
