<?php
/**
 * What a command line process does when nothing catches an exception.
 *
 * Every case is a real subprocess: an exit status and what a script wrote to STDERR are not
 * observable from inside the test runner, and they are the whole subject here. A script that died
 * half way through must not look like one that finished -- the caller of `php script.php` reads
 * `$?`, and a cron mail or a CI step reads STDERR.
 *
 * The three boundaries are deliberately different, and each is asserted separately:
 *
 *   plain script            the global handler reports and exits non zero
 *   bin/plato               the console catches, prints one line and returns its own exit code
 *   caller-side try/catch   nothing is printed and nothing exits; the exception is the caller's
 */

/**
 * Runtime directory of one case.
 *
 * @param string $case
 * @return string
 */
function cli_failure_dir(string $case): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'platophp-test-cli-' . getmypid() . '-' . $case;
}

/**
 * Boot the framework in a subprocess, run $body, and report what came back.
 *
 * STDOUT and STDERR are captured apart, because "it was reported" means STDERR here.
 *
 * @param string $case Case name, used for the runtime directory and the script file
 * @param string $body PHP source, appended to a script that has already called plato::registry()
 *
 * @return array{status: int, stdout: string, stderr: string}
 */
function run_cli_script(string $case, string $body): array
{
    $root = dirname(__DIR__, 2);
    $dir  = cli_failure_dir($case);

    is_dir($dir) || mkdir($dir, 0777, true);

    $script = "<?php\n"
        . 'require ' . var_export($root . '/vendor/autoload.php', true) . ";\n"
        . "use plato\\plato;\n"
        . 'plato::registry(['
        . "'app_path' => " . var_export($root . '/tests/Fixtures/app', true) . ','
        . "'data_path' => " . var_export($dir, true) . ','
        . "'env_path' => " . var_export($root . '/tests/Fixtures/app/.env.testing', true) . ','
        . "]);\n"
        . $body;

    $file = $dir . DIRECTORY_SEPARATOR . 'case.php';
    file_put_contents($file, $script);

    return run_cli_command(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file));
}

/**
 * Run a command, keeping its two output streams apart.
 *
 * @param string $command
 *
 * @return array{status: int, stdout: string, stderr: string}
 */
function run_cli_command(string $command): array
{
    $pipes   = [];
    $process = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__, 2)
    );

    if (!is_resource($process))
    {
        throw new RuntimeException('could not start ' . $command);
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

/**
 * @param string $case
 * @return void
 */
function cli_failure_cleanup(string $case): void
{
    $dir = cli_failure_dir($case);

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

it('reports an uncaught exception on stderr and exits non zero', function () {
    $result = run_cli_script('uncaught', "throw new RuntimeException('the job blew up');\n");

    expect($result['status'])->not->toBe(0)
        ->and($result['stderr'])->toContain('the job blew up')
        ->and($result['stderr'])->toContain('RuntimeException')
        // Nothing on stdout: a script piped into something else must not have its failure mixed
        // into the data the caller is reading
        ->and($result['stdout'])->toBe('');

    cli_failure_cleanup('uncaught');
});

it('fails a script that already printed something, without eating what it printed', function () {
    // The case that made the old behaviour dangerous: a long running script writes its progress,
    // dies half way through, and a caller reading only stdout and the exit code is told it worked
    $body = "echo 'step 1 done', PHP_EOL;\nthrow new RuntimeException('step 2 blew up');\n";

    $result = run_cli_script('midway', $body);

    expect($result['status'])->not->toBe(0)
        ->and($result['stdout'])->toContain('step 1 done')
        ->and($result['stderr'])->toContain('step 2 blew up');

    cli_failure_cleanup('midway');
});

it('does not ask the application for an error page it has nowhere to send', function () {
    // error_handle renders a response for a client. A command line process has none, so a job that
    // dies must not go boot a template engine and query whatever an error layout reads on its way
    // out -- and whatever the callback prints would land in the data a caller is piping
    $body = "plato::\$config['error_handle'] = static function () {\n"
        . "    echo 'THE ERROR PAGE RAN', PHP_EOL;\n\n"
        . "    return null;\n};\n"
        . "throw new RuntimeException('the job blew up');\n";

    $result = run_cli_script('nopage', $body);

    expect($result['status'])->toBe(255)
        ->and($result['stdout'])->toBe('')
        ->and($result['stderr'])->toContain('the job blew up');

    cli_failure_cleanup('nopage');
});

it('lets an unrouteable cli request answer false instead of dying', function () {
    // plato::run() reports a cli request it could not dispatch by returning false; that is not a
    // failure of the process, and the exit status has to keep saying so
    $result = run_cli_script(
        'unrouted',
        "var_export(plato::run(['ct' => 'nothing', 'ac' => 'there']));\n"
    );

    expect($result['status'])->toBe(0)
        ->and($result['stdout'])->toContain('false');

    cli_failure_cleanup('unrouted');
});

it('leaves an exception the caller catches alone', function () {
    $body = "try\n{\n    throw new RuntimeException('handled');\n}\ncatch (Throwable \$e)\n{\n"
        . "    echo 'caught: ', \$e->getMessage(), PHP_EOL;\n}\n";

    $result = run_cli_script('caught', $body);

    // The handler is installed but never reached, so the script ends the way it normally would
    expect($result['status'])->toBe(0)
        ->and($result['stdout'])->toContain('caught: handled')
        ->and($result['stderr'])->toBe('');

    cli_failure_cleanup('caught');
});

it('answers an unknown console command with one line on stderr and a non zero status', function () {
    $result = run_cli_command(escapeshellarg(PHP_BINARY) . ' bin/plato no-such-command');

    expect($result['status'])->toBe(1)
        ->and($result['stderr'])->toContain('Unknown command: no-such-command');
});

it('turns a command that throws into an exit code rather than a stack trace', function () {
    // The console catches everything handle() throws, so a broken command is a failed command and
    // not a process that died: one line, and the exit code its caller branches on
    $result = run_cli_command(
        escapeshellarg(PHP_BINARY) . ' bin/plato migrate --app-path=/no/such/directory'
    );

    expect($result['status'])->toBe(1)
        ->and($result['stderr'])->not->toBe('')
        ->and($result['stderr'])->not->toContain('#0 ');
});
