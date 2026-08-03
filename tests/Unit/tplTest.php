<?php
/**
 * tpl: the Smarty 5 wrapper.
 *
 * Templates and application plugins are resolved from the fixture application, the same way they
 * would be in a real one: app_path/template and app_path/smarty_plugins.
 */

use plato\debug\profiler;
use plato\http\req;
use plato\plato;
use plato\security\security;
use plato\tpl;

it('returns a configured Smarty 5 engine', function () {
    $smarty = tpl::instance();

    expect($smarty)->toBeInstanceOf(\Smarty\Smarty::class);
    expect($smarty->getLeftDelimiter())->toBe('<{');
    expect($smarty->getRightDelimiter())->toBe('}>');
    // Same instance on every call
    expect(tpl::instance())->toBe($smarty);
});

it('registers the framework plugins from the plugin directory', function () {
    $smarty = tpl::instance();

    expect($smarty->getRegisteredPlugin('function', 'form_token'))->not->toBeNull();
    expect($smarty->getRegisteredPlugin('function', 'plato_page_data'))->not->toBeNull();
    expect($smarty->getRegisteredPlugin('modifier', 'date_f'))->not->toBeNull();
    expect($smarty->getRegisteredPlugin('block', 'rewrite'))->not->toBeNull();
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
        $out   = tpl::instance()->fetch('string:<{form_token}>');

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

it('replaces the request total placeholders on output', function () {
    tpl::$output = 'took {exec_time}s {mem_usage}MB';

    ob_start();
    tpl::output();
    $out = ob_get_clean();

    expect($out)->toMatch('/^took [0-9.]+s [0-9.]+MB$/');

    tpl::$output = null;
});

it('decorates a page a controller returns instead of echoing', function () {
    $panel = profiler::instance();

    $panel->enable_profiler();

    try
    {
        $html = tpl::decorate('<html><body>page {exec_time}s</body></html>');

        expect($html)->toMatch('/page [0-9.]+s/')
            ->and($html)->toContain('id="plato_profiler"')
            // The panel sits inside the document, not after the closing tags
            ->and($html)->toEndWith('</body></html>')
            ->and(substr_count($html, '</html>'))->toBe(1);
    }
    finally
    {
        $panel->enable_profiler(false);
    }
});

it('leaves a page alone when the profiler was never enabled', function () {
    expect(profiler::instance()->enable_profiler)->toBeFalse();

    expect(tpl::decorate('<html><body>page</body></html>'))
        ->toBe('<html><body>page</body></html>');
});

it('disables profiling at the next resident request boundary', function () {
    profiler::instance()->enable_profiler();

    plato::reset_request();

    expect(profiler::instance()->enable_profiler)->toBeFalse();
});

it('renders zero when the memory peak is below the request baseline', function () {
    $start_mem = new \ReflectionProperty(plato::class, '_start_mem');
    $previous  = $start_mem->getValue();
    $start_mem->setValue(null, memory_get_peak_usage() + 1024 * 1024);

    try
    {
        tpl::$output = 'took {exec_time}s {mem_usage}MB';

        ob_start();
        tpl::output();
        $out = ob_get_clean();

        expect($out)->toMatch('/^took [0-9.]+s 0MB$/');
    }
    finally
    {
        $start_mem->setValue(null, $previous);
        tpl::$output = null;
    }
});
