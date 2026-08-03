<?php

/**
 * Console command contract: what the console kernel needs from a command
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\console;

/**
 * One console command, or a group of related ones.
 *
 * A class owns a set of names rather than a single one, because the verbs of one subject share
 * everything but a line of dispatch: `migrate`, `migrate:status` and `migrate:rollback` are one
 * migrator built three ways. names() is what the kernel registers and what the help lists, and
 * handle() is told which of them was asked for.
 *
 * Every method is static because the console kernel holds a command class name
 * and calls through it, and a command that wants state has argv and the framework, both of which
 * are static as well.
 *
 * A command reports what it cannot run without through requires(), and the kernel checks that
 * before it boots the framework -- plato::registry() creates directories, so a run that is going
 * to fail on a missing path has to fail before it, not after.
 *
 * Register one from an application with console::register(), or by listing it under `commands` in
 * plato.config.php or `console.commands` in config/config.php.
 */
interface command
{
    /**
     * The names this class answers to, each with the one line the help shows.
     *
     * A name is `verb` or `subject:verb`, lower case, and may carry an argument placeholder in the
     * help -- 'make:migration NAME' is a name of 'make:migration'.
     *
     * @return array<string, string>  Command name => description
     */
    public static function names(): array;

    /**
     * The option block `php plato help <name>` prints under the description.
     *
     * @param string $name Command being asked about
     *
     * @return string  Empty when the command takes no options of its own
     */
    public static function usage(string $name): string;

    /**
     * Path keys this command cannot run without, checked before the framework boots.
     *
     * Keys are the ones console::path() answers to: app_path, data_path, env_path,
     * migration_path. A command that would rather create a missing directory than be stopped by
     * it says nothing here and does the creating itself.
     *
     * @return array<int, string>
     */
    public static function requires(): array;

    /**
     * Run the command.
     *
     * The framework is booted, the paths are resolved, and options are readable through
     * console::option(). Output goes through console::line() and its siblings, so that a caller
     * capturing it gets errors on STDERR and everything else on STDOUT.
     *
     * @param string $name Name the command was invoked under
     *
     * @return int  Process exit code: console::OK, or console::FAILURE for anything else
     */
    public static function handle(string $name): int;
}
