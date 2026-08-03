<?php
/**
 * bin/plato, the console entry point.
 *
 * Every case runs the script as a subprocess, which is the only way to cover the bootstrap it does
 * for itself: no APPPATH / ENVPATH constants, paths from the command line options.
 */

/**
 * Run bin/plato and return its exit status together with its combined output.
 *
 * @param array<int, string> $args
 * @return array{0: int, 1: string}
 */
function run_plato_cli(array $args): array
{
    $root    = dirname(__DIR__, 2);
    $command = escapeshellarg(PHP_BINARY)
        . ' '
        . escapeshellarg($root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'plato');

    foreach ($args as $arg)
    {
        $command .= ' ' . escapeshellarg($arg);
    }

    exec($command . ' 2>&1', $output, $status);

    return [$status, implode(PHP_EOL, $output)];
}

it('lists every registered command and the path options', function () {
    [$status, $help] = run_plato_cli(['--help']);

    expect($status)->toBe(0)
        ->and($help)->toContain('migrate')
        ->and($help)->toContain('migrate:status')
        ->and($help)->toContain('migrate:rollback')
        ->and($help)->toContain('make:migration')
        ->and($help)->toContain('queue:work')
        ->and($help)->toContain('--app-path=DIR')
        ->and($help)->toContain('--data-path=DIR')
        ->and($help)->toContain('--migration-path=DIR');
});

it('describes a single command on its own', function () {
    [$status, $help] = run_plato_cli(['help', 'migrate']);

    expect($status)->toBe(0)
        ->and($help)->toContain('php plato migrate')
        ->and($help)->toContain('--table=NAME');
});

it('returns a non-zero exit code for an unknown plato command', function () {
    [$status, $output] = run_plato_cli(['unknown']);

    expect($status)->toBe(1)
        ->and($output)->toContain('Unknown command: unknown');
});

it('reports a missing app directory instead of failing on an undefined APPPATH', function () {
    [$status, $output] = run_plato_cli(['migrate:status', '--app-path=/no/such/app']);

    expect($status)->toBe(1)
        ->and($output)->toContain('Application directory does not exist: /no/such/app');
});

it('reports a missing migration directory before it boots the framework', function () {
    $root = dirname(__DIR__, 2);

    [$status, $output] = run_plato_cli([
        'migrate:status',
        '--app-path=' . $root . '/tests/Fixtures/app',
        '--migration-path=/no/such/migrations',
    ]);

    expect($status)->toBe(1)
        ->and($output)->toContain('Migration directory does not exist: /no/such/migrations');

    // requires() is checked ahead of plato::registry(), which is what keeps a failed run from
    // leaving a data directory inside the fixture application
    expect(is_dir($root . '/tests/Fixtures/app/data'))->toBeFalse();
});

it('boots the framework from cli options without APPPATH / ENVPATH constants', function () {
    $root = dirname(__DIR__, 2);
    // Without --data-path the run would create <app-path>/data, which would put runtime output
    // back into the repository tree
    $data = sys_get_temp_dir() . '/platophp-test-cli';

    [$status, $output] = run_plato_cli([
        'migrate:status',
        '--app-path=' . $root . '/tests/Fixtures/app',
        '--data-path=' . $data,
        '--env-path=' . $root . '/tests/Fixtures/app/.env.testing',
        '--migration-path=' . $root . '/tests/Fixtures/migrations',
    ]);

    plato_test_rmdir($data);

    // Booting is what is under test here: either the migration status comes back, or the database
    // is unreachable and it reports a connection error. What must not happen any more is a
    // bootstrap failure over a path or an undefined constant.
    expect($output)->not->toContain('APPPATH')
        ->and($output)->not->toContain('Application directory does not exist')
        ->and($output)->not->toContain('Migration directory does not exist')
        ->and($output)->not->toContain('vendor/autoload.php');

    if ($status !== 0)
    {
        expect($output)->toContain('migrate:status failed:');
    }
});

it('writes a migration file the migrator can load', function () {
    $root = dirname(__DIR__, 2);
    $dir  = sys_get_temp_dir() . '/platophp-test-make-' . getmypid();
    $data = sys_get_temp_dir() . '/platophp-test-cli';

    [$status, $output] = run_plato_cli([
        'make:migration',
        'CreateWidgetsTable',
        '--app-path=' . $root . '/tests/Fixtures/app',
        '--data-path=' . $data,
        '--env-path=' . $root . '/tests/Fixtures/app/.env.testing',
        '--migration-path=' . $dir,
    ]);

    $written = glob($dir . '/*_create_widgets_table.php') ?: [];

    expect($status)->toBe(0)
        ->and($output)->toContain('Written:')
        ->and($written)->toHaveCount(1);

    // The name has to be one plato\database\migrator accepts, or the file breaks every later run
    expect(basename($written[0]))->toMatch('/^\d{8}_\d{6}_create_widgets_table\.php$/');

    // What the file returns is what the migrator requires of it
    expect(require $written[0])->toBeInstanceOf(plato\database\migration::class);

    foreach (glob($dir . '/*.php') ?: [] as $file)
    {
        unlink($file);
    }
    @rmdir($dir);
    plato_test_rmdir($data);
});
