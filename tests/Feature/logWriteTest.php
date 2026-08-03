<?php
/**
 * log's write path: the append handle it holds open, and what makes it drop that handle.
 *
 * Every case runs as a subprocess. The handle lives for the life of the process, so a case that
 * checks what happens after a rotation or a fork has to own the process it runs in -- inside the
 * PHPUnit process the handle would be shared with every other test file, and forking there would
 * leave each worker running the test runner's shutdown handlers on its way out.
 */

/**
 * Boot the framework in a subprocess with a data directory of its own, run $body, and return the
 * exit status with the combined output.
 *
 * @param string $body PHP source, run with log imported and $log set to the info level file
 * @return array{0: int, 1: string, 2: string} Status, output, and the data directory used
 */
function run_log_script(string $body): array
{
    $data = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'platophp-test-logwrite-' . getmypid() . '-' . md5($body);
    plato_test_rmdir($data);

    $script = "<?php\n"
        . 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n"
        . "use plato\\log;\n"
        . "use plato\\plato;\n"
        . 'plato::registry(' . var_export([
            'app_path'  => plato_test_app(),
            'data_path' => $data,
            'env_path'  => plato_test_app('.env.testing'),
            'debug'     => false,
            'env'       => 'dev',
        ], true) . ");\n"
        . '$log = plato::log_path(\'info.log\');' . "\n"
        . $body;

    $dir = $data . DIRECTORY_SEPARATOR . 'script';
    is_dir($dir) || mkdir($dir, 0777, true);

    $file = $dir . DIRECTORY_SEPARATOR . 'case.php';
    file_put_contents($file, $script);

    $output = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1', $output, $status);

    return [$status, implode("\n", $output), $data];
}

afterEach(function () {
    foreach ( (array) glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'platophp-test-logwrite-*') as $dir )
    {
        plato_test_rmdir((string) $dir);
    }
});

it('creates the level file on the first entry and leaves its mode alone afterwards', function () {
    // An existing file keeps a mode set by hand or by the host's own tooling.
    [$status, $output] = run_log_script(<<<'PHP'
log::info('first');
$created = substr(sprintf('%o', fileperms($log)), -4);

chmod($log, 0600);
log::info('second');
clearstatcache(true, $log);

echo 'created=', $created, ' after=', substr(sprintf('%o', fileperms($log)), -4), "\n";
echo 'lines=', substr_count((string) file_get_contents($log), "\n"), "\n";
PHP);

    expect($status)->toBe(0)
        ->and($output)->toContain('created=0777 after=0600')
        ->and($output)->toContain('lines=2');
});

it('reopens the file after a rename, so a rotated log does not swallow later entries', function () {
    [$status, $output] = run_log_script(<<<'PHP'
log::info('before-rotate');
rename($log, $log . '.1');
log::info('after-rotate');

$live    = (string) file_get_contents($log);
$rotated = (string) file_get_contents($log . '.1');

echo 'live_has_after=',    (int) (strpos($live, 'after-rotate') !== false);
echo ' live_has_before=',  (int) (strpos($live, 'before-rotate') !== false);
echo ' rotated_has_after=',(int) (strpos($rotated, 'after-rotate') !== false), "\n";
PHP);

    expect($status)->toBe(0)
        ->and($output)->toContain('live_has_after=1 live_has_before=0 rotated_has_after=0');
});

it('recreates the file after it is deleted', function () {
    [$status, $output] = run_log_script(<<<'PHP'
log::info('before-unlink');
unlink($log);
log::info('after-unlink');

echo 'exists=', (int) is_file($log);
echo ' has_after=', (int) (strpos((string) file_get_contents($log), 'after-unlink') !== false), "\n";
PHP);

    expect($status)->toBe(0)
        ->and($output)->toContain('exists=1 has_after=1');
});

it('gives a forked worker an open file description of its own', function () {
    if ( !function_exists('pcntl_fork') )
    {
        $this->markTestSkipped('the fork case needs the pcntl extension');
    }

    // The case below only shows that nothing was torn or lost, which an inherited handle manages
    // on its own here: an O_APPEND write to a local file is atomic. This one checks the reason the
    // handle is reopened anyway -- flock() locks belong to the open file description, so workers
    // that share the parent's would all be granted LOCK_EX at once and the lock would mean nothing
    [$status, $output] = run_log_script(<<<'PHP'
$id = static function () use ($log) {
    // The handle is registered with the runtime rather than held here, which is what drops it
    // when the process forks
    // Fully qualified: the script imports plato\plato, so `plato\...` would resolve through
    // that alias into plato\plato\...
    $entry = \plato\runtime::get('log.handle.' . $log);

    return $entry === null ? 0 : get_resource_id($entry['handle']);
};

log::info('parent-line');
$parent = $id();

if (pcntl_fork() === 0)
{
    log::info('child-line');
    echo 'reopened=', (int) ($id() !== $parent), "\n";
    exit(0);
}

pcntl_waitpid(-1, $st);
echo 'parent_kept=', (int) ($id() === $parent), "\n";
PHP);

    expect($status)->toBe(0)
        ->and($output)->toContain('reopened=1')
        ->and($output)->toContain('parent_kept=1');
});

it('keeps entries whole when forked workers append to the same file', function () {
    if ( !function_exists('pcntl_fork') )
    {
        $this->markTestSkipped('the fork case needs the pcntl extension');
    }

    // 8 workers writing 250 entries each, on a handle the parent had already opened. The child
    // has to reopen it: flock() cannot serialise processes that share one open file description
    [$status, $output] = run_log_script(<<<'PHP'
log::info('parent-line');

for ($w = 0; $w < 8; $w++)
{
    if (pcntl_fork() === 0)
    {
        for ($i = 0; $i < 250; $i++)
        {
            log::info('forkline-' . getmypid() . '-' . $i . '-' . str_repeat('x', 200));
        }
        exit(0);
    }
}

while (pcntl_waitpid(-1, $st) > 0) {}

$lines = array_filter(explode("\n", (string) file_get_contents($log)), 'strlen');
$whole = 0;
foreach ($lines as $line)
{
    // The trailing context is the shared request id, which every child inherited from the parent:
    // a fork continues the request that forked it, it does not start one
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \[INFO\] --> (parent-line|forkline-\d+-\d+-x{200}) \{"rid":"[0-9a-f]{16}"\}$/', $line))
    {
        $whole++;
    }
}

echo 'lines=', count($lines), ' whole=', $whole, "\n";
PHP);

    expect($status)->toBe(0)
        ->and($output)->toContain('lines=2001 whole=2001');
});
