<?php
/**
 * view\native: the plain PHP driver.
 *
 * Templates come from the fixture application's template/native directory, the same way they would
 * in a real one. The driver is constructed directly here rather than reached through plato\tpl:
 * these cases are about what a driver does with a template, and the facade has its own file.
 */

use plato\view\native;

beforeEach(function () {
    $this->engine = new native();
    $this->engine->configure([]);
});

it('renders a template with nothing assigned to it', function () {
    expect(trim($this->engine->fetch('native/plain')))->toBe('plain');
});

it('appends the configured extension only when the name lacks it', function () {
    expect(trim($this->engine->fetch('native/plain')))->toBe('plain')
        ->and(trim($this->engine->fetch('native/plain.php')))->toBe('plain');
});

it('extracts the assigned variables into the template scope', function () {
    $this->engine->assign('greeting', 'hello');

    expect($this->engine->fetch('native/greeting'))->toBe('hello');
});

it('assigns a whole array at once, without dropping what is already there', function () {
    $this->engine->assign('greeting', 'hello');
    $this->engine->assign(['unsafe' => '<b>x</b>']);

    expect($this->engine->fetch('native/greeting'))->toBe('hello')
        ->and($this->engine->fetch('native/escape'))->toBe('&lt;b&gt;x&lt;/b&gt;');
});

it('escapes through the helper the template calls, not on the way in', function () {
    $this->engine->assign('unsafe', '<script>alert("x")</script>');

    expect($this->engine->fetch('native/escape'))
        ->toBe('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;');
});

it('assigns the same ambient variables the smarty driver does', function () {
    expect($this->engine->fetch('native/defaults'))->toBe($_ENV['APP_NAME']);
});

it('lets an application variable win over an ambient one', function () {
    $this->engine->assign('app_name', 'overridden');

    expect($this->engine->fetch('native/defaults'))->toBe('overridden');
});

it('renders a partial from inside a template, with the same variables in scope', function () {
    $this->engine->assign('greeting', 'hello');

    expect(trim($this->engine->fetch('native/partial')))->toBe('[hello]');
});

it('tells known templates from unknown ones', function () {
    expect($this->engine->exists('native/plain'))->toBeTrue()
        ->and($this->engine->exists('native/no_such_template'))->toBeFalse();
});

it('names the missing template rather than failing on the include', function () {
    expect(fn () => $this->engine->fetch('native/no_such_template'))
        ->toThrow(RuntimeException::class, 'no such template');
});

it('refuses a name that climbs out of the template directory', function () {
    // The fixture config file exists and is readable PHP, which is exactly why this must not
    // resolve: a template name assembled from request input is otherwise an arbitrary include
    expect($this->engine->exists('../config/config'))->toBeFalse()
        ->and(fn () => $this->engine->fetch('../config/config'))
        ->toThrow(RuntimeException::class);
});

it('discards the half-rendered buffer when a template throws', function () {
    $depth = ob_get_level();

    expect(fn () => $this->engine->fetch('native/broken'))
        ->toThrow(RuntimeException::class, 'template failed');

    // Neither the buffer nor the 'half' it printed survives the failure
    expect(ob_get_level())->toBe($depth);
});

it('drops the assigned variables when it is cleared', function () {
    $this->engine->assign('greeting', 'hello');
    $this->engine->clear();

    expect($this->engine->fetch('native/greeting'))->toBe('');
});

it('drops the assigned variables when it is reconfigured', function () {
    $this->engine->assign('greeting', 'hello');
    $this->engine->configure([]);

    // Same as what the Smarty driver does by dropping the engine its variables lived in
    expect($this->engine->fetch('native/greeting'))->toBe('');
});

it('renders from a configured template directory instead of the application one', function () {
    $this->engine->configure(['template_dir' => __DIR__ . '/../../Fixtures/app/template/native']);

    expect(trim($this->engine->fetch('plain')))->toBe('plain')
        ->and($this->engine->config('template_dir'))->toContain('native');
});

it('reports its effective settings, defaults included', function () {
    expect($this->engine->config('extension'))->toBe('.php')
        ->and($this->engine->config())->toHaveKey('template_dir')
        ->and($this->engine->config('no_such_setting'))->toBeNull();
});
