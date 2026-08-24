<?php

/**
 * Plain PHP template engine driver
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\view;

use plato\http\req;
use plato\plato;

/**
 * The engine contract, answered by PHP itself.
 *
 * A template is a `.php` file under the template directory. The assigned variables are extracted
 * into its scope and its output is captured, so `<?= $title ?>` is the whole of the syntax:
 *
 *     tpl::assign('title', 'Orders');
 *     tpl::fetch('order/list');        // template/order/list.php
 *
 * There is no compilation step, no cache directory and no third-party package. This driver exists
 * for two reasons. One is that an application rendering a handful of pages should not have to
 * install a template engine to do it. The other is that a contract with a single implementation is
 * a guess about what varies; the Smarty driver and this one disagree about compilation, delimiters,
 * plugins and caching, and what survived that disagreement is the five calls in plato\view\engine.
 *
 * **Nothing is escaped for you.** Smarty escapes plain variables by default; PHP does not, and a
 * driver that silently escaped on the way into `include` would corrupt every value a template
 * deliberately emits as markup. Templates call `$this->e()`, which is the same
 * `htmlspecialchars` the framework uses everywhere else:
 *
 *     <h1><?= $this->e($title) ?></h1>
 *
 * The methods a template may call on `$this` are e(), fetch() and exists(); fetch() is how a
 * template renders a partial, and it sees the same assigned variables.
 */
class native implements engine
{
    /**
     * Settings this driver understands.
     *
     * `template_dir` empty means "work it out from the application paths". `extension` is the
     * suffix appended to a template name that does not already carry one.
     *
     * @var array<string, mixed>
     */
    public const DEFAULT_CONFIG = [
        'template_dir' => '',
        'extension'    => '.php',
    ];

    /**
     * @var array<string, mixed>
     */
    private $_config = self::DEFAULT_CONFIG;

    /**
     * Assigned variables, extracted into the scope of every template this driver renders.
     *
     * @var array<string, mixed>
     */
    private $_vars = [];

    /**
     * Whether the ambient variables have been added to $_vars yet.
     *
     * They are added on the first call that renders rather than in the constructor, because two of
     * the three read request state that does not exist while the driver is being configured.
     *
     * @var bool
     */
    private $_defaults_assigned = false;

    /**
     * Replace the settings, and take the assigned variables with them.
     *
     * This driver has nothing built from its settings to drop -- there is no engine behind it --
     * but the contract says the variables do not survive a reconfiguration, and a driver deciding
     * that for itself is how two engines end up disagreeing about what configure() means.
     *
     * @param array<string, mixed> $config
     *
     * @return void
     */
    public function configure(array $config): void
    {
        $this->_config = $config + self::DEFAULT_CONFIG;

        $this->clear();
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
     * @param array<string, mixed>|string $tpl_var
     * @param mixed                       $value
     *
     * @return void
     */
    public function assign($tpl_var, $value = null): void
    {
        if ( is_array($tpl_var) )
        {
            $this->_vars = $tpl_var + $this->_vars;

            return;
        }

        $this->_vars[(string) $tpl_var] = $value;
    }

    /**
     * @param string $tpl
     *
     * @return bool
     */
    public function exists(string $tpl): bool
    {
        $path = $this->_resolve($tpl);

        return $path !== null && is_file($path);
    }

    /**
     * Render a template and answer with its output.
     *
     * @param string $tpl
     *
     * @return string
     *
     * @throws \RuntimeException When the name does not resolve to a readable file
     */
    public function fetch(string $tpl): string
    {
        $path = $this->_resolve($tpl);

        if ( $path === null || !is_file($path) )
        {
            throw new \RuntimeException(
                'plato\view\native cannot render "' . $tpl . '": no such template under '
                . $this->_template_dir()
            );
        }

        $this->_assign_defaults();

        return $this->_render($path);
    }

    /**
     * @return void
     */
    public function clear(): void
    {
        $this->_vars              = [];
        $this->_defaults_assigned = false;
    }

    /**
     * Escape a value for HTML text or for a quoted attribute.
     *
     * @param mixed $value
     *
     * @return string
     */
    public function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Include the file with the assigned variables in scope and capture what it printed.
     *
     * The include happens inside a closure so that the template cannot reach this method's own
     * locals, and EXTR_SKIP keeps an assigned variable from overwriting the path halfway through
     * resolving it. `$this` stays bound, which is what makes $this->e() and a nested $this->fetch()
     * available to the template.
     *
     * A template that throws leaves the buffer behind, so it is discarded before the throwable
     * continues: a half-rendered page appended to whatever the error handler writes is worse than
     * no page at all.
     *
     * @param string $path
     *
     * @return string
     */
    private function _render(string $path): string
    {
        $render = function () use ($path)
        {
            extract($this->_vars, EXTR_SKIP);

            include $path;
        };

        ob_start();

        try
        {
            $render();
        }
        catch ( \Throwable $e )
        {
            ob_end_clean();

            throw $e;
        }

        return (string) ob_get_clean();
    }

    /**
     * Turn a template name into an absolute path inside the template directory.
     *
     * Backslashes become separators so that a name written either way resolves, and the result has
     * to still be under the template directory once realpath() has resolved it: a name assembled
     * from request input is otherwise a way to include an arbitrary PHP file.
     *
     * @param string $tpl
     *
     * @return string|null  Null when the name escapes the template directory
     */
    private function _resolve(string $tpl): ?string
    {
        $name = str_replace('\\', '/', trim($tpl));

        if ( $name === '' )
        {
            return null;
        }

        $extension = (string) $this->_config['extension'];

        if ( $extension !== '' && substr($name, -strlen($extension)) !== $extension )
        {
            $name .= $extension;
        }

        $dir  = $this->_template_dir();
        $path = $dir . DIRECTORY_SEPARATOR . ltrim($name, '/');
        $real = realpath($path);

        if ( $real === false )
        {
            // Not there yet is not the same as out of bounds: exists() has to be able to say false
            // for a template nobody has written, and fetch() reports the missing file itself
            return str_contains($name, '..') ? null : $path;
        }

        $root = realpath($dir);

        return $root !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR) ? $real : null;
    }

    /**
     * @return string
     */
    private function _template_dir(): string
    {
        $configured = trim((string) ($this->_config['template_dir'] ?? ''));

        return rtrim($configured !== '' ? $configured : plato::app_path('template'), DIRECTORY_SEPARATOR);
    }

    /**
     * The variables every template can count on, matching what the Smarty driver assigns.
     *
     * They go in behind whatever the application assigned, so a controller that assigns its own
     * `app_name` keeps it.
     *
     * @return void
     */
    private function _assign_defaults(): void
    {
        if ( $this->_defaults_assigned )
        {
            return;
        }

        $this->_defaults_assigned = true;

        $this->_vars += [
            'app_name' => $_ENV['APP_NAME'] ?? 'platoapp',
            'request'  => req::$forms,
            /* Cache buster appended to asset urls, `<img src="a.png<?= $clear_cache ?>">` */
            'clear_cache' => '?' . time(),
        ];
    }
}
