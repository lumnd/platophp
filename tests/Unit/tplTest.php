<?php
/**
 * tpl: the template facade and the owner of the `template` configuration section.
 *
 * What the engine behind it does with a template belongs to the driver's own test file; what is
 * here is the driver choice, the request boundary, and the decoration a finished page gets.
 */

use plato\debug\profiler;
use plato\plato;
use plato\tpl;
use plato\view\engine;
use plato\view\native;
use plato\view\smarty;

afterEach(function () {
    tpl::reset_config();
    tpl::$output = null;
});

it('builds the driver named by template.driver, once', function () {
    $engine = tpl::engine();

    expect($engine)->toBeInstanceOf(smarty::class)
        ->and($engine)->toBeInstanceOf(engine::class)
        // Same instance on every call
        ->and(tpl::engine())->toBe($engine);
});

it('hands the driver the section without the key naming it', function () {
    $config = tpl::engine()->config();

    expect($config)->not->toHaveKey('driver')
        // The fixture application overrides the delimiters, the rest are framework defaults
        ->and($config['left_delimiter'])->toBe('<{')
        ->and($config['cache_lifetime'])->toBe(120);
});

it('switches engine when the driver setting changes', function () {
    expect(tpl::engine())->toBeInstanceOf(smarty::class);

    tpl::configure(['driver' => native::class]);

    expect(tpl::engine())->toBeInstanceOf(native::class);
});

it('merges an override on top of the file settings instead of replacing them', function () {
    tpl::configure(['cache_lifetime' => 5]);

    expect(tpl::config('cache_lifetime'))->toBe(5)
        // Untouched by the override, so still the fixture application's value
        ->and(tpl::config('left_delimiter'))->toBe('<{');
});

it('reads the file again after reset_config', function () {
    tpl::configure(['cache_lifetime' => 5]);
    tpl::reset_config();

    expect(tpl::config('cache_lifetime'))->toBe(120);
});

it('refuses a driver that does not implement the contract', function () {
    tpl::configure(['driver' => stdClass::class]);

    expect(fn () => tpl::engine())
        ->toThrow(RuntimeException::class, 'does not implement');
});

it('keeps the rendered page for output instead of echoing it', function () {
    tpl::configure(['driver' => native::class]);

    $out = tpl::fetch('native/plain');

    expect(trim($out))->toBe('plain')
        ->and(tpl::$output)->toBe($out);
});

it('clears the assigned variables at the request boundary', function () {
    tpl::configure(['driver' => native::class]);
    tpl::assign('greeting', 'hello');

    expect(tpl::fetch('native/greeting'))->toBe('hello');

    plato::reset_request();

    expect(tpl::$output)->toBe('')
        ->and(tpl::fetch('native/greeting'))->toBe('');
});

it('does not build an engine for a request that rendered nothing', function () {
    tpl::reset();

    $built = new ReflectionProperty(tpl::class, '_engine');

    expect($built->getValue())->toBeNull()
        ->and(tpl::$output)->toBe('');
});

it('replaces the request total placeholders on output', function () {
    tpl::$output = 'took {exec_time}s {mem_usage}MB';

    ob_start();
    tpl::output();
    $out = ob_get_clean();

    expect($out)->toMatch('/^took [0-9.]+s [0-9.]+MB$/');
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
    }
});
