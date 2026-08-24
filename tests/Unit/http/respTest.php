<?php

/**
 * plato\http\resp: status, headers and bodies.
 *
 * Headers are asserted through resp::pending() rather than headers_list(), which is empty under the
 * CLI SAPI -- header() there does nothing at all. What actually reaches a client is covered over a
 * real socket in tests/Feature/httpKernelTest.php.
 *
 * response() and response_error() are not exercised here: they end in exit(), which would take the
 * test runner with them. httpKernelTest drives those.
 */

use plato\http\resp;
use plato\http\reply;
use plato\plato;
use plato\security\crypt;

plato::registry(plato_test_config());

beforeEach(function () {
    resp::reset();
});

afterEach(function () {
    resp::reset();
});

/**
 * Capture what a body method echoes.
 *
 * @param callable $body
 * @return string
 */
function resp_capture(callable $body): string
{
    ob_start();
    $result = $body();
    $result = $result instanceof reply ? $result : resp::prepared();

    if ( $result instanceof reply )
    {
        resp::send($result);
    }

    return (string) ob_get_clean();
}

it('queues a status until a body sends', function () {
    resp::status(201);

    expect(resp::pending()['status'])->toBe(201)
        ->and(resp::pending()['sent'])->toBeFalse();
});

it('queues headers in the order they were set', function () {
    resp::header('X-One', '1')->header('X-Two', '2');

    expect(resp::pending()['headers'])->toBe(['X-One' => '1', 'X-Two' => '2']);
});

it('chains from a setter to a body', function () {
    $body = resp_capture(function () {
        resp::status(202)->header('X-Chained', 'yes')->json(['ok' => true]);
    });

    expect($body)->toBe('{"ok":true}')
        ->and(resp::pending()['status'])->toBe(202)
        ->and(resp::pending()['headers'])->toHaveKey('X-Chained');
});

it('strips line breaks out of a header, which would otherwise inject one', function () {
    resp::header("X-Bad\r\nX-Injected", "value\r\nSet-Cookie: a=b");

    expect(resp::pending()['headers'])->toBe(['X-BadX-Injected' => 'valueSet-Cookie: a=b']);
});

it('sets the json content type unless one was set already', function () {
    resp_capture(fn () => resp::json(['a' => 1]));

    expect(resp::pending()['headers']['Content-Type'])->toBe('application/json; charset=utf-8');

    resp::reset();
    resp::type('application/vnd.api+json');
    resp_capture(fn () => resp::json(['a' => 1]));

    expect(resp::pending()['headers']['Content-Type'])->toBe('application/vnd.api+json; charset=utf-8');
});

it('takes the status from the body call', function () {
    resp_capture(fn () => resp::json(['a' => 1], 422));

    expect(resp::pending()['status'])->toBe(422);
});

it('writes text and html with their own types', function () {
    expect(resp_capture(fn () => resp::text('plain')))->toBe('plain');
    expect(resp::pending()['headers']['Content-Type'])->toBe('text/plain; charset=utf-8');

    resp::reset();

    expect(resp_capture(fn () => resp::html('<p>hi</p>')))->toBe('<p>hi</p>');
    expect(resp::pending()['headers']['Content-Type'])->toBe('text/html; charset=utf-8');
});

it('writes a raw body with the type it was given', function () {
    expect(resp_capture(fn () => resp::raw('a,b', 'text/csv')))->toBe('a,b');
    expect(resp::pending()['headers']['Content-Type'])->toBe('text/csv');
});

it('encodes floats without the precision php prints by default', function () {
    expect(resp::encode(['n' => 0.1]))->toBe('{"n":0.1}');
});

it('leaves the host serialize_precision as it found it', function () {
    $before = ini_get('serialize_precision');

    resp::encode(['n' => 0.1]);

    expect(ini_get('serialize_precision'))->toBe($before);
});

it('does not escape unicode or slashes', function () {
    expect(resp::encode(['u' => '中文', 'p' => '/a/b']))->toBe('{"u":"中文","p":"/a/b"}');
});

it('throws when a value cannot be encoded as json', function () {
    $value = [];
    $value['self'] = &$value;

    resp::encode($value);
})->throws(JsonException::class);

it('reports that a body was written', function () {
    expect(resp::sent())->toBeFalse();

    resp_capture(fn () => resp::text('done'));

    expect(resp::sent())->toBeTrue();
});

it('returns the classic error envelope as a reply', function () {
    $body = resp_capture(fn () => resp::response_error(-7, 'failed'));

    expect(json_decode($body, true))
        ->toMatchArray(['code' => -7, 'msg' => 'failed', 'data' => []]);
});

it('keeps metadata outer middleware adds after the body is prepared', function () {
    $reply = resp::json(['ok' => true]);

    resp::status(202)->header('X-After', 'yes');
    resp_capture(fn () => $reply);

    expect(resp::pending()['status'])->toBe(202)
        ->and(resp::pending()['headers']['X-After'])->toBe('yes');
});

it('sends a redirect as a Location header and an empty body', function () {
    $body = resp_capture(fn () => resp::redirect('/order/list'));

    expect($body)->toBe('')
        ->and(resp::pending()['status'])->toBe(302)
        ->and(resp::pending()['headers']['Location'])->toBe('/order/list');
});

