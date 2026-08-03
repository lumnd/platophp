<?php

/**
 * Base class for a seeder: a file returns one of these with run() filled in
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\database;

use RuntimeException;

/**
 * Reference data, written by code rather than by hand.
 *
 *     use plato\database\db;
 *     use plato\database\seeder;
 *
 *     return new class extends seeder
 *     {
 *         public function run(): void
 *         {
 *             $this->call(['country', 'currency']);   // other seeder files, first
 *
 *             db::table('#PB#role')->upsert([
 *                 ['code' => 'admin',  'label' => 'Administrator'],
 *                 ['code' => 'editor', 'label' => 'Editor'],
 *             ], ['label']);
 *         }
 *     };
 *
 * **A seeder is not a migration.** Nothing records that it ran, so `db:seed` can be run twice and
 * a seeder has to survive that — which is why the example upserts rather than inserts. The
 * distinction is deliberate: a migration changes the shape of the database exactly once, a seeder
 * puts data into whatever shape is there now, and giving seeders a ledger would only invite them
 * to be written as one-shot data migrations. Data that has to change exactly once **is** a
 * migration; write it as one.
 *
 * There is no factory alongside this. A factory generates model instances, and this package has no
 * model layer to generate — `db::table()->insert()` in a seeder is the whole of what one would do.
 */
abstract class seeder
{
    /**
     * Directory the seeder files live in, set by the console before run() is called.
     *
     * @var string
     */
    private static $_path = '';

    /**
     * Names already run in this process, so a diamond in the call graph does not run twice.
     *
     * @var array<string, bool>
     */
    private static $_ran = [];

    /**
     * Tell the seeders where their siblings are.
     */
    public static function locate(string $path): void
    {
        self::$_path = rtrim($path, DIRECTORY_SEPARATOR);
        self::$_ran  = [];
    }

    /**
     * Write the data.
     */
    abstract public function run(): void;

    /**
     * Run other seeders by file name, without the extension, each at most once per process.
     *
     * @param  string|array<int, string> $names
     * @throws RuntimeException When a name has no file
     */
    public function call($names): void
    {
        foreach ( (array) $names as $name )
        {
            self::run_file((string) $name);
        }
    }

    /**
     * Load one seeder file and run it, unless it already ran.
     *
     * @param  string $name File name without the extension
     * @return bool   Whether it ran now; false means it had already run
     * @throws RuntimeException
     */
    public static function run_file(string $name): bool
    {
        $name = trim($name);

        if ( isset(self::$_ran[$name]) )
        {
            return false;
        }

        $file = self::$_path . DIRECTORY_SEPARATOR . $name . '.php';

        if ( !is_file($file) )
        {
            throw new RuntimeException('no seeder file at ' . $file);
        }

        $seeder = require $file;

        if ( !$seeder instanceof self )
        {
            throw new RuntimeException($file . ' has to return a plato\database\seeder');
        }

        // Marked before running, not after: a seeder calling back into the one that called it
        // would otherwise recurse until the stack gives out
        self::$_ran[$name] = true;

        $seeder->run();

        return true;
    }

    /**
     * Names of the seeder files that have run in this process.
     *
     * @return array<int, string>
     */
    public static function ran(): array
    {
        return array_keys(self::$_ran);
    }
}
