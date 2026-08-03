<?php
/**
 * plato\cache\memory: the store that needs no server.
 *
 * A real unit test -- no redis, no file, no framework bootstrap.
 */

use plato\cache\memory;

it('stores and returns a scalar', function () {
    $store = new memory();

    expect($store->set('k', 10))->toBeTrue();
    expect($store->get('k'))->toBe(10);
});

it('stores and returns an array', function () {
    $store = new memory();
    $value = ['aa' => 1, 'bb' => 'ssss'];

    $store->set('k', $value);

    expect($store->get('k'))->toBe($value);
});

it('answers false for a key it does not hold', function () {
    $store = new memory();

    expect($store->get('missing'))->toBeFalse();
    expect($store->has('missing'))->toBeFalse();
});

it('hands back the default it was given for a key it does not hold', function () {
    $store    = new memory();
    $sentinel = new stdClass();

    expect($store->get('missing', $sentinel))->toBe($sentinel);
});

it('keeps a stored falsy value apart from a miss', function () {
    $store = new memory();

    foreach ( ['f' => false, 'z' => 0, 'e' => '', 'n' => null, 'a' => []] as $key => $value )
    {
        expect($store->set($key, $value))->toBeTrue();
        expect($store->get($key, 'default'))->toBe($value);
        expect($store->has($key))->toBeTrue();
    }
});

it('hands back a copy rather than the object it was given', function () {
    $store  = new memory();
    $object = new stdClass();
    $object->n = 1;

    $store->set('k', $object);
    $object->n = 2;

    expect($store->get('k')->n)->toBe(1);
});

it('reports how many keys del removed', function () {
    $store = new memory();
    $store->set('k', 1);

    expect($store->del('k'))->toBe(1);
    expect($store->del('k'))->toBe(0);
});

it('follows the redis ttl convention', function () {
    $store = new memory();

    $store->set('forever', 1);
    $store->set('limited', 1, 10);

    expect($store->ttl('missing'))->toBe(-2);
    expect($store->ttl('forever'))->toBe(-1);
    expect($store->ttl('limited'))->toBe(10);
});

it('treats a value whose lifetime has passed as missing', function () {
    $store = new memory();
    $store->set('k', 1, 1);

    // The only way to move the clock without a clock to inject; one second, once, in the whole
    // unit suite
    sleep(2);

    expect($store->get('k'))->toBeFalse();
    expect($store->has('k'))->toBeFalse();
    expect($store->ttl('k'))->toBe(-2);
});

it('counts up and down from nothing', function () {
    $store = new memory();

    expect($store->inc('n'))->toBe(1);
    expect($store->inc('n', 4))->toBe(5);
    expect($store->inc('n', -2))->toBe(3);
    expect($store->get('n'))->toBe(3);
});

it('refuses to count a value that is not a number', function () {
    $store = new memory();
    $store->set('n', 'abc');

    expect($store->inc('n'))->toBeFalse();
});

it('leaves the lifetime alone when counting', function () {
    $store = new memory();
    $store->set('n', 1, 10);
    $store->inc('n');

    expect($store->ttl('n'))->toBe(10);
});

it('drops everything on flush and on close', function () {
    $store = new memory();
    $store->set('a', 1);
    $store->set('b', 2);

    expect($store->flush())->toBeTrue();
    expect($store->get('a'))->toBeFalse();

    $store->set('c', 3);

    expect($store->close())->toBeTrue();
    expect($store->get('c'))->toBeFalse();
});
