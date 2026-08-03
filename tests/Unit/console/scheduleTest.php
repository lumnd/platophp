<?php
/**
 * plato\console\schedule: which tasks are due, and what running one does.
 *
 * Only the callable path runs here. A `command` task is a subprocess by design, so what it does
 * belongs to tests/Feature -- what this file pins down is the table, the due check, the overlap
 * guard and the fact that one task throwing does not stop the next.
 */

use plato\cli;
use plato\console\console;
use plato\console\schedule;
use plato\plato;

plato::registry(plato_test_config());

/**
 * Run a schedule command with a given argv and return what it wrote.
 *
 * console::line() goes through cli::write(), which writes to a stream and not to the output
 * buffer, so the stream is what has to be swapped -- ob_start() would capture nothing.
 *
 * @param string             $name    Command name
 * @param array<int, string> $options Options to append to argv
 *
 * @return string  Everything the command printed to stdout
 */
function schedule_output(string $name, array $options = []): string
{
    console::input(array_merge(['plato', $name], $options));

    $buffer = fopen('php://memory', 'w+');
    $out    = cli::stdout($buffer);
    // Failures go to stderr on purpose, so a caller redirecting one stream still sees them; both
    // land in the same buffer here because a test only cares that they were reported
    $err = cli::stderr($buffer);

    try
    {
        schedule::handle($name);
    }
    finally
    {
        cli::stdout($out);
        cli::stderr($err);
    }

    rewind($buffer);
    $written = (string) stream_get_contents($buffer);
    fclose($buffer);

    return $written;
}

/**
 * schedule:run, with the options given.
 *
 * @param array<int, string> $options
 *
 * @return string
 */
function schedule_run(array $options = []): string
{
    return schedule_output('schedule:run', $options);
}

beforeEach(function () {
    schedule::reset();
});

afterEach(function () {
    schedule::reset();
});

it('fills in the name, the expression and the overlap flag of a task', function () {
    schedule::configure(['enable' => true, 'tasks' => [
        ['command' => 'queue:work --once'],
        ['name' => 'nightly', 'expression' => '@daily', 'call' => 'time', 'overlap' => false],
    ]]);

    $tasks = schedule::tasks();

    expect($tasks[0]['name'])->toBe('queue:work --once')
        ->and($tasks[0]['expression'])->toBe('@always')
        ->and($tasks[0]['overlap'])->toBeTrue()
        ->and($tasks[1]['name'])->toBe('nightly')
        ->and($tasks[1]['expression'])->toBe('@daily')
        ->and($tasks[1]['overlap'])->toBeFalse();
});

it('drops a task that names neither a command nor a callable', function () {
    schedule::configure(['enable' => true, 'tasks' => [
        ['expression' => '@daily'],
        ['command' => 'queue:status'],
    ]]);

    // It complains on stderr while it does so, which is the point of dropping rather than throwing
    $buffer = fopen('php://memory', 'w+');
    $err    = cli::stderr($buffer);

    try
    {
        $tasks = schedule::tasks();
    }
    finally
    {
        cli::stderr($err);
    }

    rewind($buffer);
    $complaint = (string) stream_get_contents($buffer);
    fclose($buffer);

    expect($tasks)->toHaveCount(1)
        ->and($complaint)->toContain('neither a command nor a callable');
});

it('inspects invalid tasks without writing or dropping them', function () {
    schedule::configure(['enable' => true, 'tasks' => [
        'not-an-array',
        ['name' => 'broken-cron', 'expression' => 'tomorrow', 'command' => 'queue:status'],
        ['name' => 'valid', 'command' => 'queue:status'],
    ]]);

    $buffer = fopen('php://memory', 'w+');
    $err    = cli::stderr($buffer);

    try
    {
        $tasks = schedule::inspect();
    }
    finally
    {
        cli::stderr($err);
    }

    rewind($buffer);
    $written = (string) stream_get_contents($buffer);
    fclose($buffer);

    expect($tasks)->toHaveCount(3)
        ->and($tasks[0]['error'])->toContain('is not an array')
        ->and($tasks[1]['error'])->toContain('invalid expression')
        ->and($tasks[2]['error'])->toBeNull()
        ->and($written)->toBe('');
});

it('runs a callable task that is due', function () {
    $ran = 0;

    schedule::configure(['enable' => true, 'tasks' => [
        ['name' => 'due', 'expression' => '@always', 'call' => function () use (&$ran) {
            $ran++;
        }],
    ]]);

    schedule_run();

    expect($ran)->toBe(1);
});

it('leaves a task that is not due alone', function () {
    $ran = 0;

    schedule::configure(['enable' => true, 'tasks' => [
        // 03:30, evaluated at 09:00
        ['name' => 'nightly', 'expression' => '30 3 * * *', 'call' => function () use (&$ran) {
            $ran++;
        }],
    ]]);

    schedule_run(['--at=' . mktime(9, 0, 0, 7, 30, 2026)]);

    expect($ran)->toBe(0);
});

it('runs a task that is not due when it is forced', function () {
    $ran = 0;

    schedule::configure(['enable' => true, 'tasks' => [
        ['name' => 'nightly', 'expression' => '30 3 * * *', 'call' => function () use (&$ran) {
            $ran++;
        }],
    ]]);

    schedule_run(['--at=' . mktime(9, 0, 0, 7, 30, 2026), '--force']);

    expect($ran)->toBe(1);
});

