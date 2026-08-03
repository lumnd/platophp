<?php
/**
 * What a fork does to the framework's process scoped resources.
 *
 * Every case runs as a subprocess that boots the framework and then forks for real. It cannot
 * happen inside the PHPUnit process: a worker would run the test runner's shutdown handlers on
 * its way out, and the whole point here is what the workers write on their own.
 *
 * The cache driver is switched to file for these cases. It is the driver a fork can break in a way
 * a test can see without a server running -- a descriptor inherited through fork() shares its file
 * offset with the parent and cannot be serialised by flock() -- and it needs nothing to be
 * reachable. The redis path is covered by tests/Feature/cacheTest.php.
 */

use plato\lock;

/**
 * Runtime directory of one case. Its own, because the framework writes the cache file and the
 * logs under it and the cases must not read each other's.
 *
 * @param string $case
 * @return string
 */
function fork_case_data(string $case): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'platophp-test-fork-' . getmypid() . '-' . $case;
}

/**
 * Boot the framework, run $body, and return the exit status with the combined output.
 *
 * $body is appended to a script that has already called plato::registry(), pointed the cache at
 * the file driver and put $dir in scope.
 *
 * @param string $case Case name, used for the runtime directory and the script file
 * @param string $body PHP source
 * @return array{0: int, 1: string}
 */
function run_fork_script(string $case, string $body): array
{
    $root = dirname(__DIR__, 2);
    $dir  = fork_case_data($case);

    is_dir($dir) || mkdir($dir, 0777, true);

    $script = "<?php\n"
        . 'require ' . var_export($root . '/vendor/autoload.php', true) . ";\n"
        . "use plato\\plato;\n"
        . "use plato\\config;\n"
        . "use plato\\runtime;\n"
        . "use plato\\cache\\cache;\n"
        . "use plato\\log;\n"
        . 'plato::registry(['
        . "'app_path' => " . var_export($root . '/tests/Fixtures/app', true) . ','
        . "'data_path' => " . var_export($dir, true) . ','
        . "'env_path' => " . var_export($root . '/tests/Fixtures/app/.env.testing', true) . ','
        . "]);\n"
        // Before anything touches the cache, so cache::config() reads it
        . "config::instance('cache')->set('cache_type', 'file');\n"
        . '$dir = ' . var_export($dir, true) . ";\n"
        . $body;

    $file = $dir . DIRECTORY_SEPARATOR . 'case.php';
    file_put_contents($file, $script);

    $output = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1', $output, $status);

    return [$status, implode("\n", $output)];
}

/**
 * Remove a runtime directory of this test file.
 *
 * @param string $case
 * @return void
 */
function fork_case_cleanup(string $case): void
{
    $dir = fork_case_data($case);

    if ( !is_dir($dir) )
    {
        return;
    }

    // Two globs and not one GLOB_BRACE pattern: the constant is a glibc extension and is undefined
    // on musl, so the alpine containers the suite runs in threw out of here before it deleted
    // anything -- which left every case after the first reading the one before it's files
    $items = array_merge((array) glob($dir . '/*'), (array) glob($dir . '/*/*'));

    foreach ( $items as $item )
    {
        is_file($item) && @unlink($item);
    }

    foreach ( (array) glob($dir . '/*', GLOB_ONLYDIR) as $sub )
    {
        @rmdir($sub);
    }

    @rmdir($dir);
}

beforeEach(function () {
    if ( !function_exists('pcntl_fork') || !function_exists('posix_getpid') )
    {
        $this->markTestSkipped('the fork cases need the pcntl and posix extensions');
    }
});

it('hands a forked child an empty registry and a new epoch', function () {
    [$status, $output] = run_fork_script('epoch', <<<'PHP'
cache::set('warm', 'the driver is open now');

$parent_epoch = runtime::epoch();
$parent_keys  = count(runtime::keys());

$pid = pcntl_fork();
if ($pid === 0)
{
    printf(
        "child epoch=%d keys=%d abandoned=%d noticed=%s\n",
        runtime::epoch(),
        count(runtime::keys()),
        runtime::abandoned(),
        runtime::forked() ? 'late' : 'already'
    );
    exit(0);
}

pcntl_waitpid($pid, $s);
printf("parent epoch=%d keys=%d\n", $parent_epoch, $parent_keys);
PHP);

    expect($status)->toBe(0);
    expect($output)->toContain('parent epoch=0');
    // The driver the parent opened is registered, and gone again in the child
    expect($output)->toContain('keys=1');
    // The inherited driver is held rather than closed, so its teardown never reaches a descriptor
    // the parent is still using
    expect($output)->toContain('child epoch=1 keys=0 abandoned=1');
    // runtime::epoch() at the top of the printf already noticed the fork
    expect($output)->toContain('noticed=already');

    fork_case_cleanup('epoch');
});

