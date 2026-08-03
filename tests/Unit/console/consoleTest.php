<?php
/**
 * plato\console\console: the registry and the help, without running a command.
 *
 * run() itself is covered end to end in tests/Feature/migrationCliTest.php, which drives bin/plato
 * as a subprocess -- the only way to exercise the bootstrap it does.
 */

use plato\console\command;
use plato\console\console;
use plato\console\make;
use plato\console\migrate;
use plato\console\queue;

class fake_console_command implements command
{
    /** @var array<int, string> */
    public static $handled = [];

    public static function names(): array
    {
        return [
            'fake'          => 'A command that only records that it ran',
            'fake:sub ARG'  => 'The same class, under a second name',
        ];
    }

    public static function usage(string $name): string
    {
        return '  --flag                 Nothing at all';
    }

    public static function requires(): array
    {
        return ['migration_path'];
    }

    public static function handle(string $name): int
    {
        self::$handled[] = $name;

        return console::OK;
    }
}

class not_a_console_command
{
}

it('registers every name a command answers to', function () {
    console::register(fake_console_command::class);

    expect(console::commands())->toHaveKey('fake')
        ->and(console::commands())->toHaveKey('fake:sub ARG');
});

it('lists the built-in commands', function () {
    console::register(migrate::class);
    console::register(make::class);
    console::register(queue::class);

    $commands = console::commands();

    expect($commands)->toHaveKey('migrate')
        ->and($commands)->toHaveKey('migrate:status')
        ->and($commands)->toHaveKey('migrate:rollback')
        ->and($commands)->toHaveKey('make:migration NAME')
        ->and($commands)->toHaveKey('queue:work');
});

it('refuses a class that is not a command', function () {
    $stderr = cli_capture_stderr();

    console::register(not_a_console_command::class);

    expect(cli_release_stderr($stderr))->toContain('is not a plato\console\command');
    expect(console::commands())->not->toHaveKey('not_a_console_command');
});

it('names every path key it can resolve', function () {
    foreach (['app_path', 'data_path', 'env_path', 'migration_path'] as $key)
    {
        // A path is either resolved or empty, never missing: data_path is deliberately empty when
        // it was not given, because registry() derives it
        expect(console::path($key))->toBeString();
    }
});

it('answers a path key spelled with dashes', function () {
    expect(console::path('migration-path'))->toBe(console::path('migration_path'));
});

it('declares what it needs before the framework boots', function () {
    expect(migrate::requires())->toBe(['migration_path'])
        ->and(make::requires())->toBe([])
        ->and(queue::requires())->toBe([]);
});

it('has an option block for every name it registers', function () {
    foreach ([migrate::class, make::class, queue::class] as $class)
    {
        foreach (array_keys($class::names()) as $name)
        {
            $usage = $class::usage((string) strtok((string) $name, ' '));

            expect($usage)->toBeString();
        }
    }
});

/**
 * Point cli:: STDERR at a temporary stream and return the handle to read back.
 *
 * @return resource
 */
function cli_capture_stderr()
{
    $stream = fopen('php://temp', 'w+');

    plato\cli::stderr($stream);

    return $stream;
}

/**
 * Read what was written to the captured stream and put STDERR back.
 *
 * @param resource $stream
 * @return string
 */
function cli_release_stderr($stream): string
{
    rewind($stream);
    $output = (string) stream_get_contents($stream);

    plato\cli::stderr(STDERR);
    fclose($stream);

    return $output;
}
