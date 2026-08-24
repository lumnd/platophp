<?php
/**
 * view\smarty: the Smarty 5 driver.
 *
 * Templates and application plugins are resolved from the fixture application, the same way they
 * would be in a real one: app_path/template and app_path/smarty_plugins. The cases go through
 * plato\tpl rather than constructing the driver, so that the settings under test are the ones the
 * facade actually hands over.
 */

use plato\http\req;
use plato\plato;
use plato\security\security;
use plato\tpl;
use plato\view\smarty;

afterEach(function () {
    tpl::reset_config();
    tpl::$output = null;
});

it('answers the engine contract with a configured Smarty 5 instance', function () {
    /** @var smarty $driver */
    $driver = tpl::engine();

    expect($driver)->toBeInstanceOf(smarty::class);

    $raw = $driver->raw();

    expect($raw)->toBeInstanceOf(\Smarty\Smarty::class)
        ->and($raw->getLeftDelimiter())->toBe('<{')
        ->and($raw->getRightDelimiter())->toBe('}>')
        // Same instance on every call
        ->and($driver->raw())->toBe($raw);
});

it('builds nothing until something renders', function () {
    $driver = new smarty();
    $driver->configure(['left_delimiter' => '<{', 'right_delimiter' => '}>']);

    $built = new ReflectionProperty(smarty::class, '_smarty');

    expect($built->getValue($driver))->toBeNull();
});

it('registers the framework plugins from the plugin directory', function () {
    /** @var smarty $driver */
    $driver = tpl::engine();
    $raw    = $driver->raw();

    expect($raw->getRegisteredPlugin('function', 'form_token'))->not->toBeNull();
    expect($raw->getRegisteredPlugin('function', 'plato_page_data'))->not->toBeNull();
    expect($raw->getRegisteredPlugin('modifier', 'date_f'))->not->toBeNull();
    expect($raw->getRegisteredPlugin('block', 'rewrite'))->not->toBeNull();
});

it('renders the token from the effective request configuration', function () {
    plato::$config['cli_csrf'] = true;
    req::configure([
        'csrf_token_on' => true,
        'csrf_secret'   => str_repeat('s', 32),
        'csrf_binding'  => static fn(): string => 'template-session',
    ]);

    try
    {
        security::capture();
        $token = security::get_csrf_hash();
        /** @var smarty $driver */
        $driver = tpl::engine();
        $out    = $driver->raw()->fetch('string:<{form_token}>');

        expect($out)->toContain('name="csrf_token_name"')
            ->and($out)->toContain('value="' . $token . '"');
    }
    finally
    {
        plato::$config['cli_csrf'] = false;
        req::reset_config();
        security::capture();
    }
});

it('tells known templates from unknown ones', function () {
    expect(tpl::exists('hello.tpl'))->toBeTrue();
    expect(tpl::exists('no_such_template.tpl'))->toBeFalse();
});

it('renders variables, defaults and plugins', function () {
    tpl::assign('greeting', 'hello');
    tpl::assign('row', ['title' => 'plato']);

    $out = trim(tpl::fetch('hello.tpl'));

    expect($out)->toBe('hello|' . $_ENV['APP_NAME'] . '|plato');
    // fetch keeps the page for output() instead of echoing it
    expect(trim(tpl::$output))->toBe($out);
});

it('scans the application plugins first, so they override the framework ones', function () {
    tpl::assign('ts', mktime(0, 0, 0, 3, 4, 2026));

    // Fixtures/app/smarty_plugins ships app_marker plus its own date_f
    expect(trim(tpl::fetch('app_plugins.tpl')))->toBe('app_marker|app:2026-03-04');
});

it('runs function and block plugins', function () {
    expect(trim(tpl::fetch('plugins.tpl')))->toBe('[a][b][c]|keep');
});

it('escapes values that could break out of the plato_page_data script tag', function () {
    tpl::assign('payload', ['html' => '</script><script>alert(1)</script>']);

    $out = trim(tpl::fetch('page_data.tpl'));

    expect($out)->toStartWith('<script> var PAGE = ');
    expect($out)->not->toContain('</script><script>');
    expect(json_decode(
        substr($out, strlen('<script> var PAGE = '), -strlen('; </script>')),
        true
    ))->toBe(['payload' => ['html' => '</script><script>alert(1)</script>']]);
});

it('escapes ordinary template variables by default', function () {
    tpl::assign('unsafe', '<script>alert(1)</script>');

    expect(trim(tpl::fetch('escape.tpl')))
        ->toBe('&lt;script&gt;alert(1)&lt;/script&gt;');
});

it('drops the assigned variables when the request boundary clears it', function () {
    tpl::assign('greeting', 'hello');
    tpl::engine()->clear();

    // The ambient variables come back for the next request; only what the application assigned goes
    expect(trim(tpl::fetch('hello.tpl')))->toBe('|' . $_ENV['APP_NAME'] . '|');
});

it('lets an application variable win over an ambient one', function () {
    tpl::assign('app_name', 'overridden');

    expect(trim(tpl::fetch('hello.tpl')))->toBe('|overridden|');
});
