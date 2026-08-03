<?php

/**
 * envelope: the encrypted request envelope that carries the route and the payload.
 */

use plato\exception\route_exception;
use plato\http\envelope;
use plato\http\req;
use plato\http\resp;
use plato\http\route;
use plato\plato;
use plato\security\crypt;

const ENVELOPE_KEY = 'd0fea132b98363458135bdf0990879ecd0fea132b98363458135bdf0990879ec';

/**
 * Build an encrypted envelope body.
 *
 * @param array<string, mixed> $envelope
 * @return string
 */
function envelope_body(array $envelope, string $key = ENVELOPE_KEY, string $client = 'web'): string
{
    return crypt::encode(json_encode($envelope), $key, 'plato-envelope:request:' . $client);
}

/**
 * Put a request in place: raw body plus the client selector header.
 *
 * @param array<string, mixed> $envelope
 * @return void
 */
function envelope_request(array $envelope, ?string $client = 'web', ?string $key = null): void
{
    $_SERVER['CONTENT_TYPE'] = crypt::MEDIA_TYPE;
    req::set_raw(envelope_body($envelope, $key ?? ENVELOPE_KEY, $client ?? 'web'));

    if ($client === null)
    {
        unset($_SERVER['HTTP_X_CLIENT']);
    }
    else
    {
        $_SERVER['HTTP_X_CLIENT'] = $client;
    }
}

