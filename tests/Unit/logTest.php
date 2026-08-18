<?php
/**
 * log: levelled logging into data/log/<level>.log.
 *
 * Under CLI every write() flushes straight away, so each test can read the file back.
 */

use plato\config;
use plato\log;

afterEach(function () {
    config::instance('log')->set('log_threshold', log::ALL);
    config::instance('log')->set('log_output', "%datetime% [%level_name%] --> %message%%context%\n");
    config::instance('log')->set('log_type', 'file');
    log::forget_context(['tag', 'order', 'agent', 'uid']);
});

it('writes an entry into the file of its level', function () {
    $written = log_test_capture('info', function () {
        expect(log::info('platophptest info line'))->toBeTrue();
    });

    expect($written)->toContain('[INFO] --> platophptest info line');
    // The configured format leads with the date and closes with a newline
    expect($written)->toEndWith("\n");
    expect($written)->toStartWith(date('Y'));
});

it('keeps the levels in separate files', function () {
    $written = log_test_capture('error', function () {
        log::warning('platophptest warning line');
        log::error('platophptest error line');
    });

    expect($written)->toContain('platophptest error line');
    expect($written)->not->toContain('platophptest warning line');
});

it('json encodes an array message', function () {
    $written = log_test_capture('debug', function () {
        log::debug(['marker' => 'platophptest', 'zh' => '中文']);
    });

    // JSON_UNESCAPED_UNICODE: a log file is read by humans, not parsed back
    expect($written)->toContain('{"marker":"platophptest","zh":"中文"}');
});

it('writes nothing when the threshold is NONE', function () {
    config::instance('log')->set('log_threshold', log::NONE);

    $written = log_test_capture('info', function () {
        expect(log::info('platophptest suppressed'))->toBeFalse();
    });

    expect($written)->toBe('');
});

it('reads a string context as a tag', function () {
    $written = log_test_capture('notice', function () {
        log::notice('platophptest message', 'platophptest context');
    });

    expect($written)->toContain('platophptest message')
        ->and($written)->toContain('"tag":"platophptest context"');
});

it('substitutes context placeholders into the message', function () {
    $written = log_test_capture('info', function () {
        log::info('platophptest order {order} for user {uid}', ['order' => 91, 'uid' => 7]);
    });

    // Both keys were consumed by the message, so neither is left in %context%
    expect($written)->toContain('platophptest order 91 for user 7')
        ->and($written)->not->toContain('"order"');
});

it('renders the context the message did not use', function () {
    $written = log_test_capture('info', function () {
        log::info('platophptest {order} taken', ['order' => 91, 'agent' => 'curl']);
    });

    expect($written)->toContain('platophptest 91 taken {')
        ->and($written)->toContain('"agent":"curl"');
});

it('leaves a placeholder alone when its value cannot be a string', function () {
    $written = log_test_capture('debug', function () {
        log::debug('platophptest payload {order}', ['order' => ['a' => 1]]);
    });

    expect($written)->toContain('platophptest payload {order}')
        ->and($written)->toContain('"order":{"a":1}');
});

it('renders an exception in the context rather than encoding it', function () {
    $written = log_test_capture('error', function () {
        log::error('platophptest it broke', ['exception' => new RuntimeException('the reason')]);
    });

    expect($written)->toContain('RuntimeException: the reason')
        ->and($written)->toContain(basename(__FILE__));
});

it('leaves no separator on a line with no leftover context', function () {
    log::forget_context();

    $written = log_test_capture('info', function () {
        log::info('platophptest bare line');
    });

    log::context(['rid' => log::new_rid()]);

    expect($written)->toEndWith("platophptest bare line\n");
});

it('drops the whole shared context, the request id with it', function () {
    $before = log::shared_context();

    try
    {
        log::context(['uid' => 7]);

        log::reset();

        // Not "everything but rid": a caller clearing the context owes a fresh one, which is what
        // plato::reset_request() does through restamp()
        expect(log::shared_context())->toBe([]);
    }
    finally
    {
        log::context($before);
    }
});

it('seeds a request id that every entry carries', function () {
    $rid = log::shared_context()['rid'] ?? '';

    expect($rid)->toMatch('/^[0-9a-f]{16}$/');

    $written = log_test_capture('info', function () {
        log::info('platophptest rid line');
    });

    expect($written)->toContain('"rid":"' . $rid . '"');
});

it('puts shared context on every entry and lets a call override it', function () {
    log::context(['uid' => 7]);

    $written = log_test_capture('info', function () {
        log::info('platophptest shared');
        log::info('platophptest overridden', ['uid' => 9]);
    });

    expect($written)->toContain('"uid":7')
        ->and($written)->toContain('"uid":9');
});

it('writes one json object per line under log_output json', function () {
    config::instance('log')->set('log_output', 'json');

    $written = log_test_capture('warning', function () {
        log::warning('platophptest json line', ['order' => 91]);
    });

    $lines = array_values(array_filter(explode("\n", $written)));

    expect($lines)->toHaveCount(1);

    $record = json_decode($lines[0], true);

    expect($record)->toBeArray()
        ->and($record['level'])->toBe('WARNING')
        ->and($record['msg'])->toBe('platophptest json line')
        // Leftover context is a field of the record, not json nested inside the message
        ->and($record['order'])->toBe(91)
        ->and($record['rid'])->toBe(log::shared_context()['rid'])
        ->and($record['ts'])->toMatch('/^\d{4}-\d{2}-\d{2}T/');
});

it('does not let a context key hide the message in json output', function () {
    config::instance('log')->set('log_output', 'json');

    $written = log_test_capture('warning', function () {
        log::warning('platophptest real message', ['msg' => 'impostor', 'level' => 'DEBUG']);
    });

    $record = json_decode(trim($written), true);

    expect($record['msg'])->toBe('platophptest real message')
        ->and($record['level'])->toBe('WARNING');
});

it('sends every level to one stream under log_type stderr', function () {
    config::instance('log')->set('log_type', 'stderr');

    $file = log_test_capture('error', function () {
        log::error('platophptest to the stream');
    });

    // Nothing reached error.log; the entry went to php://stderr instead
    expect($file)->toBe('');
});
