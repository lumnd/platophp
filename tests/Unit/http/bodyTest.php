<?php

/**
 * plato\http\body: the request body parser, on its own.
 *
 * A real unit test — three arguments in, an array out, no superglobals and no bootstrap. That is
 * the whole reason this was lifted out of req: the cases below are the ones a request layer gets
 * wrong (a boundary holding a regex metacharacter, a JSON body that is a bare scalar, a document
 * pulling in an external entity), and none of them needs a request to exist.
 */

use plato\exception\request_exception;
use plato\http\body;

it('strips the parameters off a media type', function () {
    expect(body::bare_mime('application/json; charset=utf-8'))->toBe('application/json')
        ->and(body::bare_mime('  APPLICATION/JSON  '))->toBe('application/json')
        ->and(body::bare_mime(''))->toBe('');
});

it('leaves a form encoded GET or POST body to php', function () {
    $parsed = body::parse('a=1&b=2', 'application/x-www-form-urlencoded', 'post');

    // PHP already put it in $_POST; parsing it again here would only disagree with what it did
    expect($parsed['data'])->toBe('a=1&b=2')
        ->and($parsed['known'])->toBeTrue();
});

it('parses a form encoded body php does not parse', function () {
    foreach (['put', 'patch', 'delete'] as $method)
    {
        $parsed = body::parse('a=1&b=two', 'application/x-www-form-urlencoded', $method);

        expect($parsed['data'])->toBe(['a' => '1', 'b' => 'two']);
    }
});

it('refuses a form body php truncated rather than serving half of it', function () {
    $limit = (int) ini_get('max_input_vars');
    $raw   = implode('&', array_map(fn ($i) => "f{$i}=1", range(0, $limit + 1)));

    expect(fn () => body::parse($raw, 'application/x-www-form-urlencoded', 'post'))
        ->toThrow(Exception::class, 'Input truncated by PHP');
});

it('parses a multipart body', function () {
    $boundary = '----WebKitFormBoundaryABC123';
    $raw      = "--{$boundary}\r\n"
        . "Content-Disposition: form-data; name=\"name\"\r\n\r\nplato\r\n"
        . "--{$boundary}\r\n"
        . "Content-Disposition: form-data; name=\"age\"\r\n\r\n10\r\n"
        . "--{$boundary}--\r\n";

    $parsed = body::parse($raw, 'multipart/form-data; boundary=' . $boundary, 'post');

    expect($parsed['data'])->toBe(['name' => 'plato', 'age' => '10']);
});

it('quotes a multipart boundary holding a regex metacharacter', function () {
    // The boundary is client supplied. Spliced into the pattern unquoted, the '/' below ends the
    // delimiter and the split matches nothing at all
    $boundary = 'a/b+c.d';
    $raw      = "--{$boundary}\r\n"
        . "Content-Disposition: form-data; name=\"name\"\r\n\r\nplato\r\n"
        . "--{$boundary}--\r\n";

    $parsed = body::parse($raw, 'multipart/form-data; boundary=' . $boundary, 'post');

    expect($parsed['data'])->toBe(['name' => 'plato']);
});

it('finds nothing in a multipart body that declared no boundary', function () {
    $parsed = body::parse("--x\r\nname\r\n--x--", 'multipart/form-data', 'post');

    expect($parsed['data'])->toBe([]);
});

it('parses a json body and reports it as json', function () {
    $parsed = body::parse('{"order":{"id":7}}', 'application/json', 'post');

    expect($parsed['data'])->toBe(['order' => ['id' => 7]])
        ->and($parsed['json'])->toBe(['order' => ['id' => 7]])
        ->and($parsed['xml'])->toBeNull();
});

it('refuses a json body that is not valid json', function () {
    expect(fn () => body::parse('{not json', 'application/json', 'post'))
        ->toThrow(request_exception::class);
});

it('refuses a json body that decodes to a scalar', function () {
    // A caller that declared JSON and sent `7` has made a mistake worth hearing about once
    expect(fn () => body::parse('7', 'application/json', 'post'))
        ->toThrow(request_exception::class);
});

it('parses an xml body into nested arrays', function () {
    $parsed = body::parse(
        '<root><order><id>7</id></order></root>',
        'text/xml',
        'post'
    );

    expect($parsed['data'])->toBe(['order' => ['id' => '7']])
        ->and($parsed['xml'])->toBe(['order' => ['id' => '7']])
        ->and($parsed['json'])->toBeNull();
});

it('drops a malformed xml body instead of throwing', function () {
    // Nothing on this path may throw: the error handler reads the request back, so an exception
    // here loops
    $parsed = body::parse('<root><unclosed>', 'application/xml', 'post');

    expect($parsed['data'])->toBe([])
        ->and($parsed['xml'])->toBe([])
        ->and($parsed['known'])->toBeTrue();
});

it('does not fetch what an xml document points at', function () {
    $xml = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY e SYSTEM "http://127.0.0.1:1/x">]>'
        . '<r><v>&e;</v></r>';

    $parsed = body::parse($xml, 'application/xml', 'post');

    // LIBXML_NONET refuses the fetch, so the entity is never resolved to anything
    expect($parsed['data'])->not->toContain('http://127.0.0.1:1/x');
});

it('hands back an unknown content type untouched, and says it did not understand it', function () {
    $parsed = body::parse('AAAA', 'application/octet-stream', 'post');

    expect($parsed['data'])->toBe('AAAA')
        ->and($parsed['known'])->toBeFalse()
        ->and($parsed['json'])->toBeNull()
        ->and($parsed['xml'])->toBeNull();
});

it('has nothing to complain about when there is no body at all', function () {
    $parsed = body::parse('', '', 'get');

    // An empty body under an unknown type is not a body the caller got wrong
    expect($parsed['data'])->toBe('')
        ->and($parsed['known'])->toBeTrue();
});
