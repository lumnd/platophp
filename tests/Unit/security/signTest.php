<?php
/**
 * plato\security\sign: the canonical payload, the digest and the timing safe check.
 *
 * The payload cases are the ones worth having: two implementations that disagree about nulls,
 * nesting or ordering still produce a plausible looking hex string, and the mismatch only turns up
 * against the other side's client.
 */

use plato\plato;
use plato\security\sign;

plato::registry(plato_test_config());

afterEach(function () {
    sign::reset();
});

it('sorts keys and percent encodes values', function () {
    $payload = sign::payload(['b' => 2, 'a' => 1, 'c' => 'x y+z']);

    expect($payload)->toBe('a=1&b=2&c=x%20y%2Bz');
});

it('drops the signature field and the excluded routing parameters', function () {
    $payload = sign::payload(['ct' => 'user', 'ac' => 'login', 'sign' => 'deadbeef', 'uid' => 7]);

    expect($payload)->toBe('uid=7');
});

it('takes an explicit exclusion list over the configured one', function () {
    $payload = sign::payload(['ct' => 'user', 'uid' => 7, 'nonce' => 'n'], ['nonce']);

    // ct is no longer excluded once the caller names its own list; sign always is
    expect($payload)->toBe('ct=user&uid=7');
});

it('signs an absent parameter and a null one the same', function () {
    expect(sign::payload(['a' => 1, 'b' => null]))->toBe(sign::payload(['a' => 1]));
});

it('flattens nested arrays into bracket keys that sort with everything else', function () {
    $payload = sign::payload([
        'z'    => 1,
        'user' => ['name' => 'ann', 'id' => 3],
    ]);

    expect($payload)->toBe('user[id]=3&user[name]=ann&z=1');
});

it('does not confuse delimiters in values with separate fields', function () {
    $joined = ['a' => '1&b=2'];
    $split  = ['a' => '1', 'b' => '2'];

    expect(sign::payload($joined))->not->toBe(sign::payload($split));

    $signature = sign::make($joined, 'secret');

    expect(sign::verify($split, 'secret', $signature))->toBeFalse();
});

it('does not confuse a literal bracket key with a nested path', function () {
    expect(sign::payload(['user[id]' => 3]))
        ->toBe('user%5Bid%5D=3')
        ->not->toBe(sign::payload(['user' => ['id' => 3]]));
});

it('signs booleans the way a query string carries them', function () {
    expect(sign::payload(['on' => true, 'off' => false]))->toBe('off=&on=1');
});

it('is hmac by default', function () {
    $data = ['a' => 1, 'b' => 2];

    expect(sign::make($data, 'secret'))
        ->toBe(hash_hmac('sha256', 'a=1&b=2', 'secret'));
});

it('honours the algorithm, the case and the append style', function () {
    sign::configure(['algo' => 'md5', 'style' => 'append', 'upper' => true]);

    expect(sign::make(['a' => 1], 'secret'))->toBe(strtoupper(md5('a=1&key=secret')));
});

it('refuses an algorithm the runtime does not have', function () {
    sign::configure(['algo' => 'not-a-hash']);

    expect(fn () => sign::make(['a' => 1], 'secret'))->toThrow(InvalidArgumentException::class);
});

it('refuses a hash that cannot be used for hmac', function () {
    sign::configure(['algo' => 'adler32']);

    expect(fn () => sign::make(['a' => 1], 'secret'))->toThrow(InvalidArgumentException::class);
});

it('refuses an unknown signing style', function () {
    sign::configure(['style' => 'custom']);

    expect(fn () => sign::make(['a' => 1], 'secret'))->toThrow(InvalidArgumentException::class);
});

it('verifies a signature it made', function () {
    $data = ['ct' => 'user', 'ac' => 'login', 'uid' => 7, 'name' => 'ann'];
    $data = sign::attach($data, 'secret');

    expect($data['sign'])->toBeString();
    expect(sign::verify($data, 'secret'))->toBeTrue();
    expect(sign::verify($data, 'other secret'))->toBeFalse();
});

it('fails a tampered parameter, and passes a changed routing parameter', function () {
    $data = sign::attach(['ct' => 'user', 'uid' => 7], 'secret');

    expect(sign::verify(['ct' => 'user', 'uid' => 8] + $data, 'secret'))->toBeFalse();
    // ct is excluded, so a rewritten route does not break the signature
    expect(sign::verify(['ct' => 'account'] + $data, 'secret'))->toBeTrue();
});

it('fails a missing or empty signature rather than comparing nothing', function () {
    expect(sign::verify(['uid' => 7], 'secret'))->toBeFalse();
    expect(sign::verify(['uid' => 7, 'sign' => ''], 'secret'))->toBeFalse();
    expect(sign::verify(['uid' => 7], 'secret', ''))->toBeFalse();
});

it('takes the claimed signature as an argument when it did not travel in the data', function () {
    $signature = sign::make(['uid' => 7], 'secret');

    expect(sign::verify(['uid' => 7], 'secret', $signature))->toBeTrue();
    expect(sign::verify(['uid' => 7], 'secret', strrev($signature)))->toBeFalse();
});

it('renames the signature field', function () {
    sign::configure(['field' => 'signature']);

    $data = sign::attach(['uid' => 7], 'secret');

    expect($data)->toHaveKey('signature');
    expect(sign::verify($data, 'secret'))->toBeTrue();
});

it('merges an override rather than replacing the settings', function () {
    sign::configure(['algo' => 'sha1']);

    expect(sign::config('algo'))->toBe('sha1');
    expect(sign::config('field'))->toBe('sign');
    expect(sign::config('exclude'))->toBe(['ct', 'ac']);
});
