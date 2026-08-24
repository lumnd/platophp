<?php

/**
 * Template facade: the configured engine, and the decoration a finished page gets
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato;

use plato\debug\benchmark;
use plato\debug\profiler;
use plato\http\req;
use plato\view\engine;
use plato\view\smarty;

/**
 * What a controller renders through, and the owner of the `template` configuration section.
 *
 *     tpl::assign('row', $row);
 *     return resp::html(tpl::fetch('order/list'));
 *
 * **The engine behind it is a driver.** `template.driver` names a class implementing
 * plato\view\engine -- plato\view\smarty by default, plato\view\native for plain PHP templates with
 * no third-party package at all -- and everything else in the section is that driver's own. This
 * class reads `driver` and nothing more, which is why a Smarty delimiter is not part of its
 * vocabulary and a different engine can name a completely different set of settings.
 *
 * The driver is built on first use, so a JSON or CLI request that never renders a template does not
 * pay for one. That laziness is what lets the template engines stay Composer suggestions: engine()
 * is the only place a driver class is named at runtime, and the framework itself never calls it.
 * Its two touchpoints here -- plato::reset_request() calling reset(), and
 * error_handler::shutdown_handler() calling output() -- work on self::$output alone and never reach
 * the engine.
 *
 * **Rendering never echoes anything.** fetch() stores the page in self::$output and
 * error_handler::shutdown_handler() calls output() at the very end of the request, so the benchmark
 * placeholders and the profiler panel can still be appended to a finished page. An application
 * whose controllers return replies instead never reaches output() and calls decorate() on the
 * response body, which is the same decoration without the echo.
 */
class tpl
{
    /**
     * The driver used when the application configures none.
     */
    public const DEFAULT_DRIVER = smarty::class;

    /**
     * Rendered page, echoed by output() at shutdown.
     *
     * @var string|null
     */
    public static $output;

    /**
     * The `template` section, null until config() reads it.
     *
     * @var array<string, mixed>|null
     */
    private static $_config = null;

    /**
     * The configured engine, null until the first call that renders.
     *
     * @var engine|null
     */
    private static $_engine = null;

    /**
     * The `template` settings, read on the first call that needs them.
     *
     * @param string|null $key One setting, or null for all of them
     *
     * @return mixed
     */
    public static function config(?string $key = null)
    {
        if ( self::$_config === null )
        {
            self::$_config = (array) config::instance('config')->get('template');
        }

        return $key === null ? self::$_config : (self::$_config[$key] ?? null);
    }

    /**
     * Hand the settings over instead of letting them be read from config/config.php.
     *
     * Merges on top of the file settings, so an override names only what it changes. The engine is
     * dropped rather than reconfigured: a driver change has to build a different class, and a
     * driver that stayed the same is cheap to build again.
     *
     * @param array<string, mixed> $config Same shape as the `template` section
     *
     * @return void
     */
    public static function configure(array $config): void
    {
        self::$_config = $config + (array) self::config();
        self::$_engine = null;
    }

    /**
     * Drop the overrides, so the next read comes from the file again.
     *
     * @return void
     */
    public static function reset_config(): void
    {
        self::$_config = null;
        self::$_engine = null;
    }

    /**
     * The configured engine, built on the first call.
     *
     * @return engine
     *
     * @throws \RuntimeException When template.driver names something that is not an engine
     */
    public static function engine(): engine
    {
        if ( self::$_engine !== null )
        {
            return self::$_engine;
        }

        $config = (array) self::config();
        $driver = (string) ($config['driver'] ?? '');
        $driver = $driver !== '' ? $driver : self::DEFAULT_DRIVER;

        if ( !class_exists($driver) || !is_subclass_of($driver, engine::class) )
        {
            throw new \RuntimeException(
                'template.driver is set to "' . $driver . '", which does not implement '
                . engine::class . '. The drivers this package ships are ' . smarty::class
                . ' and ' . \plato\view\native::class
            );
        }

        unset($config['driver']);

        // Assigned after configure() so that a driver rejecting its settings is reported on every
        // call rather than once: a half-configured engine kept here would go on rendering from the
        // defaults it was built with. Building one again costs nothing -- a driver's constructor is
        // contractually forbidden from touching the filesystem or building its engine
        $engine = new $driver();
        $engine->configure($config);

        return self::$_engine = $engine;
    }

