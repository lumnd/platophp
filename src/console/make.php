<?php

/**
 * Console command: write the boilerplate of a migration, controller, middleware or command
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\console;

use plato\plato;

/**
 * The `make:` verbs: one file each, from a stub held in this class.
 *
 * The stubs are strings and not files under a stubs/ directory, on purpose: this package is
 * installed into somebody else's vendor/, where a template nobody can edit is only a file to keep
 * in sync with the code that fills it.
 *
 * Nothing is ever overwritten. A generator that clobbers is a generator that eats an afternoon's
 * work the first time a name is reused, so an existing target is an error with the path in it,
 * and --force is deliberately not a thing.
 *
 * Where each kind lands, and why:
 *
 *   migration    <migration_path>/<Ymd_His>_<name>.php, the file name plato\database\migrator
 *                requires -- \d{8}_\d{6}_[a-z][a-z0-9_]*. The directory is created when it is
 *                missing, which is what a project's first migration needs
 *   seeder       <seeder_path>/<name>.php, with no timestamp: a seeder is addressed by name, both
 *                by db:seed --class and by another seeder's call()
 *   controller   <app_path>/control/ctl_<name>.php, the class plato::run() resolves for ct=<name>,
 *                in the namespace the controller_namespace configuration names
 *   middleware   <app_path>/middleware/<name>.php, ready to be listed in config/config.php
 *   command      <app_path>/command/<name>.php, ready to be listed under console.commands
 *
 * The host application must map these namespaces through Composer. This generator writes the
 * class file only; it does not mutate the host's composer.json.
 */
class make implements command
{
    /**
     * @return array<string, string>
     */
    public static function names(): array
    {
        return [
            'make:migration NAME'  => 'Write a migration file, timestamped',
            'make:seeder NAME'     => 'Write a seeder file',
            'make:controller NAME' => 'Write a controller under the application control directory',
            'make:middleware NAME' => 'Write a middleware class',
            'make:command NAME'    => 'Write a console command class',
        ];
    }

    /**
     * @param string $name
     *
     * @return string
     */
    public static function usage(string $name): string
    {
        if ( $name === 'make:migration' )
        {
            return '  --migration-path=DIR   Directory to write into, created when missing';
        }

        if ( $name === 'make:seeder' )
        {
            return '  --seeder-path=DIR      Directory to write into, created when missing';
        }

        return '  --app-path=DIR         Application root the file is written under';
    }

    /**
     * @return array<int, string>
     */
    public static function requires(): array
    {
        // Nothing: make:migration creates its directory, and the others are under app_path, which
        // the kernel checks for every command
        return [];
    }

    /**
     * @param string $name
     *
     * @return int
     */
    public static function handle(string $name): int
    {
        $subject = (string) console::argument(1, '');

        if ( $subject === '' )
        {
            console::fail('A name is needed: php plato ' . $name . ' NAME');

            return console::FAILURE;
        }

        $subject = self::_normalise($subject);

        if ( $subject === '' )
        {
            console::fail('A name may hold letters, digits and underscores, and has to start with a letter.');

            return console::FAILURE;
        }

        switch ( $name )
        {
            case 'make:migration':
                return self::_migration($subject);

            case 'make:seeder':
                return self::_seeder($subject);

            case 'make:controller':
                return self::_controller($subject);

            case 'make:middleware':
                return self::_middleware($subject);

            default:
                return self::_command($subject);
        }
    }

    /**
     * Write a migration file.
     *
     * @param string $subject Migration name, snake case
     *
     * @return int
     */
    private static function _migration(string $subject): int
    {
        $dir = console::path('migration_path');

        if ( !is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir) )
        {
            console::fail('Cannot create the migration directory: ' . $dir);

            return console::FAILURE;
        }

        $file = $dir . DIRECTORY_SEPARATOR . date('Ymd_His') . '_' . $subject . '.php';

