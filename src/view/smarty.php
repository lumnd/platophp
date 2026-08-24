<?php

/**
 * Smarty 5 template engine driver
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\view;

use plato\file;
use plato\http\req;
use plato\http\rewrite;
use plato\plato;
use plato\security\security;

/**
 * The engine contract, answered by Smarty 5.
 *
 * The Smarty instance is built on the first call that needs it, so a JSON or CLI request that never
 * renders a template does not pay for it. That laziness is why smarty/smarty is a suggestion rather
 * than a requirement: _smarty() is the only place the class is named at runtime, and nothing in the
 * framework reaches it unless an application asked for this driver and then rendered something.
 *
 * Every setting Smarty understands is this driver's own. plato\tpl passes the `template` section
 * through without reading any of it beyond `driver`, which is what keeps a Smarty delimiter out of
 * the facade's vocabulary and lets a different driver name a completely different set of keys.
 */
class smarty implements engine
{
    /**
     * Settings this driver understands, and what they mean when the application says nothing.
     *
     * The three directories are empty rather than absent: empty means "work it out from the
     * application paths", which is what an application that never configures templates wants, and
     * a value here would be a path in somebody else's project.
     *
     * @var array<string, mixed>
     */
    public const DEFAULT_CONFIG = [
        'template_dir'    => '',
        'compile_dir'     => '',
        'cache_dir'       => '',
        'left_delimiter'  => '{',
        'right_delimiter' => '}',
        'compile_check'   => true,
        'force_compile'   => false,
        'escape_html'     => true,
        'debugging'       => false,
        'caching'         => false,
        'cache_lifetime'  => 120,
        'plugins'         => ['smarty_plugins'],
    ];

    /**
     * Plugin types loaded from `{type}.{name}.php` files inside the plugin directories.
     *
     * @var array<int, string>
     */
    public const PLUGIN_TYPES = ['function', 'modifier', 'modifiercompiler', 'block', 'compiler'];

    /**
     * Plugins the framework ships, as [type, template name, method on this class].
     *
     * Framework plugins are private methods registered through Closure::fromCallable().
     *
     * @var array<int, array<int, string>>
     */
    private const BUILTIN_PLUGINS = [
        ['block', 'rewrite', '_plugin_rewrite'],
        ['function', 'form_token', '_plugin_form_token'],
        ['function', 'plato_page_data', '_plugin_plato_page_data'],
        ['function', 'request_em', '_plugin_request_em'],
        ['function', 'string_array', '_plugin_string_array'],
        ['modifier', 'date_f', '_plugin_date_f'],
        ['modifier', 'day2date', '_plugin_day2date'],
    ];

    /**
     * Effective settings, DEFAULT_CONFIG until configure() says otherwise.
     *
     * @var array<string, mixed>
     */
    private $_config = self::DEFAULT_CONFIG;

    /**
     * The Smarty instance, null until the first render.
     *
     * @var \Smarty\Smarty|null
     */
    private $_smarty = null;

    /**
     * Whether the ambient variables have been assigned to the current instance.
     *
     * They are assigned per render rather than once at build time because clear() takes them away
     * with everything else, and because two of the three are request state: a resident worker that
     * built its engine during the first request would otherwise render every later one with that
     * first request's input and cache buster, and with nothing at all after the first boundary.
     *
     * @var bool
     */
    private $_defaults_assigned = false;

    /**
     * @param array<string, mixed> $config
     *
     * @return void
     */
    public function configure(array $config): void
    {
        $this->_config            = $config + self::DEFAULT_CONFIG;
        $this->_smarty            = null;
        $this->_defaults_assigned = false;
    }

    /**
     * One setting, for a caller that needs to know how this driver was configured.
     *
     * @param string|null $key One setting, or null for all of them
     *
     * @return mixed
     */
    public function config(?string $key = null)
    {
        return $key === null ? $this->_config : ($this->_config[$key] ?? null);
    }

    /**
     * The Smarty instance itself, for the handful of things this contract deliberately does not
     * cover -- registering a filter, clearing a compiled template, reading a template's own
     * variables.
     *
     * Reaching for it ties the calling code to Smarty, which is the point of it being a separate
     * method rather than what fetch() returns.
     *
     * @return \Smarty\Smarty
     *
     * @throws \RuntimeException When smarty/smarty is not installed
     */
    public function raw()
    {
        return $this->_smarty();
    }

    /**
     * @param array<string, mixed>|string $tpl_var
     * @param mixed                       $value
     *
     * @return void
     */
    public function assign($tpl_var, $value = null): void
    {
        $this->_smarty()->assign($tpl_var, $value);
    }

    /**
     * @param string $tpl
     *
     * @return bool
     */
    public function exists(string $tpl): bool
    {
        return $this->_smarty()->templateExists($tpl);
    }

    /**
     * @param string $tpl
     *
     * @return string
     */
    public function fetch(string $tpl): string
    {
        $smarty = $this->_smarty();

        $this->_assign_defaults($smarty);

        return (string) $smarty->fetch($tpl);
    }

    /**
     * @return void
     */
    public function clear(): void
    {
        $this->_defaults_assigned = false;

        if ( $this->_smarty !== null )
        {
            $this->_smarty->clearAllAssign();
        }
    }