    /**
     * Assign a template variable, or a whole array of them when $value is omitted.
     *
     * @param array<string, mixed>|string $tpl_var
     * @param mixed                       $value
     *
     * @return void
     */
    public static function assign($tpl_var, $value = null)
    {
        self::engine()->assign($tpl_var, $value);
    }

    /**
     * @param string $tpl
     *
     * @return bool
     */
    public static function exists($tpl)
    {
        return self::engine()->exists((string) $tpl);
    }

    /**
     * Render a template and keep it for output(). Nothing is echoed here.
     *
     * @param string $tpl
     *
     * @return string
     */
    public static function fetch($tpl)
    {
        return self::$output = self::engine()->fetch((string) $tpl);
    }

    /**
     * Alias of fetch(), kept because controllers read better with it.
     *
     * @param string $tpl
     *
     * @return string
     */
    public static function display($tpl)
    {
        return self::fetch($tpl);
    }

    /**
     * Clear template state that belongs to the previous request of a resident process.
     *
     * The engine is cleared rather than dropped -- its compiled templates and registered plugins
     * are process state worth keeping -- and only when one was built, so a request that rendered
     * nothing does not construct an engine on its way out.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$output = '';

        if ( self::$_engine !== null )
        {
            self::$_engine->clear();
        }
    }

    /**
     * Echo the rendered page. Called once by error_handler::shutdown_handler().
     *
     * @return void
     */
    public static function output()
    {
        self::$output = self::$output ?? '';

        if ( req::is_json() )
        {
            echo self::$output;
            return;
        }

        self::$output = self::decorate(self::$output);

        echo self::$output;
    }

    /**
     * Put the benchmark figures and the profiler panel onto a finished page.
     *
     * output() does this on the way to echoing self::$output, which is the path taken by a page the
     * shutdown handler flushes. That is not the path plato::run() documents: a controller that
     * `return`s a reply is answered by resp::send(), output() never runs, and the panel was
     * unreachable for every application built the way the framework asks for.
     *
     * So the decoration is a method of its own, and an application returning replies calls it on
     * the body. From a middleware rather than from the action: the `_end` benchmark marks the
     * dispatcher makes have not been made yet while the action is still running, so a panel built
     * inside the action shows no completed spans at all.
     *
     * The profiler stays off until something calls profiler::instance()->enable_profiler(), so
     * beyond the placeholder substitution this does nothing unless it was asked for.
     *
     * @param string $html
     * @return string
     */
    public static function decorate($html)
    {
        $html = self::_replace_benchmarks($html);

        if ( profiler::instance()->enable_profiler !== true )
        {
            return $html;
        }

        // The panel goes after the page, so the closing tags come off and go back on. A fragment
        // that never had them keeps not having them
        $html = preg_replace('|</body>.*?</html>|is', '', $html, -1, $count) . profiler::instance()->run();

        return $count > 0 ? $html . '</body></html>' : $html;
    }

    /**
     * Replace the runtime placeholders a template may carry.
     *
     * `{elapsed_time}` / `{memory_usage}` are the formatted benchmark values between the
     * total_execution marks, `{exec_time}` / `{mem_usage}` are the raw request totals in
     * seconds and MB.
     *
     * @param string $output
     * @return string
     */
    private static function _replace_benchmarks($output)
    {
        if ( strpos($output, '{elapsed_time}') !== false || strpos($output, '{memory_usage}') !== false )
        {
            $output = str_replace(
                ['{elapsed_time}', '{memory_usage}'],
                [
                    benchmark::elapsed_time('total_execution_start', 'total_execution_end'),
                    benchmark::elapsed_memory('total_execution_start', 'total_execution_end'),
                ],
                $output
            );
        }

        if ( strpos($output, '{exec_time}') !== false || strpos($output, '{mem_usage}') !== false )
        {
            $total = plato::app_total();
            $output = str_replace(
                ['{exec_time}', '{mem_usage}'],
                [(string) round($total[0], 4), (string) round($total[1] / pow(1024, 2), 3)],
                $output
            );
        }

        return $output;
    }
}
