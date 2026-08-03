<?php
/**
 * What a fork does to a live MySQL connection.
 *
 * This needs a reachable MySQL. A failure to connect is an environment problem, not a broken
 * assertion -- do not weaken a case to make it pass without the server. A developer whose MySQL is
 * not the passwordless one the fixture .env and the CI service describe can point these cases
 * somewhere else with PLATO_TEST_DB_HOST / _PORT / _USERNAME / _PASSWORD / _DATABASE, which are
 * read before the fixture values.
 *
 * The case that matters is the second one. A child that inherited its parent's PDO and then set it
 * to null runs the client teardown against a descriptor the parent is still using, which ends the
 * parent's session -- the parent's next statement fails with "MySQL server has gone away", and it
 * fails somewhere else entirely, long after the fork that caused it. Holding the handles in
 * plato\runtime is what stops that: the registry moves what a child inherited aside instead of
 * dropping the last reference to it.
 *
 * Everything runs as a subprocess. A fork inside the PHPUnit process would have the child run the
 * test runner's shutdown handlers on its way out.
 */

use plato\plato;

// So that db_fork_env() sees the fixture .env whichever files ran before this one
plato::registry(plato_test_config());

/**
 * A connection setting: the test override first, then what the fixture .env loaded.
 *
 * @param string $key     Name without a prefix, e.g. `HOST`
 * @param mixed  $default
 * @return mixed
 */
function db_fork_env(string $key, $default)
{
    foreach (['PLATO_TEST_DB_' . $key, 'DB_' . $key] as $name)
    {
        $value = $_ENV[$name] ?? getenv($name);

        if ($value !== false && $value !== null && $value !== '')
        {
            return $value;
        }
    }

    return $default;
}

/**
 * Runtime directory of one case.
 *
 * @param string $case
 * @return string
 */
function db_fork_dir(string $case): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'platophp-test-dbfork-' . getmypid() . '-' . $case;
}

/**
 * Boot the framework with one MySQL connection configured, run $body, and report what came back.
 *
 * @param string $case Case name, used for the runtime directory and the script file
 * @param string $body PHP source; `db`, `connection` and `runtime` are already imported
 *
 * @return array{status: int, output: string}
 */
function run_db_fork_script(string $case, string $body): array
{
    $root = dirname(__DIR__, 2);
    $dir  = db_fork_dir($case);

    is_dir($dir) || mkdir($dir, 0777, true);

    $settings = [
        'driver'   => 'mysql',
        'database' => (string) db_fork_env('DATABASE', 'platophptest'),
        'username' => (string) db_fork_env('USERNAME', 'root'),
        'password' => (string) db_fork_env('PASSWORD', ''),
        'charset'  => 'utf8mb4',
        'prefix'   => '',
        'host'     => (string) db_fork_env('MASTER_HOST', db_fork_env('HOST', '127.0.0.1')),
        'port'     => (int) db_fork_env('MASTER_PORT', db_fork_env('PORT', 3306)),
        'timeout'  => 5,
    ];

    $script = "<?php\n"
        . 'require ' . var_export($root . '/vendor/autoload.php', true) . ";\n"
        . "use plato\\plato;\n"
        . "use plato\\config;\n"
        . "use plato\\runtime;\n"
        . "use plato\\database\\db;\n"
        . "use plato\\database\\connection;\n"
        . 'plato::registry(['
        . "'app_path' => " . var_export($root . '/tests/Fixtures/app', true) . ','
        . "'data_path' => " . var_export($dir, true) . ','
        . "'env_path' => " . var_export($root . '/tests/Fixtures/app/.env.testing', true) . ','
        . "]);\n"
        . "config::instance('database')->set('connections.fork_case', " . var_export($settings, true) . ");\n"
        . "connection::set_default('fork_case');\n"
        // The case's own runtime directory, for a body that has to hand something between the two
        // processes. Without it a body writing to $dir writes to the filesystem root instead,
        // which only looks harmless when the tests happen to run as root
        . '$dir = ' . var_export($dir, true) . ";\n"
        . $body;

    $file = $dir . DIRECTORY_SEPARATOR . 'case.php';
    file_put_contents($file, $script);

    $output = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1', $output, $status);

    return ['status' => $status, 'output' => implode("\n", $output)];
}

/**
 * @param string $case
 * @return void
 */
function db_fork_cleanup(string $case): void
{
    $dir = db_fork_dir($case);

    if (!is_dir($dir))
    {
        return;
    }

    foreach (array_merge((array) glob($dir . '/*'), (array) glob($dir . '/*/*')) as $item)
    {
        is_file($item) && @unlink($item);
    }

    foreach ((array) glob($dir . '/*', GLOB_ONLYDIR) as $sub)
    {
        @rmdir($sub);
    }

    @rmdir($dir);
}