it('counts exactly once per increment across forked workers', function () {
    [$status, $output] = run_fork_script('counter', <<<'PHP'
$workers = 6;
$steps   = 60;
$gate    = $dir . '/go';

// Opened in the parent on purpose: the children inherit the driver object and have to notice
cache::set('counter', 0);

for ($i = 0; $i < $workers; $i++)
{
    $pid = pcntl_fork();
    if ($pid === -1) { fwrite(STDERR, "fork failed\n"); exit(1); }
    if ($pid === 0)
    {
        // Every worker waits for the gate, so they contend instead of each finishing its share
        // before the next one is even forked -- without this the case proves nothing
        while (!file_exists($gate)) { usleep(200); }

        for ($n = 0; $n < $steps; $n++)
        {
            cache::inc('counter');
        }
        exit(0);
    }
}

touch($gate);

while (pcntl_waitpid(0, $s) > 0) {}

// The parent memoises nothing under CLI, so this is a read of the file
echo 'counter=', (int) cache::get('counter'), ' expected=', $workers * $steps, "\n";
PHP);

    expect($status)->toBe(0);
    expect($output)->toContain('counter=360 expected=360');

    fork_case_cleanup('counter');
});

it('keeps the log lines of forked workers whole and complete', function () {
    [$status, $output] = run_fork_script('log', <<<'PHP'
$workers = 5;
$lines   = 40;

// The parent opens the handle first, so every worker starts from an inherited one
log::error('parent line');

for ($i = 0; $i < $workers; $i++)
{
    $pid = pcntl_fork();
    if ($pid === -1) { fwrite(STDERR, "fork failed\n"); exit(1); }
    if ($pid === 0)
    {
        for ($n = 0; $n < $lines; $n++)
        {
            log::error('worker ' . getmypid() . ' line ' . $n);
        }
        exit(0);
    }
}

while (pcntl_waitpid(0, $s) > 0) {}

$file    = $dir . '/log/error.log';
$content = (string) file_get_contents($file);
$rows    = array_values(array_filter(explode("\n", $content), 'strlen'));
$whole   = 0;

foreach ($rows as $row)
{
    // Every line carries the whole template, so a short write shows up as a row that does not
    // match rather than as a row that is merely missing
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \[ERROR\] --> /', $row))
    {
        $whole++;
    }
}

echo 'rows=', count($rows), ' whole=', $whole, ' expected=', $workers * $lines + 1, "\n";
PHP);

    expect($status)->toBe(0);
    expect($output)->toContain('rows=201 whole=201 expected=201');

    fork_case_cleanup('log');
});

it('does not let a child release a lock its parent is holding', function () {
    try
    {
        $probe = 'fork-probe-' . getmypid();
        lock::lock($probe, 0, 1) && lock::unlock($probe);
    }
    catch ( Throwable $e )
    {
        $this->markTestSkipped('the lock cases need a reachable redis: ' . $e->getMessage());
    }

    [$status, $output] = run_fork_script('lock', <<<'PHP'
use plato\lock;

$name = 'fork-safety-' . getmypid();

if (!lock::lock($name, 0, 30))
{
    fwrite(STDERR, "the parent could not take the lock\n");
    exit(1);
}

$pid = pcntl_fork();
if ($pid === 0)
{
    // The child inherited the token of the lock the parent is holding. Releasing on that is
    // exactly what must not happen
    echo 'child unlocked=', lock::unlock($name) ? 'yes' : 'no', "\n";
    exit(0);
}

pcntl_waitpid($pid, $s);

echo 'still locked=', lock::is_locking($name) ? 'yes' : 'no', "\n";
echo 'parent unlocked=', lock::unlock($name) ? 'yes' : 'no', "\n";
PHP);

    expect($status)->toBe(0);
    expect($output)->toContain('child unlocked=no');
    expect($output)->toContain('still locked=yes');
    expect($output)->toContain('parent unlocked=yes');

    fork_case_cleanup('lock');
});

it('leaves a pool worker nothing to inherit', function () {
    [$status, $output] = run_fork_script('pool', <<<'PHP'
use plato\pool;

// Warm every resource the pool is supposed to release before it forks
cache::set('warm', 'value');
log::error('parent line');

$marker = $dir . '/keys.txt';

$code = pool::supervise(function () use ($marker)
{
    file_put_contents(
        $marker,
        sprintf(
            "worker keys=%d epoch=%d abandoned=%d\n",
            count(runtime::keys()),
            runtime::epoch(),
            runtime::abandoned()
        ),
        FILE_APPEND | LOCK_EX
    );
}, 2, ['restart' => false, 'poll' => 0.02]);

echo (string) file_get_contents($marker);
echo 'code=', $code, "\n";
PHP);

    expect($status)->toBe(0);
    expect($output)->toContain('code=0');
    // supervise() flushed in the master, so a worker inherits nothing at all -- not even something
    // to abandon, which is the difference between the pool and a bare pcntl_fork()
    expect(substr_count($output, 'worker keys=0 epoch=1 abandoned=0'))->toBe(2);

    fork_case_cleanup('pool');
});
