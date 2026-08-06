<?php

/**
 * Configuration access: framework defaults overlaid with the application files
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato;

use plato\exception\config_exception;

/**
 * Config reader.
 *
 * One instance per module, obtained through instance(); a module is the config file name without
 * its extension.
 *
 * Modules are read from the framework first and the application second, the application
 * only has to name the keys it changes. Merging is recursive on string keys, which means a list
 * configured by the application replaces the framework list index by index rather than as a
 * whole. There is no per environment config file: a value that differs per environment belongs
 * in .env and is read from $_ENV inside the config file.
 *
 * Values are read with dot notation and memoised for the life of the process; set() and del()
 * only touch that copy, they never write a file back.
 */
class config
{
    /**
     * Live instances, module => config.
     *
     * @var array<string, config>
     */
    private static $_instances = [];

    /**
     * Module name, i.e. the config file name without its extension.
     *
     * @var string
     */
    private $_module;

    /**
     * Loaded values, null until load() ran.
     *
     * @var array<string, mixed>|null
     */
    private $_configs = null;

    /**
     * Alias placeholders, '@name@' => replacement.
     *
     * @var array<string, string>
     */
    private $_alias = [];

    /**
     * Instance of a module.
     *
     * @param string $module Config file name without extension
     * @return config
     */
    public static function instance($module = 'config')
    {
        if ( !isset(self::$_instances[$module]) )
        {
            self::$_instances[$module] = new self($module);
        }

        return self::$_instances[$module];
    }

    /**
     * Drop every instance, the next instance() reads the values again.
     *
     * Meant for tests and long running workers, a plain web request has no reason to call it.
     *
     * @return void
     */
    public static function flush()
    {
        self::$_instances = [];
    }

    /**
     * @param string $module
     */
    private function __construct($module = 'config')
    {
        $this->_module = $module;
    }

    /**
     * Values of the module, read on first use and memoised afterwards.
     *
     * @return array<string, mixed>
     * @throws config_exception When no file of the module exists
     */
    public function load()
    {
        if ( $this->_configs === null )
        {
            $this->_configs = $this->_load_files();
        }

        return $this->_configs;
    }

    /**
     * Forget the memoised values and read the module again.
     *
     * @return array<string, mixed>
     * @throws config_exception
     */
    public function reload()
    {
        $this->_configs = null;

        return $this->load();
    }

    /**
     * Every value of the module.
     *
     * @param bool $alias Whether alias placeholders are replaced
     * @return array<string, mixed>
     * @throws config_exception
     */
    public function all($alias = true)
    {
        return (array) $this->get(null, [], $alias);
    }

    /**
     * Whether a (dot notated) key is configured.
     *
     * Tells a configured null, false or empty string apart from a missing key, which get()
     * cannot do on its own.
     *
     * @param string $key
     * @return bool
     * @throws config_exception
     */
    public function has($key)
    {
        $missing = new \stdClass();

        return arr::get($this->load(), $key, $missing) !== $missing;
    }

    /**
     * Read a (dot notated) value.
     *
     * The default is only used when the key is absent: a configured false, 0 or '' is returned
     * as it stands.
     *
     * @param string|int|null $key     Dot notated key, null for the whole module. An int is a key
     *                                 too: config/exception.php is indexed by exception code, and
     *                                 that is how error_handler reads a message template
     * @param mixed           $default Returned when the key is not configured
     * @param bool            $alias   Whether alias placeholders are replaced
     * @return mixed
     * @throws config_exception
     */
    public function get($key = null, $default = null, $alias = true)
    {
        $value = arr::get($this->load(), $key, $default);

        return $alias ? $this->_apply_alias($value) : $value;
    }

    /**
     * Set a (dot notated) value on the in-process copy.
     *
     * @param string $key
     * @param mixed  $value
     * @return config
     * @throws config_exception
     */
    public function set($key, $value)
    {
        $configs = $this->load();
        arr::set($configs, $key, $value);
        $this->_configs = $configs;

        return $this;
    }

    /**
     * Remove a (dot notated) key from the in-process copy.
     *
     * @param string|array<int, string> $key
     * @return array<string, bool>|bool True when the key was there
     * @throws config_exception
     */
    public function del($key)
    {
        $configs = $this->load();
        $deleted = arr::del($configs, $key);
        $this->_configs = $configs;

        return $deleted;
    }

    /**
     * Register an alias, '@name@' in any string value is replaced with $value on read.
     *
     * @param string $key
     * @param mixed  $value
     * @return config
     */
    public function set_alias($key, $value)
    {
        $this->_alias["@{$key}@"] = (string) $value;

        return $this;
    }

    /**
     * Read the module from the framework and the application config directories.
     *
     * @return array<string, mixed>
     * @throws config_exception When none of the candidate files exists
     */
    private function _load_files()
    {
        $paths = [plato::framework_path('config') . DIRECTORY_SEPARATOR];
        if ( plato::app_path() !== '' )
        {
            $paths[] = plato::app_path('config') . DIRECTORY_SEPARATOR;
        }

        $configs = [];
        $tried   = [];
        $found   = false;

        foreach ( $paths as $path )
        {
            $file    = $path . $this->_module . '.php';
            $tried[] = $file;

            if ( !is_file($file) )
            {
                continue;
            }

            $found   = true;
            $configs = arr::merge_assoc($configs, (array) require $file);
        }

        if ( !$found )
        {
            throw new config_exception([implode(', ', $tried)], 1002);
        }

        return $configs;
    }

    /**
     * Replace the registered aliases in a value, recursing into arrays.
     *
     * @param mixed $value
     * @return mixed
     */
    private function _apply_alias($value)
    {
        if ( $this->_alias === [] )
        {
            return $value;
        }

        if ( is_array($value) )
        {
            return array_map([$this, '_apply_alias'], $value);
        }

        if ( !is_string($value) )
        {
            return $value;
        }

        return str_replace(array_keys($this->_alias), array_values($this->_alias), $value);
    }
}
