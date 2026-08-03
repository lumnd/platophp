<?php
/**
 * plato\pool, the foreground process supervisor.
 *
 * Every case runs as a subprocess. Forking inside the PHPUnit process would leave each worker
 * running the test runner's shutdown handlers on its way out, and the cases that check a worker
 * that crashes or ignores SIGTERM need it to exit non-zero without ending the suite.
 *
 * The scripts require the two class files directly rather than booting the framework: the pool
 * depends on nothing but plato\runtime, and a bootstrap would only add the framework's own
 * shutdown handlers to what the workers run.
 *
 * Timings are deliberately short -- poll and backoff are options for this reason -- but every
 * assertion is on a count or an ordering, never on a duration, except the two that check something
 * finished at all rather than hanging.
 */

/**
 * Write $body into a script that has already required the class, run it, and return the exit status
 * with the combined output.
 *
 * @param string $body PHP source, run with pool imported and $dir set to its own directory
 * @return array{0: int, 1: string}
 */
function run_pool_script(string $body): array
{
    $dir = plato_test_data() . DIRECTORY_SEPARATOR . 'pool';
    is_dir($dir) || mkdir($dir, 0777, true);

    $src    = dirname(__DIR__, 2) . '/src';
    $script = "<?php\n"
        . 'require ' . var_export($src . '/runtime.php', true) . ";\n"
        . 'require ' . var_export($src . '/worker.php', true) . ";\n"
        . 'require ' . var_export($src . '/pool.php', true) . ";\n"
        . "use plato\\pool;\n"
        . "use plato\\runtime;\n"
        . "use plato\\worker;\n"
        . '$dir = ' . var_export($dir, true) . ";\n"
        . "function pool_lines(string \$file): array {\n"
        . "    return array_values(array_filter(explode(\"\\n\", (string) @file_get_contents(\$file))));\n"
        . "}\n"
        . $body;

    $file = $dir . DIRECTORY_SEPARATOR . 'case_' . md5($body) . '.php';
    file_put_contents($file, $script);

    $output = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1', $output, $status);

    return [$status, implode("\n", $output)];
}

beforeEach(function () {
    if ( !function_exists('pcntl_fork') || !function_exists('posix_getpid') )
    {
        $this->markTestSkipped('pool needs the pcntl and posix extensions');
    }
});

it('runs one child per slot, each with its own index and process', function () {
    [$status, $output] = run_pool_script(<<<'PHP'
$file = $dir . '/indexes.txt';
@unlink($file);

$code = pool::supervise(function (int $index) use ($file) {
    file_put_contents($file, $index . ':' . posix_getpid() . "\n", FILE_APPEND | LOCK_EX);
}, 4, ['restart' => false, 'poll' => 0.02]);

$indexes = [];
$pids    = [];

foreach ( pool_lines($file) as $line )
{
    [$index, $pid] = explode(':', $line);
    $indexes[]     = (int) $index;
    $pids[]        = $pid;
}

sort($indexes);

echo 'code=', $code, ' indexes=', implode(',', $indexes), ' pids=', count(array_unique($pids)), "\n";
echo 'master_worker=', worker::index(), ' master_count=', worker::count(), "\n";
PHP);

    expect($status)->toBe(0)
        ->and($output)->toContain('code=0 indexes=0,1,2,3 pids=4')
        // The master never claimed a worker identity, so it still reads as being in no group
        ->and($output)->toContain('master_worker=-1 master_count=0');
});

it('puts a fresh worker in the place of one that exited', function () {
    [$status, $output] = run_pool_script(<<<'PHP'
$file = $dir . '/restarts.txt';
@unlink($file);

// One slot, so the starts are strictly sequential and the count below cannot race
$code = pool::supervise(function () use ($file) {
    $started = count(pool_lines($file)) + 1;

    file_put_contents($file, "up\n", FILE_APPEND | LOCK_EX);

    if ( $started >= 5 )
    {
        posix_kill(posix_getppid(), SIGTERM);
    }
}, 1, ['backoff' => 0.02, 'poll' => 0.02]);

echo 'code=', $code, ' starts=', count(pool_lines($file)), "\n";
PHP);

    expect($status)->toBe(0)
        ->and($output)->toContain('code=0 starts=5');
});

it('forwards a signal to every worker and waits for each of them to drain', function () {
    [$status, $output] = run_pool_script(<<<'PHP'
$ready   = $dir . '/drain_ready.txt';
$drained = $dir . '/drain_done.txt';
@unlink($ready);
@unlink($drained);

$code = pool::supervise(function (int $index) use ($ready, $drained) {
    file_put_contents($ready, "up\n", FILE_APPEND | LOCK_EX);

    // One worker stands in for whatever would send the master a signal in production, once the
    // other two have had time to come up
    if ( $index === 0 )
    {
        usleep(300000);
        posix_kill(posix_getppid(), SIGTERM);
    }

    while ( !pool::stopping() )
    {
        usleep(10000);
    }

    // Written after the loop: proves the worker was asked rather than killed, and got to finish
    file_put_contents($drained, "done\n", FILE_APPEND | LOCK_EX);
}, 3, ['poll' => 0.02]);

echo 'code=', $code, ' ready=', count(pool_lines($ready)), ' drained=', count(pool_lines($drained)), "\n";
PHP);

    expect($status)->toBe(0)
        ->and($output)->toContain('code=0 ready=3 drained=3');
});

