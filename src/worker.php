<?php

/**
 * Which of its group's processes this one is, whoever started the group
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato;

/**
 * Process identity inside a group of identical workers.
 *
 * Every process starter registers its workers here:
 *
 *   plato\pool        calls enter() in the child, right after the fork
 *   a server adapter  calls enter() in each worker process, once its index is known
 *
 * and everything downstream asks the same question the same way:
 *
 *     if ( worker::owns($account_id) )
 *     {
 *         // exactly one worker of this group does this
 *     }
 *
 * **Outside a group this class says so rather than guessing.** A php-fpm request, a plain CLI
 * script and a lone consumer are all "not in a pool": index() is -1 and count() is 0. `owns()`
 * answers true there, because a process that is alone owns all of its own work and code written
 * with the guard has to keep working when it is run singly.
 *
 * The consequence is worth stating plainly: **an adapter that forks workers and forgets to call
 * enter() leaves every one of them thinking it is alone**, and every one of them runs the timer.
 * Registering is part of what it means to implement plato\server\driver, not an optimisation.
 *
 * Nothing here survives a fork by accident. A child inherits the parent's values like it inherits
 * everything else, which is exactly why `pool` overwrites them in the child before running any of
 * the caller's code.
 */
class worker
{
    /**
     * Index of this process among its group's, counting from 0, and -1 when it is in no group.
     *
     * @var int
     */
    private static $_index = -1;

    /**
     * How many workers the group was started with, 0 when this process is in no group.
     *
     * @var int
     */
    private static $_count = 0;

    /**
     * Claim this process as worker $index of $count.
     *
     * Called by whatever started the process, in the process itself and before any of the work --
     * `pool` in the child after the fork, a server adapter in each worker it forks.
     *
     * @param int $index Index among the group's workers, counting from 0
     * @param int $count How many workers the group was started with
     *
     * @return void
     */
    public static function enter(int $index, int $count): void
    {
        if ( $index < 0 || $count < 1 || $index >= $count )
        {
            self::leave();

            return;
        }

        self::$_index = $index;
        self::$_count = $count;
    }

    /**
     * Give up the claim, putting the process back to "in no group".
     *
     * A worker normally exits rather than returning to being nobody, so this is for a supervisor
     * that runs work in its own process and for a test that has to leave no state behind.
     *
     * @return void
     */
    public static function leave(): void
    {
        self::$_index = -1;
        self::$_count = 0;
    }

    /**
     * Index of this process among its group's, counting from 0, and -1 when it is in no group.
     *
     * @return int
     */
    public static function index(): int
    {
        return self::$_index;
    }

    /**
     * How many workers the group was started with, 0 when this process is in no group.
     *
     * @return int
     */
    public static function count(): int
    {
        return self::$_count;
    }

    /**
     * Whether this process is one of a group at all.
     *
     * @return bool
     */
    public static function in_group(): bool
    {
        return self::$_index >= 0 && self::$_count > 0;
    }

    /**
     * Whether this process is the one of its group that handles $key.
     *
     * The point of it is a job exactly one worker may do -- a periodic sweep, a migration of due
     * messages, a cache warm -- without taking a lock to find out. Pass a stable number: an account
     * id, a shard number, or 0 for "just one of you, any of you".
     *
     * True outside a group, see the class docblock.
     *
     * @param int $key Stable number identifying the work
     *
     * @return bool
     */
    public static function owns(int $key = 0): bool
    {
        if ( !self::in_group() )
        {
            return true;
        }

        return abs($key) % self::$_count === self::$_index;
    }
}
