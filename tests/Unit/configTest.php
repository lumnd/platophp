<?php
/**
 * config: the framework defaults overlaid with the application config/ directory.
 *
 * The module files these tests read live in tests/Fixtures/app/config/.
 */

use plato\config;
use plato\exception\config_exception;

beforeEach(function () {
    config::flush();
});

it('reads the framework defaults', function () {
    expect(config::instance('config')->get('route.default_ct'))->toBe('index');
});

it('ignores a module file carrying an environment suffix', function () {
    // Fixtures/app/config/testcfg_dev.php exists and the environment is dev, but suffixed files
    // are gone: environment differences go through .env
    $cfg = config::instance('testcfg');

    expect($cfg->get('name'))->toBe('base');
    expect($cfg->get('nested'))->toBe(['a' => 1, 'b' => 2]);
    expect($cfg->get('zero'))->toBe(0);
});

it('lets a configured falsy value win over the default', function () {
    $cfg = config::instance('testcfg');

    expect($cfg->get('debug', true))->toBeFalse();
    expect($cfg->get('zero', 42))->toBe(0);
    expect($cfg->get('blank', 'fallback'))->toBe('');
    expect($cfg->get('missing', 'fallback'))->toBe('fallback');
});

it('tells a missing key from a falsy one', function () {
    $cfg = config::instance('testcfg');

    expect($cfg->has('debug'))->toBeTrue();
    expect($cfg->has('blank'))->toBeTrue();
    expect($cfg->has('nested.a'))->toBeTrue();
    expect($cfg->has('nested.z'))->toBeFalse();
    expect($cfg->has('missing'))->toBeFalse();
});

it('returns the whole module', function () {
    expect(config::instance('testcfg')->all())->toHaveKeys(['name', 'nested', 'paths']);
});

it('keeps the file values when an untouched module is written to', function () {
    $cfg = config::instance('testcfg');

    $cfg->set('nested.c', 9);

    expect($cfg->get('nested'))->toBe(['a' => 1, 'b' => 2, 'c' => 9]);
    expect($cfg->get('name'))->toBe('base');
});

it('removes a dot notated key', function () {
    $cfg = config::instance('testcfg');

    expect($cfg->del('nested.a'))->toBeTrue();
    expect($cfg->get('nested'))->toBe(['b' => 2]);
    expect($cfg->del('nested.a'))->toBeFalse();
});

it('drops the in process changes on reload', function () {
    $cfg = config::instance('testcfg');

    $cfg->set('name', 'changed');
    expect($cfg->get('name'))->toBe('changed');

    $cfg->reload();
    expect($cfg->get('name'))->toBe('base');
});

it('replaces aliases in strings and in arrays', function () {
    $cfg = config::instance('testcfg');
    $cfg->set_alias('root', '/srv');

    expect($cfg->get('path'))->toBe('/srv/tmp');
    expect($cfg->get('paths'))->toBe(['/srv/a', '/srv/b']);
    expect($cfg->get('path', null, false))->toBe('@root@/tmp');
});

it('names the files it looked at for an unknown module', function () {
    config::instance('nope')->get('any');
})->throws(config_exception::class, 'config' . DIRECTORY_SEPARATOR . 'nope.php');
