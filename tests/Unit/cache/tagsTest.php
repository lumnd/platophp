<?php

/**
 * plato\cache\tags: tagged entries, driven against the memory store.
 *
 * The point of the design is that flush() costs one write per tag and never leaves half a group
 * behind, at the price of not deleting the entries themselves -- both halves of that are asserted
 * here.
 */

use plato\cache\cache;
use plato\config;
use plato\plato;

plato::registry(plato_test_config());

$original = null;

beforeEach(function () use (&$original) {
    $original = (string) config::instance('cache')->get('cache_type');

    cache::configure(['cache_type' => 'memory']);
});

afterEach(function () use (&$original) {
    cache::configure(['cache_type' => $original]);
});

it('stores and reads a tagged value', function () {
    cache::tags('user:7')->set('profile', ['name' => 'plato'], 60);

    expect(cache::tags('user:7')->get('profile'))->toBe(['name' => 'plato']);
});

it('keeps the same key apart under different tags', function () {
    cache::tags('user:7')->set('profile', 'seven', 60);
    cache::tags('user:8')->set('profile', 'eight', 60);

    expect(cache::tags('user:7')->get('profile'))->toBe('seven')
        ->and(cache::tags('user:8')->get('profile'))->toBe('eight');
});

it('addresses one entry whatever the order of the tags', function () {
    cache::tags(['a', 'b'])->set('k', 'value', 60);

    expect(cache::tags(['b', 'a'])->get('k'))->toBe('value');
});

it('makes every entry of a tag unreachable on flush', function () {
    cache::tags('user:7')->set('profile', 'value', 60);
    cache::tags('user:7')->set('settings', 'value', 60);

    expect(cache::tags('user:7')->flush())->toBeTrue();

    expect(cache::tags('user:7')->get('profile'))->toBeFalse()
        ->and(cache::tags('user:7')->get('settings'))->toBeFalse();
});

it('leaves the entries of another tag alone', function () {
    cache::tags('user:7')->set('profile', 'seven', 60);
    cache::tags('user:8')->set('profile', 'eight', 60);

    cache::tags('user:7')->flush();

    expect(cache::tags('user:8')->get('profile'))->toBe('eight');
});

it('invalidates a multi tagged entry through either of its tags', function () {
    cache::tags(['user:7', 'posts'])->set('feed', 'value', 60);

    cache::tags('posts')->flush();

    expect(cache::tags(['user:7', 'posts'])->get('feed'))->toBeFalse();
});

it('does not delete the entries themselves, only the way to reach them', function () {
    $key = cache::tags('user:7')->key('profile');
    cache::tags('user:7')->set('profile', 'value', 60);

    cache::tags('user:7')->flush();

    // Still in the store under its old composite key, waiting out its own lifetime. This is the
    // documented cost of versioned tags, and the reason a tagged entry wants a lifetime.
    expect(cache::get($key))->toBe('value');
});

it('produces a tagged value remember() finds missing', function () {
    $calls = 0;
    $build = function () use (&$calls) {
        $calls++;

        return 'built';
    };

    expect(cache::tags('user:7')->remember('feed', 60, $build))->toBe('built');
    expect(cache::tags('user:7')->remember('feed', 60, $build))->toBe('built');
    expect($calls)->toBe(1);

    // After a flush the producer runs again
    cache::tags('user:7')->flush();
    expect(cache::tags('user:7')->remember('feed', 60, $build))->toBe('built');
    expect($calls)->toBe(2);
});

it('deletes a single tagged entry without touching the group', function () {
    cache::tags('user:7')->set('profile', 'value', 60);
    cache::tags('user:7')->set('settings', 'value', 60);

    expect(cache::tags('user:7')->del('profile'))->toBe(1);

    expect(cache::tags('user:7')->has('profile'))->toBeFalse()
        ->and(cache::tags('user:7')->has('settings'))->toBeTrue();
});