it('considers only the task that was named', function () {
    $ran = [];

    schedule::configure(['enable' => true, 'tasks' => [
        ['name' => 'a', 'call' => function () use (&$ran) {
            $ran[] = 'a';
        }],
        ['name' => 'b', 'call' => function () use (&$ran) {
            $ran[] = 'b';
        }],
    ]]);

    schedule_run(['--task=b']);

    expect($ran)->toBe(['b']);
});

it('filters automatic runs and reports the skip lifecycle', function () {
    $ran    = [];
    $events = [];

    schedule::configure([
        'enable' => true,
        'tasks'  => [
            ['name' => 'paused', 'call' => function () use (&$ran) {
                $ran[] = 'paused';
            }],
            ['name' => 'ready', 'call' => function () use (&$ran) {
                $ran[] = 'ready';
            }],
        ],
        'should_run' => function (array $task): bool {
            return $task['name'] !== 'paused';
        },
        'before' => function (array $task) use (&$events): void {
            $events[] = 'before:' . $task['name'];
        },
        'after' => function (array $task, array $result) use (&$events): void {
            $events[] = sprintf('after:%s:%s', $task['name'], $result['ok'] ? 'ok' : 'failed');
        },
        'skipped' => function (array $task, string $reason) use (&$events): void {
            $events[] = sprintf('skipped:%s:%s', $task['name'], $reason);
        },
    ]);

    schedule_run();

    expect($ran)->toBe(['ready'])
        ->and($events)->toBe([
            'skipped:paused:filtered',
            'before:ready',
            'after:ready:ok',
        ]);
});

it('keeps the run filter under --force, which only ignores the expression', function () {
    $ran = 0;

    schedule::configure([
        'enable'     => true,
        'tasks'      => [['name' => 'paused', 'expression' => '@yearly', 'call' => function () use (&$ran) {
            $ran++;
        }]],
        'should_run' => static fn (): bool => false,
    ]);

    expect(schedule_run(['--force']))->toContain('0 tasks started')
        ->and($ran)->toBe(0);
});

it('lets a manual execution bypass the automatic run filter', function () {
    $ran = 0;

    schedule::configure([
        'enable'     => true,
        'tasks'      => [['name' => 'manual', 'call' => function () use (&$ran) {
            $ran++;
        }]],
        'should_run' => static fn (): bool => false,
    ]);

    schedule_output('schedule:exec', ['--task=manual']);

    expect($ran)->toBe(1);
});

it('does not let observer failures replace the task result', function () {
    $ran = 0;

    schedule::configure([
        'enable' => true,
        'tasks'  => [['name' => 'observed', 'call' => function () use (&$ran) {
            $ran++;
        }]],
        'before' => static function (): void {
            throw new RuntimeException('observer unavailable');
        },
    ]);

    $output = schedule_run();

    expect($ran)->toBe(1)
        ->and($output)->toContain('before hook failed for observed')
        ->and($output)->toContain('1 task started');
});

it('runs nothing at all while the scheduler is switched off', function () {
    $ran = 0;

    schedule::configure(['enable' => false, 'tasks' => [
        ['name' => 'due', 'call' => function () use (&$ran) {
            $ran++;
        }],
    ]]);

    $output = schedule_run();

    expect($ran)->toBe(0)
        ->and($output)->toContain('switched off');
});

it('keeps going after a task throws, and says which one it was', function () {
    $ran = 0;

    schedule::configure(['enable' => true, 'tasks' => [
        ['name' => 'broken', 'call' => function () {
            throw new RuntimeException('no');
        }],
        ['name' => 'after', 'call' => function () use (&$ran) {
            $ran++;
        }],
    ]]);

    $output = schedule_run();

    expect($ran)->toBe(1)
        ->and($output)->toContain('broken failed')
        // A task that threw still ran, so it counts as started
        ->and($output)->toContain('2 tasks started');
});

it('counts what it started', function () {
    schedule::configure(['enable' => true, 'tasks' => [
        ['name' => 'a', 'call' => 'time'],
        ['name' => 'b', 'call' => 'time'],
    ]]);

    expect(schedule_run())->toContain('2 tasks started');
});

it('releases the overlap lock of a callable task when it is done', function () {
    $ran = 0;

    schedule::configure(['enable' => true, 'tasks' => [
        ['name' => 'guarded', 'overlap' => false, 'call' => function () use (&$ran) {
            $ran++;
        }],
    ]]);

    // Two runs one after the other: the first has to have let go of the lock by the time the
    // second asks for it, or a task that says overlap => false would run exactly once ever
    schedule_run();
    schedule_run();

    expect($ran)->toBe(2);
});

it('lists what is scheduled with the next time each entry runs', function () {
    schedule::configure(['enable' => true, 'tasks' => [
        ['name' => 'nightly', 'expression' => '30 3 * * *', 'command' => 'report:nightly'],
    ]]);

    $output = schedule_output('schedule:list', ['--at=' . mktime(9, 0, 0, 7, 30, 2026)]);

    expect($output)->toContain('nightly')
        ->and($output)->toContain('30 3 * * *')
        ->and($output)->toContain('2026-07-31 03:30');
});
