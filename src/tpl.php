<?php

/**
 * Smarty template engine wrapper
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato;

use plato\debug\benchmark;
use plato\debug\profiler;
use plato\http\req;
use plato\http\rewrite;
use plato\security\security;
use Smarty\Smarty;

/**
 * Template engine implementation, backed by Smarty 5.
 *
 * Rendering never echoes anything: fetch() stores the page in self::$output and
 * error_handler::shutdown_handler() calls output() at the very end of the request, so the
 * benchmark placeholders and the profiler panel can still be appended to a finished page.
 * An application whose controllers return replies instead never reaches output() and calls
 * decorate() on the response body, which is the same decoration without the echo.
 *
 * The Smarty instance is built lazily on first use, so a JSON or CLI request that never
 * renders a template does not pay for it. That laziness is why smarty/smarty is a suggestion
 * rather than a requirement: instance() is the only place the class is named at runtime, and
 * the framework itself never calls it. Its two touchpoints here -- plato::reset_request()
 * calling reset(), and error_handler::shutdown_handler() calling output() -- work on
 * self::$output alone and never reach the engine.
 *
 * @version $Id$
 */
class tpl
{
    /**
     * Default template settings, overridden by the `template` section of config/config.php.
     */
    public const DEFAULT_CONFIG = array(
        'left_delimiter'  => '{',
        'right_delimiter' => '}',
        'compile_check'   => true,
        'force_compile'   => false,
        'escape_html'     => true,
        'debugging'       => false,
        'caching'         => false,
        'cache_lifetime'  => 120,
        'plugins'         => array('smarty_plugins'),
    );

    /**
     * Plugin types loaded from `{type}.{name}.php` files inside the plugin directories.
     */
    public const PLUGIN_TYPES = array('function', 'modifier', 'modifiercompiler', 'block', 'compiler');

    /**
     * Plugins the framework ships, as [type, template name, method on this class].
     *
     * Framework plugins are private methods registered through Closure::fromCallable().
     */
    private const BUILTIN_PLUGINS = array(
        array('block', 'rewrite', '_plugin_rewrite'),
        array('function', 'form_token', '_plugin_form_token'),
        array('function', 'plato_page_data', '_plugin_plato_page_data'),
        array('function', 'request_em', '_plugin_request_em'),
        array('function', 'string_array', '_plugin_string_array'),
        array('modifier', 'date_f', '_plugin_date_f'),
        array('modifier', 'day2date', '_plugin_day2date'),
    );

    /**
     * Effective template settings, filled in on first use.
     */
    public static $config = array();

    /**
     * Directory overrides, resolved from the application paths when left empty.
     */
    public static $template_dir = null;
    public static $compile_dir = null;
    public static $cache_dir = null;

    /**
     * Rendered page, echoed by output() at shutdown.
     */
    public static $output;

    private static $_instance = null;

    /**
     * Smarty instance, created on first call.
     *
     * @return Smarty
     * @throws \RuntimeException When smarty/smarty is not installed
     */
    public static function instance()
    {
        if (self::$_instance !== null)
        {
            return self::$_instance;
        }

        if (!class_exists(Smarty::class))
        {
            throw new \RuntimeException(
                'plato\tpl needs the smarty/smarty package, which this framework only suggests: '
                . 'run `composer require smarty/smarty`. Nothing else in the framework renders '
                . 'templates, so an application serving JSON or running on the CLI does not need it'
            );
        }

        self::$config = array_merge(
            self::DEFAULT_CONFIG,
            (array) config::instance('config')->get('template')
        );

        self::$template_dir = self::$template_dir ?: plato::app_path('template');
        self::$compile_dir  = self::$compile_dir  ?: plato::data_path('template' . DIRECTORY_SEPARATOR . 'compile');
        self::$cache_dir    = self::$cache_dir    ?: plato::data_path('template' . DIRECTORY_SEPARATOR . 'cache');

        $smarty = new Smarty();
        $smarty->setTemplateDir(self::$template_dir);
        $smarty->setCompileDir(file::path_exists(self::$compile_dir));
        $smarty->setCacheDir(file::path_exists(self::$cache_dir));
        $smarty->setLeftDelimiter(self::$config['left_delimiter']);
        $smarty->setRightDelimiter(self::$config['right_delimiter']);
        $smarty->setCompileCheck(self::$config['compile_check'] ? Smarty::COMPILECHECK_ON : Smarty::COMPILECHECK_OFF);
        $smarty->setForceCompile((bool) self::$config['force_compile']);
        $smarty->setEscapeHtml((bool) self::$config['escape_html']);
        $smarty->setDebugging((bool) self::$config['debugging']);
        $smarty->setCaching(self::$config['caching'] ? Smarty::CACHING_LIFETIME_CURRENT : Smarty::CACHING_OFF);
        $smarty->setCacheLifetime((int) self::$config['cache_lifetime']);

        self::$_instance = $smarty;

        self::_register_plugins($smarty);
        self::_assign_defaults($smarty);

        return $smarty;
    }

