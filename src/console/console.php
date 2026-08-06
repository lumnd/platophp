<?php

/**
 * Console kernel: parses argv, boots the framework, dispatches to a command
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\console;

use plato\cli;
use plato\config;
use plato\plato;
use Throwable;

/**
 * The half of bin/plato that can be a class.
 *
 * bin/plato itself only finds composer's autoloader and works out where the project root is --
 * neither of which can live in an autoloaded class -- and hands the rest to run(). What is left
 * here is worth having in the package rather than in a script: option parsing, the paths,
 * bootstrapping, the command registry and the help text, all of which an application extends and a
 * test can drive.
 *
 * Order of business, and none of it is arbitrary:
 *
 *   1. parse argv, so --app-path is known before anything reads a path;
 *   2. register the built-in commands, then the ones plato.config.php names;
 *   3. answer --help / help / an unknown name, none of which need a framework;
 *   4. check the paths the chosen command declared through requires(), because
 *      plato::registry() creates the log and cache directories and a run that is going to fail
 *      on a missing path should not leave those behind;
 *   5. plato::registry(), then register the commands config/config.php names -- the application
 *      overlay is not known until registry() has established app_path, while every command class
 *      is already resolvable through the host's Composer autoloader;
 *   6. dispatch, and turn anything thrown into an exit code.
 *
 * Where a path comes from, most specific first: a --option, a PLATO_ prefixed environment
 * variable, plato.config.php in the project root, then the convention.
 */
class console
{
    /**
     * Exit code of a command that did what it was asked
     */
    public const OK = 0;

    /**
     * Exit code of anything else: a bad option, a missing directory, a thrown exception
     */
    public const FAILURE = 1;

    /**
     * Commands the package ships
     *
     * @var array<int, class-string<command>>
     */
    private const BUILTIN = [
        migrate::class,
        make::class,
        queue::class,
        schedule::class,
        seed::class,
    ];

    /**
     * Command name => class answering it
     *
     * @var array<string, class-string<command>>
     */
    private static $_commands = [];

    /**
     * Command name => the line the help shows, in registration order
     *
     * @var array<string, string>
     */
    private static $_describe = [];

    /**
     * Long options, --name=value and --flag, with dashes normalised to underscores
     *
     * @var array<string, string|bool>
     */
    private static $_options = [];

    /**
     * Everything that is not an option, the command name first
     *
     * @var array<int, string>
     */
    private static $_arguments = [];

    /**
     * The resolved paths, keyed as the options are
     *
     * @var array<string, string>
     */
    private static $_paths = [];

    /**
     * Project root: the directory holding vendor/
     *
     * @var string
     */
    private static $_root = '';

    /**
     * Contents of plato.config.php, empty when the project has none
     *
     * @var array<string, mixed>
     */
    private static $_file_config = [];

