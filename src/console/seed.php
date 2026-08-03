<?php

/**
 * Console command: run the seeders that put reference data into the database
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\console;

use plato\database\seeder;
use Throwable;

/**
 * `db:seed`, and `make:seeder` writes the file it runs.
 *
 * With no `--class`, every `*.php` in the seeder directory runs in file name order, which is why
 * a project that cares about the order numbers them the way migrations are numbered. With one, only
 * that file runs — along with whatever it calls itself.
 *
 * Nothing records that a seeder ran. See plato\database\seeder for why, and for what that means
 * about how one has to be written.
 */
class seed implements command
{
    /**
     * @return array<string, string>
     */
    public static function names(): array
    {
        return [
            'db:seed' => 'Run the seeders in the seeder directory',
        ];
    }

    /**
     * @param string $name
     *
     * @return string
     */
    public static function usage(string $name): string
    {
        return '  --class=NAME           Run only this seeder file, without the .php'
            . PHP_EOL . '  --seeder-path=DIR      Directory to read, default <root>/database/seeders';
    }

    /**
     * @return array<int, string>
     */
    public static function requires(): array
    {
        return ['seeder_path'];
    }

    /**
     * @param string $name
     *
     * @return int
     */
    public static function handle(string $name): int
    {
        $dir = console::path('seeder_path');

        seeder::locate($dir);

        $only = console::option('class');

        $names = is_string($only) && $only !== ''
            ? [$only]
            : self::_files($dir);

        if ( $names === [] )
        {
            console::warn('No seeders in ' . $dir);

            return console::OK;
        }

        foreach ( $names as $one )
        {
            try
            {
                if ( seeder::run_file($one) )
                {
                    console::success('Seeded ' . $one);
                }
            }
            catch ( Throwable $e )
            {
                // Stop rather than carry on: a later seeder usually assumes the earlier one worked
                console::fail($one . ' failed: ' . $e->getMessage());

                return console::FAILURE;
            }
        }

        return console::OK;
    }

    /**
     * Seeder file names in the directory, without the extension, in name order.
     *
     * @param  string $dir
     * @return array<int, string>
     */
    private static function _files(string $dir): array
    {
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.php');
        $files = is_array($files) ? $files : [];

        sort($files);

        return array_map(static function (string $file): string
        {
            return basename($file, '.php');
        }, $files);
    }
}
