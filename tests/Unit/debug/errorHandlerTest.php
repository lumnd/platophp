<?php

/**
 * error_handler: errors remain reportable even when their source line cannot be read, and the
 * application gets to say what a failure no middleware could catch looks like.
 */

use plato\debug\error_handler;
use plato\exception\controller_exception;
use plato\exception\route_exception;
use plato\http\reply;
use plato\http\resp;
use plato\plato;

/**
 * Run exception_reply() and read back what it appended to one level file.
 *
 * @param string     $level Level name, which is also the file name
 * @param \Throwable $e
 * @return string
 */
function error_handler_test_log(string $level, \Throwable $e): string
{
    return log_test_capture($level, static function () use ($e) {
        error_handler::exception_reply($e);
    });
}

afterEach(function () {
    unset(plato::$config['error_handle']);
    resp::reset();

    // exception_reply() hides the debug panel for the rest of the request, and every case here
    // leaves that set for whatever runs next in this process
    error_handler::reset();
});

it('formats an error whose source line is outside the file', function () {
    $message = error_handler::format_errstr(
        E_WARNING,
        'probe',
        __FILE__,
        PHP_INT_MAX,
        []
    );

    expect($message)->toContain('probe');
});

it('leaves errors suppressed by the caller alone', function () {
    $before = error_reporting();

    try
    {
        error_reporting(0);

        expect(error_handler::error_handler(E_WARNING, 'hidden', __FILE__, __LINE__))->toBeFalse();
    }
    finally
    {
        error_reporting($before);
    }
});

it('lets the application render a request that could not be routed', function () {
    $seen = [];

    plato::$config['error_handle'] = function (\Throwable $e, int $status) use (&$seen) {
        $seen = [get_class($e), $status];

        return resp::status($status)->type('text/html')->html('<h1>404</h1>');
    };

    $reply = error_handler::exception_reply(new controller_exception(['ctl_nosuch'], 2001));

    expect($seen)->toBe([controller_exception::class, 404])
        ->and($reply->status())->toBe(404)
        ->and($reply->body())->toBe('<h1>404</h1>');
});

it('keeps the default response when the callback declines', function () {
    plato::$config['error_handle'] = static fn (): ?reply => null;

    $reply = error_handler::exception_reply(new route_exception(['index:nosuch'], 2009));

    expect($reply->status())->toBe(404)
        ->and($reply->body())->toContain('is not routable');
});

it('keeps the default response when the callback returns something that is not a reply', function () {
    // A callback that rendered a string and forgot to wrap it, say. Whatever it is, it cannot be
    // sent, and guessing at it is worse than answering with the response this class already has
    plato::$config['error_handle'] = static fn (): string => '<h1>404</h1>';

    $reply = error_handler::exception_reply(new route_exception(['index:nosuch'], 2009));

    expect($reply->status())->toBe(404)
        ->and($reply->body())->toContain('is not routable');
});

it('answers anyway when the callback itself throws', function () {
    plato::$config['error_handle'] = static function (): void {
        throw new \RuntimeException('the error page is broken too');
    };

    $written = error_handler_test_log('error', new route_exception(['index:nosuch'], 2009));

    expect($written)->toContain('error_handle failed while rendering 404')
        ->and($written)->toContain('the error page is broken too');
});

it('does not let a half built response from a failed callback answer for it', function () {
    plato::$config['error_handle'] = static function (): void {
        resp::header('X-Error-Page', 'half-built');

        throw new \RuntimeException('threw after queueing a header');
    };

    $reply = error_handler::exception_reply(new route_exception(['index:nosuch'], 2009));

    expect($reply->headers())->not->toHaveKey('X-Error-Page')
        ->and(resp::pending()['headers'])->not->toHaveKey('X-Error-Page');
});

it('logs a client error as one warning line instead of a stack trace', function () {
    $written = error_handler_test_log('warning', new controller_exception(['ctl_nosuch'], 2001));

    expect($written)->toContain('404 ')
        ->and($written)->toContain('Controller[ctl_nosuch] is not exists.')
        // One line: no source excerpt, no backtrace
        ->and($written)->not->toContain('Exception Trace')
        ->and(substr_count(trim($written), "\n"))->toBe(0);
});

it('keeps the full trace for a server error', function () {
    $written = error_handler_test_log('error', new \RuntimeException('something broke'));

    expect($written)->toContain('Exception Trace')
        ->and($written)->toContain('something broke');
});