it('takes an absolute http url, and the status it was given', function () {
    resp_capture(fn () => resp::redirect('https://example.com/a', 301));

    expect(resp::pending()['status'])->toBe(301)
        ->and(resp::pending()['headers']['Location'])->toBe('https://example.com/a');
});

it('refuses a redirect that is neither a path nor an http url', function () {
    foreach (['javascript:alert(1)', '//evil.example/path', 'ftp://host/f', '', 'not a url'] as $url)
    {
        resp::reset();

        expect(resp::redirect($url))->toBeFalse()
            ->and(resp::pending()['headers'])->not->toHaveKey('Location');
    }
});

it('sends a file as an attachment', function () {
    $file = plato_test_data() . '/download.csv';
    file_put_contents($file, "a,b\n1,2\n");

    $body = resp_capture(fn () => resp::download($file, 'report.csv'));

    $headers = resp::pending()['headers'];

    expect($body)->toBe("a,b\n1,2\n")
        ->and($headers['Content-Type'])->toBe('application/octet-stream')
        ->and($headers['Content-Disposition'])->toBe('attachment; filename="report.csv"')
        ->and($headers['Content-Length'])->toBe((string) filesize($file));

    unlink($file);
});

it('carries a non ascii file name in both forms', function () {
    $file = plato_test_data() . '/download.csv';
    file_put_contents($file, 'x');

    resp_capture(fn () => resp::download($file, '报表.csv'));

    expect(resp::pending()['headers']['Content-Disposition'])
        ->toContain('filename="_.csv"')
        ->toContain("filename*=UTF-8''" . rawurlencode('报表.csv'));

    unlink($file);
});

it('refuses a download it cannot read, so the caller can answer 404', function () {
    expect(resp::download(plato_test_data() . '/no-such-file'))->toBeFalse()
        ->and(resp::sent())->toBeFalse();
});

it('streams what a callable writes', function () {
    // Nothing here flushes an output buffer the caller opened, which is what lets this be captured
    // at all -- see the note on resp::stream()
    $body = resp_capture(function () {
        resp::stream(function () {
            echo 'chunk one';
            echo ' chunk two';
        }, 'text/plain');
    });

    expect($body)->toBe('chunk one chunk two')
        ->and(resp::pending()['headers']['Content-Type'])->toBe('text/plain')
        ->and(resp::sent())->toBeTrue();
});

it('forgets the status, the headers and the encryption on reset', function () {
    resp::status(500)->header('X-Gone', '1');
    resp::set_encryption(str_repeat('a', 64), 'plato-envelope:response:web');

    resp::reset();

    expect(resp::pending())->toBe(['status' => 0, 'headers' => [], 'sent' => false]);

    // Encryption is off again: what proves it is that the body comes back as plain json
    expect(resp_capture(fn () => resp::json(['a' => 1])))->toBe('{"a":1}');
});

it('encrypts a json body while the request envelope is active', function () {
    resp::set_encryption(str_repeat('a', 64), 'plato-envelope:response:web');

    $body = resp_capture(fn () => resp::json(['a' => 1]));

    expect($body)->not->toBe('{"a":1}')
        ->and(resp::pending()['headers']['Content-Type'])->toBe(crypt::MEDIA_TYPE)
        ->and(crypt::decode(
            $body,
            str_repeat('a', 64),
            'plato-envelope:response:web'
        ))->toBe('{"a":1}');
});

it('leaves a text body alone even while encryption is on', function () {
    resp::set_encryption(str_repeat('a', 64), 'plato-envelope:response:web');

    // A file, a redirect or a plain text answer has no envelope to be encrypted into
    expect(resp_capture(fn () => resp::text('plain')))->toBe('plain');
});

it('answers a framework error as text, with the reason phrase of the status', function () {
    $body = resp_capture(fn () => resp::error(403));

    expect($body)->toBe('Forbidden')
        ->and(resp::pending()['status'])->toBe(403)
        ->and(resp::pending()['headers']['Content-Type'])->toStartWith('text/plain');
});

it('answers the same error as the classic envelope for a json request', function () {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? null;
    $_SERVER['HTTP_ACCEPT'] = 'application/json';

    try
    {
        $body = resp_capture(fn () => resp::error(401));

        expect(json_decode($body, true))
            ->toMatchArray(['code' => -401, 'msg' => 'Unauthorized'])
            ->and(resp::pending()['status'])->toBe(401);
    }
    finally
    {
        if ( $accept === null )
        {
            unset($_SERVER['HTTP_ACCEPT']);
        }
        else
        {
            $_SERVER['HTTP_ACCEPT'] = $accept;
        }
    }
});

it('says what the caller told it to say instead of the reason phrase', function () {
    $body = resp_capture(fn () => resp::error(500, 'Division by zero'));

    expect($body)->toBe('Division by zero')
        ->and(resp::pending()['status'])->toBe(500);
});

it('falls back to a generic phrase for a status it does not name', function () {
    expect(resp_capture(fn () => resp::error(418)))->toBe('Request Failed');
});