beforeEach(function () {
    if (!function_exists('pcntl_fork') || !function_exists('posix_getpid'))
    {
        $this->markTestSkipped('the fork cases need the pcntl and posix extensions');
    }

    if (!extension_loaded('pdo_mysql'))
    {
        $this->markTestSkipped('the mysql cases need ext-pdo_mysql');
    }
});

it('dials its own socket in the child instead of interleaving on the inherited one', function () {
    $body = <<<'PHP'
$parent = (int) db::select_raw('select connection_id() as id')[0]['id'];

$pid = pcntl_fork();

if ($pid === 0)
{
    // The registry noticed the fork, so this is a connection of the child's own
    $child = (int) db::select_raw('select connection_id() as id')[0]['id'];

    echo 'child=', $child, ' abandoned=', runtime::abandoned(), "\n";

    exit(0);
}

pcntl_waitpid($pid, $status);

echo 'parent=', $parent, "\n";
PHP;

    $result = run_db_fork_script('separate', $body);

    expect($result['status'])->toBe(0);

    preg_match('/child=(\d+) abandoned=(\d+)/', $result['output'], $child);
    preg_match('/parent=(\d+)/', $result['output'], $parent);

    expect($child)->not->toBeEmpty($result['output']);
    expect($parent)->not->toBeEmpty($result['output']);

    // Two MySQL sessions, so the two processes are not taking turns on one socket
    expect($child[1])->not->toBe($parent[1]);

    // And the one the child inherited was held rather than destroyed
    expect((int) $child[2])->toBeGreaterThan(0);

    db_fork_cleanup('separate');
});

it('leaves the parent session alive while a child tears its own connection down', function () {
    // The parent is asked for its session id while the child is still running, on purpose. What a
    // child does at exit is not the framework's to control -- PHP destroys the child's copy of
    // every object, and PDO says goodbye to the server on the way out, which is why plato\pool has
    // the parent release everything *before* it forks. What is the framework's to control is what
    // the child does on its own: dial for itself, and let go of what it inherited without running
    // a teardown on it.
    $body = <<<'PHP'
$before = (int) db::select_raw('select connection_id() as id')[0]['id'];
$flag   = $dir . '/child-done';

$pid = pcntl_fork();

if ($pid === 0)
{
    // Everything a child would normally do, in the order it would do it. Under the old design the
    // fork guard called _disconnect() here, which set the inherited PDO to null -- and destroying
    // that reference sent COM_QUIT down the socket the parent was still using
    db::select_raw('select 1');
    connection::purge();
    runtime::flush();

    touch($flag);

    // Hold the process open, so what is measured below is the child's teardown and not its exit
    usleep(600000);

    exit(0);
}

for ($i = 0; $i < 200 && !is_file($flag); $i++)
{
    usleep(10000);
}

$flagged = is_file($flag);

$after = (int) db::select_raw('select connection_id() as id')[0]['id'];

pcntl_waitpid($pid, $status);

echo 'before=', $before, ' after=', $after, ' flagged=', (int) $flagged, "\n";
PHP;

    $result = run_db_fork_script('survivor', $body);

    expect($result['status'])->toBe(0, $result['output']);

    preg_match('/before=(\d+) after=(\d+) flagged=(\d)/', $result['output'], $ids);

    expect($ids)->not->toBeEmpty($result['output']);

    // Without the flag the parent measured after the child was already gone, and what the case
    // asserts below would be the child's exit rather than its teardown
    expect($ids[3])->toBe('1', $result['output']);

    // Same session id: the parent never had to reconnect, because nothing closed its socket
    expect($ids[2])->toBe($ids[1]);

    db_fork_cleanup('survivor');
});

it('registers the transports under database.<name>.write and .read', function () {
    $body = <<<'PHP'
db::select_raw('select 1');

$keys = fn () => implode(',', array_filter(runtime::keys(), fn ($k) => str_starts_with($k, 'database.')));

echo 'open=[', $keys(), "]\n";

connection::purge();

echo 'purged=[', $keys(), "]\n";
PHP;

    $result = run_db_fork_script('keys', $body);

    expect($result['status'])->toBe(0, $result['output'])
        ->and($result['output'])->toContain('open=[database.fork_case.write]')
        // purge() releases them through the registry, so nothing is left holding a socket
        ->and($result['output'])->toContain('purged=[]');

    db_fork_cleanup('keys');
});
