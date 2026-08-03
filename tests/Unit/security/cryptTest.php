<?php

/**
 * crypt: the compressed, authenticated binary envelope codec.
 */

use plato\exception\plato_exception;
use plato\security\crypt;

const CRYPT_TEST_KEY = 'd0fea132b98363458135bdf0990879ecd0fea132b98363458135bdf0990879ec';
const CRYPT_TEST_CONTEXT = 'plato-envelope:request:web';

it('round trips unicode through the binary format', function () {
    $plain = '{"message":"PlatoPHP 中文","ok":true}';
    $wire = crypt::encode($plain, CRYPT_TEST_KEY, CRYPT_TEST_CONTEXT);

    expect($wire)->toStartWith(crypt::MAGIC . chr(crypt::VERSION))
        ->and(crypt::decode($wire, CRYPT_TEST_KEY, CRYPT_TEST_CONTEXT))->toBe($plain);
});

it('uses a fresh GCM nonce for every encoding', function () {
    $first = crypt::encode('same value', CRYPT_TEST_KEY, CRYPT_TEST_CONTEXT);
    $second = crypt::encode('same value', CRYPT_TEST_KEY, CRYPT_TEST_CONTEXT);

    expect($first)->not->toBe($second)
        ->and(crypt::decode($first, CRYPT_TEST_KEY, CRYPT_TEST_CONTEXT))->toBe('same value')
        ->and(crypt::decode($second, CRYPT_TEST_KEY, CRYPT_TEST_CONTEXT))->toBe('same value');
});

it('compresses repetitive json before encryption', function () {
    $json = json_encode([
        'items' => array_fill(0, 200, [
            'id' => 123,
            'name' => 'same repeated product name',
            'enabled' => true,
        ]),
    ], JSON_THROW_ON_ERROR);

    $wire = crypt::encode($json, CRYPT_TEST_KEY, CRYPT_TEST_CONTEXT);
    $flags = ord($wire[crypt::HEADER_LENGTH - 1]);

    expect($flags & crypt::FLAG_DEFLATE)->toBe(crypt::FLAG_DEFLATE)
        ->and(strlen($wire))->toBeLessThan((int) (strlen($json) * 0.3))
        ->and(crypt::decode($wire, CRYPT_TEST_KEY, CRYPT_TEST_CONTEXT))->toBe($json);
});

it('does not expand a small payload for compression', function () {
    $wire = crypt::encode('{}', CRYPT_TEST_KEY, CRYPT_TEST_CONTEXT);
    $flags = ord($wire[crypt::HEADER_LENGTH - 1]);

    expect($flags & crypt::FLAG_DEFLATE)->toBe(0)
        ->and(crypt::decode($wire, CRYPT_TEST_KEY, CRYPT_TEST_CONTEXT))->toBe('{}');
});

it('can explicitly disable compression', function () {
    $plain = str_repeat('repetitive value ', 100);
    $wire = crypt::encode($plain, CRYPT_TEST_KEY, CRYPT_TEST_CONTEXT, false);
    $flags = ord($wire[crypt::HEADER_LENGTH - 1]);

    expect($flags & crypt::FLAG_DEFLATE)->toBe(0)
        ->and(crypt::decode($wire, CRYPT_TEST_KEY, CRYPT_TEST_CONTEXT))->toBe($plain);
});

it('rejects a message under another purpose', function () {
    $wire = crypt::encode('request', CRYPT_TEST_KEY, CRYPT_TEST_CONTEXT);

    expect(crypt::decode($wire, CRYPT_TEST_KEY, 'plato-envelope:response:web'))->toBeNull();
});

it('rejects a message under another key', function () {
    $wire = crypt::encode('request', CRYPT_TEST_KEY, CRYPT_TEST_CONTEXT);

    expect(crypt::decode($wire, str_repeat('a', 64), CRYPT_TEST_CONTEXT))->toBeNull();
});

it('rejects any altered header body or tag byte', function () {
    $wire = crypt::encode(str_repeat('payload ', 40), CRYPT_TEST_KEY, CRYPT_TEST_CONTEXT);

    foreach ([0, crypt::HEADER_LENGTH + crypt::NONCE_LENGTH, strlen($wire) - 1] as $offset)
    {
        $altered = $wire;
        $altered[$offset] = chr(ord($altered[$offset]) ^ 0x01);

        expect(crypt::decode($altered, CRYPT_TEST_KEY, CRYPT_TEST_CONTEXT))->toBeNull();
    }
});

it('rejects unknown versions flags and truncated messages', function () {
    $wire = crypt::encode('payload', CRYPT_TEST_KEY, CRYPT_TEST_CONTEXT);

    $version = $wire;
    $version[strlen(crypt::MAGIC)] = chr(crypt::VERSION + 1);

    $flags = $wire;
    $flags[crypt::HEADER_LENGTH - 1] = chr(0x80);

    expect(crypt::decode($version, CRYPT_TEST_KEY, CRYPT_TEST_CONTEXT))->toBeNull()
        ->and(crypt::decode($flags, CRYPT_TEST_KEY, CRYPT_TEST_CONTEXT))->toBeNull()
        ->and(crypt::decode(substr($wire, 0, -1), CRYPT_TEST_KEY, CRYPT_TEST_CONTEXT))->toBeNull();
});

it('bounds both compressed and uncompressed plaintext', function () {
    $plain = str_repeat('a', 1024);
    $compressed = crypt::encode($plain, CRYPT_TEST_KEY, CRYPT_TEST_CONTEXT);
    $uncompressed = crypt::encode($plain, CRYPT_TEST_KEY, CRYPT_TEST_CONTEXT, false);

    expect(crypt::decode($compressed, CRYPT_TEST_KEY, CRYPT_TEST_CONTEXT, 100))->toBeNull()
        ->and(crypt::decode($uncompressed, CRYPT_TEST_KEY, CRYPT_TEST_CONTEXT, 100))->toBeNull();
});

it('rejects a wire body larger than the decoded limit before decryption', function () {
    $wire = crypt::encode(str_repeat('a', 1024), CRYPT_TEST_KEY, CRYPT_TEST_CONTEXT, false);

    expect(crypt::decode($wire, CRYPT_TEST_KEY, CRYPT_TEST_CONTEXT, 100))->toBeNull();
});

it('requires a 32 byte hexadecimal key and a non empty context', function () {
    crypt::encode('payload', str_repeat('a', 32), CRYPT_TEST_CONTEXT);
})->throws(plato_exception::class, 'key must be exactly 64 hexadecimal characters');

it('rejects an empty context', function () {
    crypt::encode('payload', CRYPT_TEST_KEY, '');
})->throws(plato_exception::class, 'context must not be empty');

it('rejects a non positive decoded size limit', function () {
    $wire = crypt::encode('payload', CRYPT_TEST_KEY, CRYPT_TEST_CONTEXT);

    crypt::decode($wire, CRYPT_TEST_KEY, CRYPT_TEST_CONTEXT, 0);
})->throws(plato_exception::class, 'maximum plaintext size must be greater than zero');