        return self::_write($file, self::_migration_stub($subject));
    }

    /**
     * Write a seeder file.
     *
     * The name is the file name, with no timestamp: a seeder is called by name from another one
     * and run again whenever somebody asks, so a name that moves with the clock is useless.
     *
     * @param string $subject Seeder name, snake case
     *
     * @return int
     */
    private static function _seeder(string $subject): int
    {
        $dir = console::path('seeder_path');

        if ( !is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir) )
        {
            console::fail('Cannot create the seeder directory: ' . $dir);

            return console::FAILURE;
        }

        return self::_write($dir . DIRECTORY_SEPARATOR . $subject . '.php', self::_seeder_stub($subject));
    }

    /**
     * Write a controller.
     *
     * @param string $subject Controller name, without the ctl_ prefix
     *
     * @return int
     */
    private static function _controller(string $subject): int
    {
        $subject = preg_replace('/^ctl_/', '', $subject) ?? $subject;
        $file    = plato::app_path('control') . DIRECTORY_SEPARATOR . 'ctl_' . $subject . '.php';

        return self::_write($file, self::_controller_stub($subject));
    }

    /**
     * Write a middleware class.
     *
     * @param string $subject Class name
     *
     * @return int
     */
    private static function _middleware(string $subject): int
    {
        $file = plato::app_path('middleware') . DIRECTORY_SEPARATOR . $subject . '.php';

        return self::_write($file, self::_middleware_stub($subject));
    }

    /**
     * Write a console command class.
     *
     * @param string $subject Class name
     *
     * @return int
     */
    private static function _command(string $subject): int
    {
        $file = plato::app_path('command') . DIRECTORY_SEPARATOR . $subject . '.php';

        return self::_write($file, self::_command_stub($subject));
    }

    /**
     * Create the file, refusing to touch one that is already there.
     *
     * @param string $file     Target
     * @param string $contents What to put in it
     *
     * @return int
     */
    private static function _write(string $file, string $contents): int
    {
        if ( file_exists($file) )
        {
            console::fail('Already there, nothing written: ' . $file);

            return console::FAILURE;
        }

        $dir = dirname($file);

        if ( !is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir) )
        {
            console::fail('Cannot create the directory: ' . $dir);

            return console::FAILURE;
        }

        if ( file_put_contents($file, $contents) === false )
        {
            console::fail('Cannot write: ' . $file);

            return console::FAILURE;
        }

        console::success('Written: ' . $file);

        return console::OK;
    }

    /**
     * Reduce a name to what the conventions accept, or to '' when nothing usable is left.
     *
     * CamelCase is folded to snake_case rather than refused: `make:controller UserProfile` is what
     * somebody coming from another framework types, and the answer to it is a file, not a lecture.
     *
     * @param string $subject Name as it was typed
     *
     * @return string
     */
    private static function _normalise(string $subject): string
    {
        $subject = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $subject);
        $subject = strtolower((string) preg_replace('/[^A-Za-z0-9_]+/', '_', $subject));
        $subject = trim($subject, '_');

        return preg_match('/^[a-z][a-z0-9_]*$/', $subject) ? $subject : '';
    }

    /**
     * @param string $subject
     *
     * @return string
     */
    private static function _seeder_stub(string $subject): string
    {
        return <<<PHP
<?php

/**
 * {$subject}
 */

use plato\\database\\db;
use plato\\database\\seeder;

return new class extends seeder
{
    /**
     * Write the data.
     *
     * Nothing records that this ran, so it has to survive running twice -- upsert rather than
     * insert. Data that has to change exactly once is a migration, not a seeder.
     *
     * @return \plato\http\reply
     */
    public function run(): void
    {
        // \$this->call(['other_seeder']);

        // db::table('#PB#{$subject}')->upsert([
        //     ['code' => 'first', 'label' => 'First'],
        // ], ['label']);
    }
};

PHP;
    }

    /**
     * @param string $subject
     *
     * @return string
     */
    private static function _migration_stub(string $subject): string
    {
        return <<<PHP
<?php

/**
 * {$subject}
 */

use plato\\database\\blueprint;
use plato\\database\\migration;
use plato\\database\\schema;

return new class extends migration
{
    /**
     * Apply the change.
     *
     * @return void
     */
    public function up(): void
    {
        // #PB# is replaced with the configured table prefix
        schema::create('#PB#{$subject}', function (blueprint \$table)
        {
            \$table->id();
            \$table->timestamps();
        });
    }

    /**
     * Undo what up() did.
     *
     * @return void
     */
    public function down(): void
    {
        schema::drop_if_exists('#PB#{$subject}');
    }
};

PHP;
    }

    /**
     * @param string $subject
     *
     * @return string
     */
    private static function _controller_stub(string $subject): string
    {
        // The namespace plato::run() will look the ct up in, so a repository that gives each of
        // its applications a namespace of its own gets a stub that is already in the right one
        $namespace   = trim((string) plato::config('controller_namespace', 'control'), '\\');
        $declaration = $namespace === '' ? '' : "namespace {$namespace};\n\n";

        return <<<PHP
<?php

{$declaration}use plato\\http\\resp;

/**
 * ct={$subject}
 */
class ctl_{$subject}
{
    /**
     * Actions this controller answers to, the http methods each accepts, and how each authenticates.
     *
     * Declaring them is what keeps a public helper from being reachable as an action; '*' accepts
     * any method. auth is 'required' (check_purview_handle must produce an identity, or the
     * request is answered 401), 'optional' (it runs and may find nobody) or 'none' (it is not
     * called). Remove the property and every public non static method becomes an action -- one
     * that requires authentication, because an undeclared action has said nothing about who may
     * reach it.
     *
     * Scaffolded as 'optional' so a new controller runs before an authentication callback is
     * wired up. Anything that must not be reached by a signed out visitor says 'required'.
     *
     * @var array<string, array{methods: list<string>, auth: string}>
     */
    public static \$actions = [
        'index' => [
            'methods' => ['GET'],
            'auth'    => 'optional',
        ],
    ];

    /**
     * ac=index
     *
     * @return void
     */
    public function index()
    {
        return resp::response(0, [], 'ok');
    }
}

PHP;
    }

    /**
     * @param string $subject
     *
     * @return string
     */
    private static function _middleware_stub(string $subject): string
    {
        return <<<PHP
<?php

namespace middleware;

/**
 * Middleware: list it under `middleware` in config/config.php, keyed by the routes it applies to.
 */
class {$subject}
{
    /**
     * Run around the action.
     *
     * Call \$next to let the request through; returning without calling it stops the request here,
     * which is how a middleware refuses one -- write the answer first, through plato\\http\\resp.
     *
     * @param callable \$next The rest of the pipeline, the action last
     *
     * @return mixed
     */
    public function handle(callable \$next)
    {
        // before the action

        \$result = \$next();

        // after the action

        return \$result;
    }
}

PHP;
    }

    /**
     * @param string $subject
     *
     * @return string
     */
    private static function _command_stub(string $subject): string
    {
        return <<<PHP
<?php

namespace command;

use plato\\console\\command;
use plato\\console\\console;

/**
 * Console command: list it under `console.commands` in config/config.php, or `commands` in
 * plato.config.php, and run it with `php plato {$subject}`.
 */
class {$subject} implements command
{
    /**
     * @return array<string, string>
     */
    public static function names(): array
    {
        return [
            '{$subject}' => 'What this command does, in one line',
        ];
    }

    /**
     * @param string \$name
     *
     * @return string
     */
    public static function usage(string \$name): string
    {
        return '';
    }

    /**
     * @return array<int, string>
     */
    public static function requires(): array
    {
        return [];
    }

    /**
     * @param string \$name
     *
     * @return int
     */
    public static function handle(string \$name): int
    {
        console::line('{$subject} ran');

        return console::OK;
    }
}

PHP;
    }
}
