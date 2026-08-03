<?php
/**
 * plato\psr\logger: the PSR-3 adapter over plato\log.
 *
 * Assertions read the log file, because that is where plato\log puts an entry under CLI -- write()
 * flushes on every call there rather than buffering to the end of a request.
 */

use plato\config;
use plato\log;
use plato\plato;
use plato\psr\logger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

plato::registry(plato_test_config());

/**
 * Read a level's log file and remove it, so the next case starts empty.
 *
 * @param string $level
 * @return string
 */
function psr_log_take(string $level): string
{
    $file = plato::log_path($level . '.log');

    if (!is_file($file))
    {
        return '';
    }

    $contents = (string) file_get_contents($file);
    unlink($file);

    return $contents;
}

beforeEach(function () {
    foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'] as $level)
    {
        psr_log_take($level);
    }
});

it('is a psr-3 logger', function () {
    expect(new logger())->toBeInstanceOf(LoggerInterface::class);
});

it('writes each psr level to the plato level of the same name', function () {
    $logger = new logger();

    $logger->emergency('an emergency');
    $logger->alert('an alert');
    $logger->critical('a critical');
    $logger->error('an error');
    $logger->warning('a warning');
    $logger->notice('a notice');
    $logger->info('an info');
    $logger->debug('a debug');

    expect(psr_log_take('emergency'))->toContain('an emergency')
        ->and(psr_log_take('alert'))->toContain('an alert')
        ->and(psr_log_take('critical'))->toContain('a critical')
        ->and(psr_log_take('error'))->toContain('an error')
        ->and(psr_log_take('warning'))->toContain('a warning')
        ->and(psr_log_take('notice'))->toContain('a notice')
        ->and(psr_log_take('info'))->toContain('an info')
        ->and(psr_log_take('debug'))->toContain('a debug');
});

it('writes the level name into the line', function () {
    (new logger())->error('shape of the line');

    expect(psr_log_take('error'))->toContain('[ERROR]');
});

it('substitutes context placeholders into the message', function () {
    (new logger())->info('User {id} signed in from {ip}', ['id' => 7, 'ip' => '10.0.0.1']);

    expect(psr_log_take('info'))->toContain('User 7 signed in from 10.0.0.1');
});

it('keeps context the message did not use', function () {
    (new logger())->info('User {id} signed in', ['id' => 7, 'agent' => 'curl']);

    $line = psr_log_take('info');

    expect($line)->toContain('User 7 signed in')
        ->and($line)->toContain('agent')
        ->and($line)->toContain('curl');
});

it('leaves a placeholder alone when its value cannot be a string', function () {
    (new logger())->info('Payload {data}', ['data' => ['a' => 1]]);

    $line = psr_log_take('info');

    expect($line)->toContain('Payload {data}')
        ->and($line)->toContain('"data":{"a":1}');
});

it('renders an exception in the context rather than encoding it', function () {
    (new logger())->error('It broke', ['exception' => new RuntimeException('the reason')]);

    $line = psr_log_take('error');

    expect($line)->toContain('It broke')
        ->and($line)->toContain('RuntimeException: the reason')
        ->and($line)->toContain(basename(__FILE__));
});

it('accepts a Stringable message', function () {
    $message = new class {
        public function __toString(): string
        {
            return 'from an object';
        }
    };

    (new logger())->notice($message);

    expect(psr_log_take('notice'))->toContain('from an object');
});

it('writes at a level given by name', function () {
    (new logger())->log(LogLevel::WARNING, 'by name');

    expect(psr_log_take('warning'))->toContain('by name');
});

it('refuses a level psr-3 does not define', function () {
    expect(fn () => (new logger())->log('verbose', 'nope'))
        ->toThrow(InvalidArgumentException::class);
});

it('does not write when the threshold excludes the level', function () {
    $original = config::instance('log')->get('log_threshold');

    config::instance('log')->set('log_threshold', log::ERROR);

    (new logger())->debug('below the threshold');

    expect(psr_log_take('debug'))->toBe('');

    config::instance('log')->set('log_threshold', $original);
});