    /**
     * Run the console.
     *
     * @param array<int, string> $argv Raw arguments, script name included
     * @param string             $root Project root: the directory holding vendor/
     *
     * @return int  Exit code
     */
    public static function run(array $argv, string $root): int
    {
        self::$_root = rtrim($root, DIRECTORY_SEPARATOR);

        try
        {
            // Reads $_SERVER['argv'] for cli::option() and opens the standard streams; refuses to
            // run outside the cli SAPI, which is the check this entry point wants anyway
            cli::boot();
        }
        catch ( Throwable $e )
        {
            fwrite(STDERR, 'plato: ' . $e->getMessage() . PHP_EOL);

            return self::FAILURE;
        }

        self::input($argv);

        foreach ( self::BUILTIN as $class )
        {
            self::register($class);
        }

        self::$_file_config = self::_file_config();

        foreach ( (array) (self::$_file_config['commands'] ?? []) as $class )
        {
            self::register((string) $class);
        }

        $name = self::$_arguments[0] ?? '';

        if ( $name === '' || in_array($name, ['help', 'list', '--help', '-h'], true) )
        {
            return self::_help(self::$_arguments[1] ?? '');
        }

        if ( isset(self::$_options['help']) )
        {
            return self::_help($name);
        }

        if ( !isset(self::$_commands[$name]) )
        {
            self::fail('Unknown command: ' . $name);
            self::fail('Run `php plato --help` for the commands there are.');

            return self::FAILURE;
        }

        $class = self::$_commands[$name];

        self::_resolve_paths();

        // app_path first: it is what registry() needs, so a wrong one is the more fundamental
        // mistake and reporting the command's own paths ahead of it only buries it
        if ( !self::_check_path('app_path') )
        {
            return self::FAILURE;
        }

        foreach ( $class::requires() as $key )
        {
            if ( !self::_check_path((string) $key) )
            {
                return self::FAILURE;
            }
        }

        try
        {
            self::_bootstrap();
        }
        catch ( Throwable $e )
        {
            self::fail('Bootstrap failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        try
        {
            return $class::handle($name);
        }
        catch ( Throwable $e )
        {
            self::fail($name . ' failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Add a command.
     *
     * A name already taken is overwritten, so an application can replace a built-in with its own
     * by registering a class that answers to the same name.
     *
     * @param string $class Class implementing command
     *
     * @return void
     */
    public static function register(string $class): void
    {
        if ( !is_a($class, command::class, true) )
        {
            self::fail(sprintf('%s is not a %s and was not registered', $class, command::class));

            return;
        }

        foreach ( $class::names() as $name => $describe )
        {
            // 'make:migration NAME' registers as 'make:migration': the placeholder is help text
            $key = trim(explode(' ', (string) $name, 2)[0]);

            if ( $key === '' )
            {
                continue;
            }

            self::$_commands[$key]    = $class;
            self::$_describe[$name]   = (string) $describe;
        }
    }

    /**
     * Every registered command, as the help lists them.
     *
     * @return array<string, string>  Name, argument placeholders included => description
     */
    public static function commands(): array
    {
        return self::$_describe;
    }

    /**
     * A long option: --queue=emails is option('queue').
     *
     * Dashes and underscores are the same thing here, so --migration-path and --migration_path both
     * answer to 'migration_path'.
     *
     * @param string $name    Option name, without the leading dashes
     * @param mixed  $default Returned when the option was not given
     *
     * @return mixed  True for a flag that carries no value
     */
    public static function option(string $name, $default = null)
    {
        $key = str_replace('-', '_', $name);

        return self::$_options[$key] ?? $default;
    }

    /**
     * A positional argument. Position 0 is the command name, so the first real argument is 1.
     *
     * @param int   $position Position, 0 being the command name
     * @param mixed $default  Returned when there is nothing at that position
     *
     * @return mixed
     */
    public static function argument(int $position, $default = null)
    {
        return self::$_arguments[$position] ?? $default;
    }

    /**
     * Every positional argument, the command name first.
     *
     * @return array<int, string>
     */
    public static function arguments(): array
    {
        return self::$_arguments;
    }

    /**
     * One of the resolved paths: app_path, data_path, env_path, migration_path, seeder_path.
     *
     * @param string $key Path key
     *
     * @return string  Empty when the key is not one of the four, or when data_path was left to
     *                 plato::registry() to derive
     */
    public static function path(string $key): string
    {
        if ( !self::$_paths )
        {
            self::_resolve_paths();
        }

        return (string) (self::$_paths[str_replace('-', '_', $key)] ?? '');
    }

    /**
     * The project root: the directory holding vendor/.
     *
     * @return string
     */
    public static function root(): string
    {
        return self::$_root;
    }

    /**
     * Write a line to STDOUT.
     *
     * @param string $text Text
     *
     * @return void
     */
    public static function line(string $text = ''): void
    {
        cli::write($text);
    }

    /**
     * Write a line to STDOUT in green: something was done.
     *
     * @param string $text Text
     *
     * @return void
     */
    public static function success(string $text): void
    {
        cli::write($text, 'light_green');
    }

    /**
     * Write a line to STDOUT in yellow: nothing was done, and that is fine.
     *
     * @param string $text Text
     *
     * @return void
     */
    public static function warn(string $text): void
    {
        cli::write($text, 'light_yellow');
    }

    /**
     * Write a line to STDERR.
     *
     * Not cli::error(), which colours the line and still sends it to STDOUT: a caller redirecting
     * one stream and not the other has to find this there.
     *
     * @param string $text Text
     *
     * @return void
     */
    public static function fail(string $text): void
    {
        $handle = cli::stderr();

        if ( is_resource($handle) )
        {
            fwrite($handle, $text . PHP_EOL);

            return;
        }

        if ( defined('STDERR') && is_resource(STDERR) )
        {
            fwrite(STDERR, $text . PHP_EOL);

            return;
        }

        error_log($text);
    }

    /**
     * Split argv into options and positional arguments.
     *
     * Options are taken out rather than counted, so `plato make:migration name --force` and
     * `plato --force make:migration name` put the same thing at position 1.
     *
     * Public because run() is not the only way in: something embedding the console, and a test
     * exercising one command::handle() without the bootstrap, both need to say what the command
     * line was. It replaces whatever was parsed before rather than adding to it.
     *
     * @param array<int, string> $argv Raw arguments, script name at position 0
     *
     * @return void
     */
    public static function input(array $argv): void
    {
        self::$_options   = [];
        self::$_arguments = [];

        foreach ( array_slice($argv, 1) as $arg )
        {
            if ( preg_match('/^--([a-zA-Z][a-zA-Z0-9_-]*)(?:=(.*))?$/', $arg, $m) )
            {
                self::$_options[str_replace('-', '_', $m[1])] = $m[2] ?? true;

                continue;
            }

            if ( $arg === '-h' )
            {
                self::$_options['help'] = true;

                continue;
            }

            self::$_arguments[] = $arg;
        }
    }

    /**
     * Read plato.config.php from the project root.
     *
     * The same file registry() takes its configuration from, so a project states app_path once and
     * both the web entry point and this one read it.
     *
     * @return array<string, mixed>
     */
    private static function _file_config(): array
    {
        $file = self::$_root . DIRECTORY_SEPARATOR . 'plato.config.php';

        if ( !is_file($file) )
        {
            return [];
        }

        return (array) require $file;
    }

    /**
     * Work out the paths, most specific source first.
     *
     * @return void
     */
    private static function _resolve_paths(): void
    {
        $defaults = [
            'app_path'       => self::$_root . DIRECTORY_SEPARATOR . 'app',
            'env_path'       => self::$_root . DIRECTORY_SEPARATOR . '.env',
            'migration_path' => self::$_root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations',
            'seeder_path'    => self::$_root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'seeders',
            // Left empty on purpose: registry() falls back to <app_path>/data, and repeating that
            // here would be a second place to change it
            'data_path'      => '',
        ];

        foreach ( $defaults as $key => $default )
        {
            $value = self::option($key);

            if ( !is_string($value) || $value === '' )
            {
                $value = $_SERVER['PLATO_' . strtoupper($key)]
                    ?? self::$_file_config[$key]
                    ?? $default;
            }

            self::$_paths[$key] = (string) $value;
        }
    }

    /**
     * Check that a resolved path exists, saying how to point it somewhere else when it does not.
     *
     * @param string $key Path key
     *
     * @return bool
     */
    private static function _check_path(string $key): bool
    {
        $path = self::path($key);

        // env_path is allowed to be absent: a project may keep every setting in the environment,
        // and registry() ignores a .env that is not there
        if ( $path === '' || $key === 'env_path' || is_dir($path) )
        {
            return true;
        }

        self::fail(sprintf('%s does not exist: %s', self::_path_label($key), $path));
        self::fail(sprintf(
            'Point it somewhere with --%s=DIR, or set %s in %s.',
            str_replace('_', '-', $key),
            $key,
            self::$_root . DIRECTORY_SEPARATOR . 'plato.config.php'
        ));

        return false;
    }

    /**
     * How a path is named in an error message.
     *
     * @param string $key Path key
     *
     * @return string
     */
    private static function _path_label(string $key): string
    {
        $labels = [
            'app_path'       => 'Application directory',
            'data_path'      => 'Data directory',
            'env_path'       => 'Environment file',
            'migration_path' => 'Migration directory',
        ];

        return $labels[$key] ?? $key;
    }

    /**
     * Boot the framework with the resolved paths, then register the commands the application
     * configuration names.
     *
     * @return void
     */
    private static function _bootstrap(): void
    {
        $registry = self::$_file_config;

        // Not registry() settings: one is this class's own, the other is resolved separately
        unset($registry['migration_path'], $registry['commands']);

        $registry['app_path'] = self::path('app_path');
        $registry['env_path'] = self::path('env_path');

        if ( self::path('data_path') !== '' )
        {
            $registry['data_path'] = self::path('data_path');
        }

        plato::registry($registry);

        $console = (array) config::instance('config')->get('console');

        foreach ( (array) ($console['commands'] ?? []) as $class )
        {
            self::register((string) $class);
        }
    }

    /**
     * Print the help, for everything or for one command.
     *
     * @param string $name Command to describe, empty for the full list
     *
     * @return int
     */
    private static function _help(string $name): int
    {
        if ( $name !== '' && isset(self::$_commands[$name]) )
        {
            return self::_command_help($name);
        }

        if ( $name !== '' )
        {
            self::fail('Unknown command: ' . $name);

            return self::FAILURE;
        }

        cli::write('PlatoPHP console', 'light_white');
        cli::write('');
        cli::write('Usage:');
        cli::write('  php plato <command> [arguments] [options]');
        cli::write('');
        cli::write('Commands:');

        foreach ( self::$_describe as $command => $describe )
        {
            cli::write(sprintf('  %-26s %s', $command, $describe));
        }

        cli::write('');
        cli::write('Options (an uppercase PLATO_ prefixed environment variable works too, PLATO_APP_PATH):');
        cli::write('  --app-path=DIR         Application root, default <project>/app');
        cli::write('  --data-path=DIR        Writable runtime directory, default <app-path>/data');
        cli::write('  --env-path=FILE        .env file, default <project>/.env');
        cli::write('  --migration-path=DIR   Migration directory, default <project>/database/migrations');
        cli::write('  --help                 Show this text, or what a single command takes');
        cli::write('');
        cli::write('Run `php plato help <command>` for one command on its own.');

        return self::OK;
    }

    /**
     * Print what one command is and what it takes.
     *
     * @param string $name Command name
     *
     * @return int
     */
    private static function _command_help(string $name): int
    {
        $class    = self::$_commands[$name];
        $describe = '';

        foreach ( self::$_describe as $command => $text )
        {
            if ( strtok($command, ' ') === $name )
            {
                $describe = $text;
                cli::write('Usage:', 'light_white');
                cli::write('  php plato ' . $command);
                break;
            }
        }

        if ( $describe !== '' )
        {
            cli::write('');
            cli::write($describe);
        }

        $usage = $class::usage($name);

        if ( $usage !== '' )
        {
            cli::write('');
            cli::write('Options:');
            cli::write($usage);
        }

        return self::OK;
    }
}
