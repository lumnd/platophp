<?php
/**
 * plato\database\seeder: loading a seeder file and running it at most once.
 *
 * The files are written into the process's temporary runtime directory rather than into
 * tests/Fixtures, because what is being exercised is the loader and each case wants a different
 * call graph. Nothing here touches a database: run() is whatever the fixture file does.
 */

use plato\database\seeder;
use plato\plato;

plato::registry(plato_test_config());

/**
 * Directory the fixture seeders are written to.
 */
function seeder_dir(): string
{
    return plato_test_data() . DIRECTORY_SEPARATOR . 'seeders';
}

/**
 * Write a seeder file whose run() appends its name to $GLOBALS['seeder_ran'].
 *
 * @param string $name
 * @param string $body Extra PHP inside run(), after the record
 */
function seeder_file(string $name, string $body = ''): void
{
    is_dir(seeder_dir()) || mkdir(seeder_dir(), 0777, true);

    file_put_contents(seeder_dir() . DIRECTORY_SEPARATOR . $name . '.php', <<<PHP
<?php
return new class extends plato\database\seeder
{
    public function run(): void
    {
        \$GLOBALS['seeder_ran'][] = '{$name}';
        {$body}
    }
};
PHP);
}

beforeEach(function () {
    $GLOBALS['seeder_ran'] = [];
    seeder::locate(seeder_dir());
});

afterEach(function () {
    foreach ( (array) glob(seeder_dir() . DIRECTORY_SEPARATOR . '*.php') as $file )
    {
        @unlink((string) $file);
    }
});

it('loads a seeder file and runs it', function () {
    seeder_file('country');

    expect(seeder::run_file('country'))->toBeTrue()
        ->and($GLOBALS['seeder_ran'])->toBe(['country']);
});

it('runs the seeders another one calls, before the rest of its own work', function () {
    seeder_file('country');
    seeder_file('currency');
    seeder_file('base', "\$this->call(['country', 'currency']);");

    seeder::run_file('base');

    // base records itself first, then calls the other two
    expect($GLOBALS['seeder_ran'])->toBe(['base', 'country', 'currency']);
});

it('runs a seeder at most once per process', function () {
    seeder_file('country');

    seeder::run_file('country');

    expect(seeder::run_file('country'))->toBeFalse()
        ->and($GLOBALS['seeder_ran'])->toBe(['country']);
});

it('does not recurse when two seeders call each other', function () {
    seeder_file('a', "\$this->call('b');");
    seeder_file('b', "\$this->call('a');");

    seeder::run_file('a');

    expect($GLOBALS['seeder_ran'])->toBe(['a', 'b']);
});

it('reports which names have run', function () {
    seeder_file('country');
    seeder_file('currency');

    seeder::run_file('country');
    seeder::run_file('currency');

    expect(seeder::ran())->toBe(['country', 'currency']);
});

it('forgets what ran when it is pointed at a directory again', function () {
    seeder_file('country');

    seeder::run_file('country');
    seeder::locate(seeder_dir());

    expect(seeder::run_file('country'))->toBeTrue();
});

it('says where it looked when there is no such file', function () {
    seeder::run_file('missing');
})->throws(RuntimeException::class, 'no seeder file at');

it('refuses a file that returns something else', function () {
    is_dir(seeder_dir()) || mkdir(seeder_dir(), 0777, true);
    file_put_contents(seeder_dir() . DIRECTORY_SEPARATOR . 'wrong.php', '<?php return 1;');

    seeder::run_file('wrong');
})->throws(RuntimeException::class);