beforeEach(function () {
    route::reset(true);
    envelope::reset(true);
    req::reset_input();
    resp::reset();
    unset($_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_X_CLIENT']);

    // Replay protection needs a nonce store; the tests that exercise ts / nonce validation turn
    // it on themselves and only assert on the checks that happen before the store is touched
    envelope::configure([
        'clients'       => ['web' => ENVELOPE_KEY, 'ios' => str_repeat('a', 64)],
        'replay_window' => 0,
    ]);
});

afterAll(function () {
    req::reset_input();
    unset($_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_X_CLIENT']);

    // capture() turns response encryption on, and the suite runs in one process: without this the
    // next file to write a json body gets ciphertext back for no reason it can see
    resp::reset();
});

/*
|--------------------------------------------------------------------------
| Decoding
|--------------------------------------------------------------------------
*/

it('decodes an envelope and reports the route it names', function () {
    envelope_request(['ct' => 'article', 'ac' => 'edit', 'method' => 'PATCH', 'data' => ['id' => 10]]);

    $result = envelope::resolve();

    expect($result)->toBeArray();
    expect($result['ct'])->toBe('article');
    expect($result['ac'])->toBe('edit');
    expect($result['method'])->toBe('PATCH');
    expect(envelope::is_active())->toBeTrue();
    expect(envelope::client())->toBe('web');
});

it('defaults the envelope method to POST', function () {
    envelope_request(['ct' => 'article', 'ac' => 'edit']);

    expect(envelope::resolve()['method'])->toBe('POST');
});

it('replaces the request parameters with the payload', function () {
    req::$posts = req::$forms = ['leftover' => 'from the plaintext body'];
    req::$gets  = ['q' => 'from the query string'];

    envelope_request(['ct' => 'article', 'ac' => 'edit', 'data' => ['id' => 10]]);
    envelope::resolve();

    expect(req::$posts)->toBe(['id' => 10]);
    expect(req::$forms)->toBe(['id' => 10]);
    expect(req::$gets)->toBe([]);
});

it('keeps the envelope metadata out of the parameters', function () {
    envelope_request([
        'ct'    => 'article',
        'ac'    => 'edit',
        'csrf'  => 'token',
        'ts'    => 1,
        'nonce' => 'abcdefgh',
        'data'  => ['id' => 10],
    ]);

    envelope::resolve();

    expect(req::$posts)->toBe(['id' => 10]);
});

it('treats a missing payload as no parameters', function () {
    envelope_request(['ct' => 'article', 'ac' => 'edit']);
    envelope::resolve();

    expect(req::$posts)->toBe([]);
});

it('exposes the csrf token the envelope carries', function () {
    envelope_request(['ct' => 'article', 'ac' => 'edit', 'csrf' => 'abc123']);
    envelope::resolve();

    expect(envelope::csrf())->toBe('abc123');
});

/*
|--------------------------------------------------------------------------
| Refusals -- every one of these has to be indistinguishable from outside
|--------------------------------------------------------------------------
*/

it('is not an envelope when the body is empty', function () {
    $_SERVER['CONTENT_TYPE'] = crypt::MEDIA_TYPE;
    req::set_raw('');
    $_SERVER['HTTP_X_CLIENT'] = 'web';

    expect(envelope::resolve())->toBeNull();
});

it('is not an envelope under another media type', function () {
    envelope_request(['ct' => 'article', 'ac' => 'edit']);
    $_SERVER['CONTENT_TYPE'] = 'application/octet-stream';
    req::reset_input();
    req::set_raw(envelope_body(['ct' => 'article', 'ac' => 'edit']));

    expect(envelope::resolve())->toBeNull();
});

it('is not an envelope without a client selector', function () {
    envelope_request(['ct' => 'article', 'ac' => 'edit'], null);

    expect(envelope::resolve())->toBeNull();
});

it('refuses an unconfigured client rather than falling back to a key', function () {
    envelope_request(['ct' => 'article', 'ac' => 'edit'], 'windows');

    expect(envelope::resolve())->toBeNull();
});

it('refuses a malformed client selector', function () {
    envelope_request(['ct' => 'article', 'ac' => 'edit'], '../../etc/passwd');

    expect(envelope::resolve())->toBeNull();
});

it('never tries another client key when one fails', function () {
    // Encrypted with the web key but announced as ios. Trial decryption would let an attacker
    // learn which platform a captured envelope belongs to from the failure alone.
    envelope_request(['ct' => 'article', 'ac' => 'edit'], 'ios', ENVELOPE_KEY);

    expect(envelope::resolve())->toBeNull();
    expect(envelope::is_active())->toBeFalse();
});

it('refuses a body that does not decrypt', function () {
    $_SERVER['CONTENT_TYPE'] = crypt::MEDIA_TYPE;
    req::set_raw('this is not ciphertext at all');
    $_SERVER['HTTP_X_CLIENT'] = 'web';

    expect(envelope::resolve())->toBeNull();
});

it('refuses ciphertext that is not a json object', function () {
    $_SERVER['CONTENT_TYPE'] = crypt::MEDIA_TYPE;
    req::set_raw(crypt::encode('"just a string"', ENVELOPE_KEY, 'plato-envelope:request:web'));
    $_SERVER['HTTP_X_CLIENT'] = 'web';

    expect(envelope::resolve())->toBeNull();
});

it('leaves the response unencrypted when the envelope is refused', function () {
    envelope_request(['ct' => 'article', 'ac' => 'edit'], 'windows');
    envelope::resolve();

    // A refusal must not switch the response into a format the client cannot read
    expect(envelope::is_active())->toBeFalse();
});

it('leaves the request parameters alone when the envelope is refused', function () {
    req::$posts = req::$forms = ['a' => 'plaintext'];
    envelope_request(['ct' => 'article', 'ac' => 'edit', 'data' => ['id' => 10]], 'windows');

    envelope::resolve();

    expect(req::$posts)->toBe(['a' => 'plaintext']);
});

/*
|--------------------------------------------------------------------------
| Replay protection
|--------------------------------------------------------------------------
*/

it('refuses an envelope with no timestamp when replay protection is on', function () {
    envelope::configure(['replay_window' => 300]);
    envelope_request(['ct' => 'article', 'ac' => 'edit', 'nonce' => 'abcdefgh']);

    expect(envelope::resolve())->toBeNull();
});

it('refuses an envelope with no nonce when replay protection is on', function () {
    envelope::configure(['replay_window' => 300]);
    envelope_request(['ct' => 'article', 'ac' => 'edit', 'ts' => plato::timestamp()]);

    expect(envelope::resolve())->toBeNull();
});

it('refuses a malformed nonce', function () {
    envelope::configure(['replay_window' => 300]);
    envelope_request([
        'ct' => 'article', 'ac' => 'edit', 'ts' => plato::timestamp(), 'nonce' => 'a b/c',
    ]);

    expect(envelope::resolve())->toBeNull();
});

it('refuses a timestamp outside the replay window', function () {
    envelope::configure(['replay_window' => 300]);
    envelope_request([
        'ct'    => 'article',
        'ac'    => 'edit',
        'ts'    => plato::timestamp() - 3600,
        'nonce' => 'abcdefgh1234',
    ]);

    expect(envelope::resolve())->toBeNull();
});

it('refuses a timestamp too far in the future', function () {
    envelope::configure(['replay_window' => 300]);
    envelope_request([
        'ct'    => 'article',
        'ac'    => 'edit',
        'ts'    => plato::timestamp() + 3600,
        'nonce' => 'abcdefgh1234',
    ]);

    expect(envelope::resolve())->toBeNull();
});

it('skips the ts and nonce checks when replay protection is off', function () {
    envelope::configure(['replay_window' => 0]);
    envelope_request(['ct' => 'article', 'ac' => 'edit']);

    expect(envelope::resolve())->toBeArray();
});

/*
|--------------------------------------------------------------------------
| Wiring into the router
|--------------------------------------------------------------------------
*/

it('routes through the envelope when registered with the router', function () {
    envelope::register();
    envelope_request(['ct' => 'article', 'ac' => 'edit', 'method' => 'PATCH', 'data' => ['id' => 1]]);

    $r = route::resolve('/', 'POST');

    expect($r['source'])->toBe(route::SOURCE_CRYPTO);
    expect($r['ct'])->toBe('article');
    expect($r['method'])->toBe('PATCH');
});

it('holds an envelope route to the same name rules as a path', function () {
    envelope::register();
    envelope_request(['ct' => '../admin', 'ac' => 'del', 'method' => 'POST']);

    route::resolve('/', 'POST');
})->throws(route_exception::class);

it('holds an envelope method to the known method set', function () {
    envelope::register();
    envelope_request(['ct' => 'article', 'ac' => 'edit', 'method' => 'TRACE']);

    route::resolve('/', 'POST');
})->throws(route_exception::class);

it('reports whether the envelope source is usable', function () {
    expect(envelope::is_configured())->toBeTrue();

    envelope::configure(['clients' => []]);

    expect(envelope::is_configured())->toBeFalse();
});
