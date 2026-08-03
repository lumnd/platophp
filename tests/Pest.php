<?php
/**
 * Pest bootstrap.
 *
 * The framework needs an application to run against, so tests/Fixtures/app plays that part: it
 * holds the config overlay, the application Smarty plugins, the templates and the .env. It is a
 * fixture, not part of the package -- nothing under tests/ other than Unit/, Feature/ and
 * Fixtures/ belongs here.
 *
 * Everything the framework writes at runtime goes to a temporary directory that is removed when
 * the process exits, so a test run leaves nothing behind and never reads what the previous run
 * wrote.
 */

use plato\plato;

/*
|--------------------------------------------------------------------------
| Test case
|--------------------------------------------------------------------------
*/

uses(Tests\TestCase::class)->in('Unit', 'Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Root of the fixture application.
 *
 * @param string $path Relative path, '' for the root itself
 * @return string
 */
function plato_test_app(string $path = ''): string
{
    $root = __DIR__ . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'app';

    return $path === '' ? $root : $root . DIRECTORY_SEPARATOR . ltrim($path, '/');
}

/**
 * Writable runtime directory of this process, under the system temporary directory.
 *
 * @return string
 */
function plato_test_data(): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'platophp-test-' . getmypid();
}

/**
 * Remove a directory tree, guarded so it can only ever delete a platophp test directory.
 *
 * @param string $path
 * @return void
 */
function plato_test_rmdir(string $path): void
{
    $prefix = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'platophp-test-';
    if ( !is_dir($path) || strpos($path, $prefix) !== 0 )
    {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ( $items as $item )
    {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }

    @rmdir($path);
}

/**
 * Read back what a closure appended to one level's log file.
 *
 * Under CLI every log::write() flushes straight away, so the file can be read as soon as the
 * closure returns. Only the tail is returned: the suite shares one log directory, so what earlier
 * cases wrote is in front of it.
 *
 * @param string   $level Level name, which is also the file name
 * @param callable $write Code that produces the log entries
 * @return string
 */
function log_test_capture(string $level, callable $write): string
{
    $file   = plato::log_path($level . '.log');
    $before = is_file($file) ? filesize($file) : 0;

    $write();

    clearstatcache(true, $file);

    return is_file($file) ? (string) file_get_contents($file, false, null, $before) : '';
}

/**
 * Runtime configuration of the fixture application.
 *
 * @param array<string, mixed> $overrides Values to override for a single call
 * @return array<string, mixed>
 */
function plato_test_config(array $overrides = []): array
{
    return array_merge([
        'app_path'  => plato_test_app(),
        'data_path' => plato_test_data(),
        'env_path'  => plato_test_app('.env.testing'),
        'debug'     => true,
        'env'       => 'dev',
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| Framework bootstrap
|--------------------------------------------------------------------------
|
| registry() runs once for the whole suite. PHPUnit builds every test file in a single process,
| so booting per file only registered the same shutdown handler over and over. Tests that need
| other settings change them through the class under test -- route::configure(),
| envelope::configure(), config::instance()->set() -- and undo it in beforeEach().
|
*/

plato::registry(plato_test_config());

// The cleanup has to be the very last thing to run: log::boot() registers its own shutdown
// handler, and a test that logs without the framework booted registers it on its first write,
// which may well be after this point -- log::save() would then write into a directory that is
// already gone. A handler registered from inside a handler is appended to the end of the queue,
// so this one always runs after every handler registered during normal execution.
register_shutdown_function(function () {
    register_shutdown_function(function () {
        plato_test_rmdir(plato_test_data());
    });
});
