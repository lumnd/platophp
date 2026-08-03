<?php
use plato\exception\queue_exception;
use plato\plato;
use plato\queue\message;

plato::registry(plato_test_config());

it('fills the envelope for a new message', function () {
    $msg = new message('emails', ['to' => 'a@example.com']);

    expect($msg->queue())->toBe('emails');
    expect($msg->payload())->toBe(['to' => 'a@example.com']);
    expect($msg->attempts())->toBe(0);
    expect($msg->delay())->toBe(0);
    expect($msg->error())->toBeNull();
    expect($msg->handle())->toBeNull();
    expect($msg->id())->not->toBeEmpty();
    expect($msg->created_at())->toBeGreaterThan(0);
});

it('round trips through the wire format', function () {
    $msg = new message('emails', ['to' => 'a@example.com', 'subject' => '中文']);
    $msg->attempted()->set_error('boom');

    $back = message::decode($msg->encode());

    expect($back)->toBeInstanceOf(message::class);
    expect($back->id())->toBe($msg->id());
    expect($back->queue())->toBe($msg->queue());
    expect($back->payload())->toBe($msg->payload());
    expect($back->attempts())->toBe(1);
    expect($back->created_at())->toBe($msg->created_at());
    expect($back->error())->toBe('boom');
});

it('leaves unicode and slashes unescaped on the wire', function () {
    $json = (new message('q', ['url' => 'https://a/b', 'name' => '中文']))->encode();

    expect($json)->toContain('https://a/b');
    expect($json)->toContain('中文');
});

it('omits error until there is one', function () {
    $msg = new message('q', 1);

    expect($msg->to_array())->not->toHaveKey('error');
    expect($msg->set_error('bad')->to_array())->toHaveKey('error');
});

it('keeps the handle out of the envelope', function () {
    $msg = new message('q', 1);
    $msg->set_handle(['stream' => 'k', 'id' => '1-0']);

    expect($msg->handle())->toBe(['stream' => 'k', 'id' => '1-0']);
    expect($msg->encode())->not->toContain('1-0');
});

it('rejects a payload that cannot be encoded', function () {
    (new message('q', fopen('php://memory', 'r')))->encode();
})->throws(queue_exception::class);

it('returns null for anything that is not an envelope', function () {
    expect(message::decode('not json'))->toBeNull();
    expect(message::decode(''))->toBeNull();
    expect(message::decode('null'))->toBeNull();
    expect(message::decode('{"queue":"q"}'))->toBeNull();
    expect(message::from_array(['data' => 1]))->toBeNull();
    expect(message::from_array('x'))->toBeNull();
});

it('accepts a null payload, which is different from a missing one', function () {
    $msg = message::decode('{"queue":"q","data":null}');

    expect($msg)->toBeInstanceOf(message::class);
    expect($msg->payload())->toBeNull();
});

it('gives every message its own id', function () {
    $ids = [];
    for ( $i = 0; $i < 1000; $i++ )
    {
        $ids[] = message::new_id();
    }

    expect(array_unique($ids))->toHaveCount(1000);
});