it('kills a worker that is still there when the grace period is up', function () {
    [$status, $output] = run_pool_script(<<<'PHP'
// The supervisor has to be signalled from outside, so it runs in a child of this script
$pid = pcntl_fork();

if ( $pid === 0 )
{
    exit(pool::supervise(function () {
        // Takes over the handler pool installed and declines to leave
        pcntl_signal(SIGTERM, SIG_IGN);
        sleep(30);
    }, 1, ['grace' => 0.5, 'poll' => 0.02]));
}

usleep(400000);

$start = microtime(true);
posix_kill($pid, SIGTERM);

$status = 0;
pcntl_waitpid($pid, $status);

$elapsed = microtime(true) - $start;

echo 'returned_under_5s=', var_export($elapsed < 5, true), ' code=', pcntl_wexitstatus($status), "\n";
PHP);

    expect($status)->toBe(0)
        ->and($output)->toContain('returned_under_5s=true code=0');
});

it('gives up on a worker that will not stay up, and says which one', function () {
    [$status, $output] = run_pool_script(<<<'PHP'
$code = pool::supervise(function () {
    exit(3);
}, 1, [
    'backoff'     => 0.01,
    'poll'        => 0.01,
    'max_crashes' => 3,
    'notify'      => function (string $line) { echo $line, "\n"; },
]);

echo 'code=', $code, "\n";
PHP);

    expect($status)->toBe(0)
        ->and($output)->toContain('exited 3')
        ->and($output)->toContain('worker 0 exited non-zero 3 times in a row')
        ->and($output)->toContain('code=1');
});

it('holds back a worker that exits cleanly and quickly without ever giving up on it', function () {
    [$status, $output] = run_pool_script(<<<'PHP'
$file = $dir . '/recycle.txt';
@unlink($file);

// max_crashes is two and this restarts eight times: a clean exit must not count towards it, or a
// consumer recycling itself on max_jobs under load would take the pool down
$code = pool::supervise(function () use ($file) {
    $started = count(pool_lines($file)) + 1;

    file_put_contents($file, "up\n", FILE_APPEND | LOCK_EX);

    if ( $started >= 8 )
    {
        posix_kill(posix_getppid(), SIGTERM);
    }
}, 1, ['backoff' => 0.01, 'poll' => 0.01, 'max_crashes' => 2]);

echo 'code=', $code, ' starts=', count(pool_lines($file)), "\n";
PHP);

    expect($status)->toBe(0)
        ->and($output)->toContain('code=0 starts=8');
});

it('exits a worker that threw with 1 and puts the exception on stderr', function () {
    [$status, $output] = run_pool_script(<<<'PHP'
$code = pool::supervise(function () {
    throw new RuntimeException('worker blew up');
}, 1, [
    'backoff'     => 0.01,
    'poll'        => 0.01,
    'max_crashes' => 2,
    'notify'      => function (string $line) { echo $line, "\n"; },
]);

echo 'code=', $code, "\n";
PHP);

    expect($status)->toBe(0)
        ->and($output)->toContain('worker blew up')
        ->and($output)->toContain('exited 1')
        ->and($output)->toContain('code=1');
});

it('reseeds the random generator so two workers do not draw the same sequence', function () {
    [$status, $output] = run_pool_script(<<<'PHP'
$file = $dir . '/random.txt';
@unlink($file);

// Seeds the master: without mt_srand() in the child every worker inherits this state and str::random()
// hands the same string to all four
mt_rand();

pool::supervise(function () use ($file) {
    file_put_contents($file, mt_rand() . "\n", FILE_APPEND | LOCK_EX);
}, 4, ['restart' => false, 'poll' => 0.02]);

$draws = pool_lines($file);

echo 'unique=', count(array_unique($draws)), '/', count($draws), "\n";
PHP);

    expect($status)->toBe(0)
        ->and($output)->toContain('unique=4/4');
});

it('rejects a worker count below one, refuses to nest, and puts the signal handlers back', function () {
    [$status, $output] = run_pool_script(<<<'PHP'
try
{
    pool::supervise(function () {}, 0);
    echo "workers=no_throw\n";
}
catch (Exception $e)
{
    echo 'workers=', $e->getMessage(), "\n";
}

$handler = function () {};
pcntl_signal(SIGTERM, $handler);

$nested = null;

pool::supervise(function () { usleep(50000); }, 1, [
    'restart' => false,
    'poll'    => 0.02,
    // Runs in the master while a pool is up, which is the only place a second supervise() can be
    // reached from
    'notify'  => function () use (&$nested) {
        if ( $nested !== null )
        {
            return;
        }

        try
        {
            pool::supervise(function () {}, 1);
            $nested = 'no_throw';
        }
        catch (Exception $e)
        {
            $nested = $e->getMessage();
        }
    },
]);

echo 'nested=', $nested, "\n";
echo 'restored=', var_export(pcntl_signal_get_handler(SIGTERM) === $handler, true), "\n";
PHP);

    expect($status)->toBe(0)
        ->and($output)->toContain('workers=pool worker count must be at least 1')
        ->and($output)->toContain('nested=pool: a supervisor is already running in this process')
        ->and($output)->toContain('restored=true');
});
