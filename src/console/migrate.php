<?php

/**
 * Console command: apply, roll back and report database migrations
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\console;

use plato\database\migrator;

/**
 * The three migration verbs, over one plato\database\migrator.
 *
 * The runner itself decides everything that matters -- one batch per run, a server side lock so two
 * deploys cannot apply the same file, files loaded before any of them runs -- and this class only
 * turns what it returns into lines and an exit code.
 *
 * `--table` and `--connection` exist because a project with two databases migrates them
 * separately: each run needs its own repository table, or the second connection would look at the
 * first one's record of what has run.
 */
class migrate implements command
{
    /**
     * @return array<string, string>
     */
    public static function names(): array
    {
        return [
            'migrate'          => 'Apply every migration that has not run yet',
            'migrate:status'   => 'List the migrations and whether they have run',
            'migrate:rollback' => 'Roll back the migrations of the last batch',
        ];
    }

    /**
     * @param string $name
     *
     * @return string
     */
    public static function usage(string $name): string
    {
        return '  --migration-path=DIR   Directory the migration files live in'
            . PHP_EOL . '  --table=NAME           Repository table, default #PB#_migrations'
            . PHP_EOL . '  --connection=NAME      Connection to run against, default the configured one';
    }

    /**
     * @return array<int, string>
     */
    public static function requires(): array
    {
        // Checked before the framework boots: an unreachable migration directory is a typo in an
        // option, and it should not cost a bootstrap -- or leave a data directory behind -- to say so
        return ['migration_path'];
    }

    /**
     * @param string $name
     *
     * @return int
     */
    public static function handle(string $name): int
    {
        $table      = (string) console::option('table', '#PB#_migrations');
        $connection = console::option('connection');
        $migrator   = new migrator(
            console::path('migration_path'),
            $table,
            is_string($connection) && $connection !== '' ? $connection : null
        );

        if ( $name === 'migrate:status' )
        {
            return self::_status($migrator);
        }

        if ( $name === 'migrate:rollback' )
        {
            return self::_report($migrator->rollback(), 'rolled back', 'Nothing to roll back.');
        }

        return self::_report($migrator->migrate(), 'migrated', 'Nothing to migrate.');
    }

    /**
     * Print what ran.
     *
     * @param array<int, string> $files Files the migrator touched
     * @param string             $verb  Word to prefix each file with
     * @param string             $empty Line to print when there were none
     *
     * @return int
     */
    private static function _report(array $files, string $verb, string $empty): int
    {
        if ( !$files )
        {
            console::warn($empty);

            return console::OK;
        }

        foreach ( $files as $file )
        {
            console::success($verb . ': ' . $file);
        }

        return console::OK;
    }

    /**
     * Print the status table.
     *
     * @param migrator $migrator
     *
     * @return int
     */
    private static function _status(migrator $migrator): int
    {
        $rows = $migrator->status();

        if ( !$rows )
        {
            console::warn('No migration files.');

            return console::OK;
        }

        foreach ( $rows as $row )
        {
            console::line(sprintf(
                '%-8s batch=%-4s %s',
                $row['status'],
                $row['batch'] === null ? '-' : $row['batch'],
                $row['migration']
            ));
        }

        return console::OK;
    }
}