    /**
     * Build the Smarty instance, once.
     *
     * @return \Smarty\Smarty
     *
     * @throws \RuntimeException When smarty/smarty is not installed
     */
    private function _smarty()
    {
        if ( $this->_smarty !== null )
        {
            return $this->_smarty;
        }

        if ( !class_exists(\Smarty\Smarty::class) )
        {
            throw new \RuntimeException(
                'plato\view\smarty needs the smarty/smarty package, which this framework only '
                . 'suggests: run `composer require smarty/smarty`, or configure template.driver as '
                . 'plato\view\native, which renders plain PHP templates with no dependency at all'
            );
        }

        $config = $this->_config;

        $smarty = new \Smarty\Smarty();
        $smarty->setTemplateDir($this->_dir('template_dir', plato::app_path('template')));
        $smarty->setCompileDir(file::path_exists($this->_dir('compile_dir', $this->_data_dir('compile'))));
        $smarty->setCacheDir(file::path_exists($this->_dir('cache_dir', $this->_data_dir('cache'))));
        $smarty->setLeftDelimiter((string) $config['left_delimiter']);
        $smarty->setRightDelimiter((string) $config['right_delimiter']);
        $smarty->setCompileCheck(
            $config['compile_check'] ? \Smarty\Smarty::COMPILECHECK_ON : \Smarty\Smarty::COMPILECHECK_OFF
        );
        $smarty->setForceCompile((bool) $config['force_compile']);
        $smarty->setEscapeHtml((bool) $config['escape_html']);
        $smarty->setDebugging((bool) $config['debugging']);
        $smarty->setCaching(
            $config['caching'] ? \Smarty\Smarty::CACHING_LIFETIME_CURRENT : \Smarty\Smarty::CACHING_OFF
        );
        $smarty->setCacheLifetime((int) $config['cache_lifetime']);

        // Assigned before the plugins are registered so that a plugin file doing work at include
        // time sees a usable instance
        $this->_smarty = $smarty;

        $this->_register_plugins($smarty);

        return $smarty;
    }

    /**
     * A configured directory, or the framework's default when nothing was configured.
     *
     * @param string $key
     * @param string $default
     *
     * @return string
     */
    private function _dir(string $key, string $default): string
    {
        $configured = trim((string) ($this->_config[$key] ?? ''));

        return $configured !== '' ? $configured : $default;
    }

    /**
     * @param string $name
     *
     * @return string
     */
    private function _data_dir(string $name): string
    {
        return plato::data_path('template' . DIRECTORY_SEPARATOR . $name);
    }

    /**
     * Register the application's `{type}.{name}.php` plugin files, then whatever the framework
     * ships that the application did not already claim.
     *
     * Application files are loaded here and handed to registerPlugin() one by one. The supported
     * `smarty_{type}_{name}` function naming is retained. The application goes first and the first
     * definition of a name wins, so a framework plugin is overridden the same way a config file is.
     *
     * @param \Smarty\Smarty $smarty
     *
     * @return void
     */
    private function _register_plugins($smarty)
    {
        foreach ( (array) $this->_config['plugins'] as $name )
        {
            $dir = plato::app_path($name);
            if ( !is_dir($dir) )
            {
                continue;
            }

            foreach ( self::PLUGIN_TYPES as $type )
            {
                foreach ( (array) glob($dir . DIRECTORY_SEPARATOR . $type . '.?*.php') as $filename )
                {
                    $plugin = substr(basename($filename), strlen($type) + 1, -4);
                    if ( $plugin === '' || $smarty->getRegisteredPlugin($type, $plugin) !== null )
                    {
                        continue;
                    }

                    require_once $filename;

                    $callback = 'smarty_' . $type . '_' . $plugin;
                    if ( function_exists($callback) )
                    {
                        $smarty->registerPlugin($type, $plugin, $callback);
                    }
                }
            }
        }

        foreach ( self::BUILTIN_PLUGINS as $builtin )
        {
            list($type, $plugin, $method) = $builtin;

            if ( $smarty->getRegisteredPlugin($type, $plugin) !== null )
            {
                continue;
            }

            // fromCallable rather than a plain [self::class, $method] pair: the closure carries
            // this class's scope, so Smarty can invoke it while the methods stay private
            $smarty->registerPlugin($type, $plugin, \Closure::fromCallable([self::class, $method]));
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
        if ( $content === null )
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

        if ( $type !== 'form' )
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

        if ( !empty($params['key']) )
        {
            $value = [];
            foreach ( (array) $params['key'] as $key )
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
        if ( !preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $bind_name) )
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
        if ( empty($params['key']) )
        {
            return '';
        }

        if ( !empty($params['array']) )
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
        if ( empty($params['name']) )
        {
            return '';
        }

        $spstring = empty($params['spstring']) ? "\n" : $params['spstring'];
        $value    = isset($params['val']) ? (string) $params['val'] : '';

        $template->assign($params['name'], $value === '' ? [] : explode($spstring, $value));

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
     * Variables every template can count on, added once per request.
     *
     * A name the application already assigned is left alone, so a controller assigning its own
     * `app_name` keeps it -- these are a floor, not an override. Assigned is decided by the key
     * being there rather than by the value being non-null, because getTemplateVars($name) answers
     * null for both a name nobody assigned and a name deliberately assigned null, and the plain PHP
     * driver keeps that second one.
     *
     * @param \Smarty\Smarty $smarty
     *
     * @return void
     */
    private function _assign_defaults($smarty)
    {
        if ( $this->_defaults_assigned )
        {
            return;
        }

        $this->_defaults_assigned = true;

        $defaults = [
            'app_name' => $_ENV['APP_NAME'] ?? 'platoapp',
            'request'  => req::$forms,
            // Cache buster appended to asset urls, `<img src="a.png<{$clear_cache}>">`
            'clear_cache' => '?' . time(),
        ];

        $assigned = (array) $smarty->getTemplateVars();

        foreach ( $defaults as $name => $value )
        {
            if ( !array_key_exists($name, $assigned) )
            {
                $smarty->assign($name, $value);
            }
        }
    }
}