    /**
     * Register the application's `{type}.{name}.php` plugin files, then whatever the framework
     * ships that the application did not already claim.
     *
     * Application files are loaded here and handed to registerPlugin() one by one. The supported
     * `smarty_{type}_{name}` function naming is retained. The application goes first and the first
     * definition of a name wins, so a framework plugin is overridden the same way a config file is.
     *
     * @param Smarty $smarty
     * @return void
     */
    private static function _register_plugins(Smarty $smarty)
    {
        foreach ((array) self::$config['plugins'] as $name)
        {
            $dir = plato::app_path($name);
            if (!is_dir($dir))
            {
                continue;
            }

            foreach (self::PLUGIN_TYPES as $type)
            {
                foreach ((array) glob($dir . DIRECTORY_SEPARATOR . $type . '.?*.php') as $filename)
                {
                    $plugin = substr(basename($filename), strlen($type) + 1, -4);
                    if ($plugin === '' || $smarty->getRegisteredPlugin($type, $plugin) !== null)
                    {
                        continue;
                    }

                    require_once $filename;

                    $callback = 'smarty_' . $type . '_' . $plugin;
                    if (function_exists($callback))
                    {
                        $smarty->registerPlugin($type, $plugin, $callback);
                    }
                }
            }
        }

        foreach (self::BUILTIN_PLUGINS as $builtin)
        {
            list($type, $plugin, $method) = $builtin;

            if ($smarty->getRegisteredPlugin($type, $plugin) !== null)
            {
                continue;
            }

            // fromCallable rather than a plain [self::class, $method] pair: the closure carries
            // this class's scope, so Smarty can invoke it while the methods stay private
            $smarty->registerPlugin($type, $plugin, \Closure::fromCallable(array(self::class, $method)));
        }
    }

    /**
     * Block plugin `rewrite`: rewrites every link in the body through the rewrite rules.
     *
     *     <{rewrite}>...<{/rewrite}>
     *
     * @param  array            $params
     * @param  string|null      $content  Null on the opening tag, the captured body on the closing one
     * @param  \Smarty\Template $template
     * @param  bool             $repeat
     * @return string
     */
    private static function _plugin_rewrite($params, $content, $template, &$repeat)
    {
        // Opening tag: the body has not been captured yet
        if ($content === null)
        {
            return '';
        }

        return rewrite::convert_url($content);
    }

    /**
     * Function plugin `form_token`: the csrf token, as a hidden input or as the bare value.
     * Empty string when csrf_token_on is off.
     *
     *     <{form_token}>             hidden input
     *     <{form_token type="raw"}>  token value only
     *
     * @param  array            $params
     * @param  \Smarty\Template $template
     * @return string
     */
    private static function _plugin_form_token($params, $template)
    {
        $token = security::get_csrf_hash();
        if ( $token === null )
        {
            return '';
        }

        $type  = empty($params['type']) ? 'form' : $params['type'];

        if ($type !== 'form')
        {
            return $token;
        }

        return '<input type="hidden" name="' . security::get_csrf_token_name() . '" value="' . $token . '" />';
    }

    /**
     * Function plugin `plato_page_data`: dumps the template variables into a JSON object inside
     * a <script> tag, so the page javascript can read what the controller assigned. Without
     * `key` everything is exported.
     *
     *     <{plato_page_data}>                          window.PLATO_PAGE_DATA = {...}
     *     <{plato_page_data key='row'}>                only the `row` variable
     *     <{plato_page_data key=['row', 'total']}>     several variables
     *     <{plato_page_data bind_name='PAGE'}>         bind to another name
     *
     * @param  array            $params
     * @param  \Smarty\Template $template
     * @return string
     */
    private static function _plugin_plato_page_data($params, $template)
    {
        // Includes the variables assigned on the engine, not only the ones local to this template
        $tpl_vars = $template->getTemplateVars();

        if (!empty($params['key']))
        {
            $value = array();
            foreach ((array) $params['key'] as $key)
            {
                $value[$key] = $tpl_vars[$key] ?? null;
            }
        }
        else
        {
            $value = $tpl_vars;
        }

        // JSON_HEX_TAG and friends keep a value containing "</script>" from breaking out of the tag
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        $bind_name = empty($params['bind_name']) ? 'PLATO_PAGE_DATA' : (string) $params['bind_name'];
        if (!preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $bind_name))
        {
            $bind_name = 'PLATO_PAGE_DATA';
        }

        return "<script> var {$bind_name} = {$json}; </script>";
    }

    /**
     * Function plugin `request_em`: reads an element that may not be there, returning an empty
     * string instead of raising an undefined index notice. Without `array` the lookup goes to
     * the request.
     *
     *     <{request_em key='page_no' default='1'}>
     *     <{request_em array=$row key='title'}>
     *
     * @param  array            $params
     * @param  \Smarty\Template $template
     * @return mixed
     */
    private static function _plugin_request_em($params, $template)
    {
        if (empty($params['key']))
        {
            return '';
        }

        if (!empty($params['array']))
        {
            $arr = (array) $params['array'];
            return $arr[$params['key']] ?? '';
        }

        return req::item($params['key'], $params['default'] ?? '');
    }

    /**
     * Function plugin `string_array`: splits a delimited string and assigns the result to a
     * template variable, so the template can iterate over it. Outputs nothing. The delimiter
     * defaults to a newline.
     *
     *     <{string_array val=$row.tags name='tags' spstring=','}>
     *     <{foreach $tags as $tag}>...<{/foreach}>
     *
     * @param  array            $params
     * @param  \Smarty\Template $template
     * @return string
     */
    private static function _plugin_string_array($params, $template)
    {
        if (empty($params['name']))
        {
            return '';
        }

        $spstring = empty($params['spstring']) ? "\n" : $params['spstring'];
        $value    = isset($params['val']) ? (string) $params['val'] : '';

        $template->assign($params['name'], $value === '' ? array() : explode($spstring, $value));

        return '';
    }

    /**
     * Modifier plugin `date_f`: formats a unix timestamp with the given date() format.
     *
     *     <{$timestamp|date_f:'Y-m-d'}>
     *
     * @param  int|string $t Unix timestamp
     * @param  string     $f date() format
     * @return string
     */
    private static function _plugin_date_f($t, $f)
    {
        return date($f, (int) $t);
    }

    /**
     * Modifier plugin `day2date`: expands a YYMMDDHH string back into `20YY-MM-DD HH(havg)`.
     *
     *     <{$dayh|day2date}>
     *
     * @param  string $dayh
     * @return string
     */
    private static function _plugin_day2date($dayh)
    {
        $y = substr($dayh, 0, 2);
        $m = substr($dayh, 2, 2);
        $d = substr($dayh, 4, 2);
        $h = substr($dayh, 6, 2);

        return "20{$y}-{$m}-{$d} {$h}(havg)";
    }

    /**
     * Variables every template can count on.
     *
     * @param Smarty $smarty
     * @return void
     */
    private static function _assign_defaults(Smarty $smarty)
    {
        $smarty->assign('app_name', $_ENV['APP_NAME'] ?? 'platoapp');
        $smarty->assign('request', req::$forms);
        // Cache buster appended to asset urls, `<img src="a.png<{$clear_cache}>">`
        $smarty->assign('clear_cache', '?' . time());
    }

    /**
     * Assign a template variable, or a whole array of them when $value is omitted.
     *
     * @param array|string $tpl_var
     * @param mixed        $value
     * @return void
     */
    public static function assign($tpl_var, $value = null)
    {
        self::instance()->assign($tpl_var, $value);
    }

    /**
     * @param string $tpl
     * @return bool
     */
    public static function exists($tpl)
    {
        return self::instance()->templateExists($tpl);
    }

    /**
     * Render a template and keep it for output(). Nothing is echoed here.
     *
     * @param string $tpl
     * @return string
     */
    public static function fetch($tpl)
    {
        return self::$output = self::instance()->fetch($tpl);
    }

    /**
     * Alias of fetch(), kept because controllers read better with it.
     *
     * @param string $tpl
     * @return string
     */
    public static function display($tpl)
    {
        return self::fetch($tpl);
    }

    /**
     * Clear template state that belongs to the previous request of a resident process.
     */
    public static function reset(): void
    {
        self::$output = '';

        if ( self::$_instance !== null )
        {
            self::$_instance->clearAllAssign();
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

        if (req::is_json())
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

        if (profiler::instance()->enable_profiler !== true)
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
        if (strpos($output, '{elapsed_time}') !== false || strpos($output, '{memory_usage}') !== false)
        {
            $output = str_replace(
                array('{elapsed_time}', '{memory_usage}'),
                array(
                    benchmark::elapsed_time('total_execution_start', 'total_execution_end'),
                    benchmark::elapsed_memory('total_execution_start', 'total_execution_end'),
                ),
                $output
            );
        }

        if (strpos($output, '{exec_time}') !== false || strpos($output, '{mem_usage}') !== false)
        {
            $total = plato::app_total();
            $output = str_replace(
                array('{exec_time}', '{mem_usage}'),
                array((string) round($total[0], 4), (string) round($total[1] / pow(1024, 2), 3)),
                $output
            );
        }

        return $output;
    }
}
