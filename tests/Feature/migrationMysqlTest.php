<?php
/**
 * The migration toolchain against the real MySQL service.
 *
 * tests/Feature/migrationCliTest.php covers bin/plato as an entry point -- its bootstrap, its path
 * options, the file make:migration writes. Everything there stops short of a server, which left
 * `migrate`, `migrate:rollback`, `db:seed` and the schema builder asserted only on the SQL they
 * compile. This file runs them.
 *
 * Every verb goes through bin/plato as a subprocess, because that is what a deploy runs and the
 * console's own bootstrap is part of what has to work. What the subprocess did is then read back
 * through plato\database\schema and plato\database\db from the test process, which is the schema
 * inspection half of the same coverage: has_table() and has_column() are answered by the server,
 * not by the blueprint that asked for the column.
 *
 * The migration and seeder files are written per test into the process's temporary runtime
 * directory. Nothing is read from or written to the repository tree, and the tables are dropped in
 * afterEach whether the case passed or not.
 *
 * An unreachable MySQL fails these cases, and is meant to: `composer test:feature` requires the
 * service, and a suite that goes green without one cannot be the thing that proves a release
 * migrates.
 */

use plato\database\db;
use plato\database\schema;

/** Repository table of these runs, kept away from whatever else the schema holds. */
const MIGRATION_MYSQL_REPOSITORY = '#PB#_ci_migrations';

/** Tables the fixture migrations create, in the order they have to be dropped. */
const MIGRATION_MYSQL_TABLES = ['#PB#_ci_orders', '#PB#_ci_order_lines'];

/**
 * Directory the fixture migration files are written to, one per process.
 *
 * @return string
 */
function migration_mysql_dir(): string
{
    return plato_test_data() . DIRECTORY_SEPARATOR . 'ci_migrations';
}

/**
 * Directory the fixture seeder files are written to.
 *
 * @return string
 */
function migration_mysql_seeder_dir(): string
{
    return plato_test_data() . DIRECTORY_SEPARATOR . 'ci_seeders';
}

/**
 * Run bin/plato against the fixture application and the temporary migration directory.
 *
 * @param array<int, string> $args Command name first, then any extra options
 *
 * @return array{0: int, 1: string}  Exit status, combined output
 */
function migration_mysql_cli(array $args): array
{
    $root = dirname(__DIR__, 2);

    $args = array_merge($args, [
        '--app-path=' . plato_test_app(),
        '--data-path=' . plato_test_data(),
        '--env-path=' . plato_test_app('.env.testing'),
        '--migration-path=' . migration_mysql_dir(),
        '--seeder-path=' . migration_mysql_seeder_dir(),
        '--table=' . MIGRATION_MYSQL_REPOSITORY,
    ]);

    $command = escapeshellarg(PHP_BINARY)
        . ' '
        . escapeshellarg($root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'plato');

    foreach ( $args as $arg )
    {
        $command .= ' ' . escapeshellarg($arg);
    }

    exec($command . ' 2>&1', $output, $status);

    return [$status, implode(PHP_EOL, $output)];
}

/**
 * Write one migration file.
 *
 * The name carries the ordering the migrator sorts on, so a case that wants two batches writes
 * the second file only after the first has been applied.
 *
 * @param string $name Timestamped file name without the extension
 * @param string $up   Body of up()
 * @param string $down Body of down()
 *
 * @return void
 */
function migration_mysql_file(string $name, string $up, string $down): void
{
    $dir = migration_mysql_dir();

    is_dir($dir) || mkdir($dir, 0777, true);

    file_put_contents($dir . DIRECTORY_SEPARATOR . $name . '.php', <<<PHP
<?php

use plato\database\blueprint;
use plato\database\migration;
use plato\database\schema;

return new class extends migration
{
    public function up(): void
    {
        {$up}
    }

    public function down(): void
    {
        {$down}
    }
};
PHP);
}

/**
 * The two fixture migrations: a table, then a second table altering the first.
 *
 * @return void
 */
function migration_mysql_first(): void
{
    migration_mysql_file(
        '20260101_000001_create_ci_orders_table',
        <<<'PHP'
schema::create('#PB#_ci_orders', function (blueprint $table) {
            $table->id();
            $table->string('code', 32);
            $table->boolean('paid');
            $table->timestamps();
            $table->unique('code');
        });
PHP,
        "schema::drop_if_exists('#PB#_ci_orders');"
    );
}

/**
 * @return void
 */
function migration_mysql_second(): void
{
    migration_mysql_file(
        '20260101_000002_create_ci_order_lines_table',
        <<<'PHP'
schema::create('#PB#_ci_order_lines', function (blueprint $table) {
            $table->id();
            $table->big_integer('order_id');
            $table->decimal('amount', 12, 2);
        });

        schema::table('#PB#_ci_orders', function (blueprint $table) {
            $table->string('note', 64);
        });
PHP,
        <<<'PHP'
schema::table('#PB#_ci_orders', function (blueprint $table) {
            $table->drop_column('note');
        });

        schema::drop_if_exists('#PB#_ci_order_lines');
PHP
    );
}

/**
 * Drop everything a case may have created, in either order of success.
 *
 * @return void
 */
function migration_mysql_clean(): void
{
    foreach ( array_merge(MIGRATION_MYSQL_TABLES, [MIGRATION_MYSQL_REPOSITORY]) as $table )
    {
        db::statement('DROP TABLE IF EXISTS `' . db::table_prefix($table) . '`');
    }
}

beforeEach(function () {
    migration_mysql_clean();

    plato_test_rmdir(migration_mysql_dir());
    plato_test_rmdir(migration_mysql_seeder_dir());

    migration_mysql_first();
});

afterEach(function () {
    migration_mysql_clean();

    plato_test_rmdir(migration_mysql_dir());
    plato_test_rmdir(migration_mysql_seeder_dir());
});

it('creates the table a migration describes, with the columns it declared', function () {
    [$status, $output] = migration_mysql_cli(['migrate']);

    expect($status)->toBe(0, $output)
        ->and($output)->toContain('migrated: 20260101_000001_create_ci_orders_table');

    // Answered by the server: the blueprint compiling the column is a different claim
    expect(schema::has_table('#PB#_ci_orders'))->toBeTrue()
        ->and(schema::has_column('#PB#_ci_orders', 'code'))->toBeTrue()
        ->and(schema::has_column('#PB#_ci_orders', 'paid'))->toBeTrue()
        ->and(schema::has_column('#PB#_ci_orders', 'created_at'))->toBeTrue()
        ->and(schema::has_column('#PB#_ci_orders', 'no_such_column'))->toBeFalse();
});

it('reports a migration as pending before it runs and as ran after', function () {
    [$status, $before] = migration_mysql_cli(['migrate:status']);

    expect($status)->toBe(0, $before)
        ->and($before)->toContain('pending')
        ->and($before)->toContain('20260101_000001_create_ci_orders_table');

    migration_mysql_cli(['migrate']);

    [$status, $after] = migration_mysql_cli(['migrate:status']);

    expect($status)->toBe(0, $after)
        ->and($after)->toContain('ran')
        ->and($after)->toContain('batch=1');
});

it('applies nothing on a second run', function () {
    migration_mysql_cli(['migrate']);

    [$status, $output] = migration_mysql_cli(['migrate']);

    expect($status)->toBe(0, $output)
        ->and($output)->toContain('Nothing to migrate.');
});

it('rolls the last batch back, undoing what it did to the schema', function () {
    migration_mysql_cli(['migrate']);

    migration_mysql_second();

    [$status, $output] = migration_mysql_cli(['migrate']);

    expect($status)->toBe(0, $output)
        ->and($output)->toContain('migrated: 20260101_000002_create_ci_order_lines_table')
        // The second migration altered the first one's table as well as creating its own
        ->and(schema::has_table('#PB#_ci_order_lines'))->toBeTrue()
        ->and(schema::has_column('#PB#_ci_orders', 'note'))->toBeTrue();

    [$status, $output] = migration_mysql_cli(['migrate:rollback']);

    expect($status)->toBe(0, $output)
        ->and($output)->toContain('rolled back: 20260101_000002_create_ci_order_lines_table')
        ->and(schema::has_table('#PB#_ci_order_lines'))->toBeFalse()
        ->and(schema::has_column('#PB#_ci_orders', 'note'))->toBeFalse()
        // One batch per run, so the first migration is untouched by rolling the second back
        ->and(schema::has_table('#PB#_ci_orders'))->toBeTrue();
});

it('rolls back only the last batch, not every migration that ever ran', function () {
    migration_mysql_cli(['migrate']);
    migration_mysql_second();
    migration_mysql_cli(['migrate']);

    migration_mysql_cli(['migrate:rollback']);

    [$status, $output] = migration_mysql_cli(['migrate:status']);

    expect($status)->toBe(0, $output)
        ->and($output)->toMatch('/ran\s+batch=1\s+20260101_000001_create_ci_orders_table/')
        ->and($output)->toMatch('/pending\s+batch=-\s+20260101_000002_create_ci_order_lines_table/');
});

it('reports nothing to roll back once the ledger is empty', function () {
    migration_mysql_cli(['migrate']);
    migration_mysql_cli(['migrate:rollback']);

    [$status, $output] = migration_mysql_cli(['migrate:rollback']);

    expect($status)->toBe(0, $output)
        ->and($output)->toContain('Nothing to roll back.')
        ->and(schema::has_table('#PB#_ci_orders'))->toBeFalse();
});

it('writes the rows a seeder inserts, and survives being run twice', function () {
    migration_mysql_cli(['migrate']);

    $dir = migration_mysql_seeder_dir();
    is_dir($dir) || mkdir($dir, 0777, true);

    file_put_contents($dir . DIRECTORY_SEPARATOR . 'orders.php', <<<'PHP'
<?php

use plato\database\db;
use plato\database\seeder;

return new class extends seeder
{
    public function run(): void
    {
        db::table('#PB#_ci_orders')->upsert([
            ['code' => 'A-1', 'paid' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
            ['code' => 'A-2', 'paid' => 0, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
        ], ['paid']);
    }
};
PHP);

    [$status, $output] = migration_mysql_cli(['db:seed']);

    expect($status)->toBe(0, $output)
        ->and(db::table('#PB#_ci_orders')->count())->toBe(2);

    // Nothing records that a seeder ran, so running it again has to be harmless
    [$status, $output] = migration_mysql_cli(['db:seed']);

    expect($status)->toBe(0, $output)
        ->and(db::table('#PB#_ci_orders')->count())->toBe(2);
});

it('keeps its ledger in the table it was told to use', function () {
    migration_mysql_cli(['migrate']);

    expect(schema::has_table(MIGRATION_MYSQL_REPOSITORY))->toBeTrue()
        ->and(db::table(MIGRATION_MYSQL_REPOSITORY)->count())->toBe(1);
});

it('fails the run and leaves the migration pending when it throws half way through', function () {
    migration_mysql_cli(['migrate']);

    migration_mysql_file(
        '20260101_000003_broken',
        "schema::create('#PB#_ci_order_lines', function (blueprint \$table) { \$table->id(); });\n"
        . '        throw new RuntimeException(\'migration failed\');',
        "schema::drop_if_exists('#PB#_ci_order_lines');"
    );

    [$status, $output] = migration_mysql_cli(['migrate']);

    // A migration that throws has to fail the run rather than be recorded as applied
    expect($status)->not->toBe(0)
        ->and($output)->toContain('migration failed');

    [, $after] = migration_mysql_cli(['migrate:status']);

    expect($after)->toMatch('/pending\s+batch=-\s+20260101_000003_broken/')
        // What the failed migration already did to the schema is still there: the migrator runs
        // no transaction around a migration, and MySQL would commit the CREATE TABLE regardless.
        // Re-running the migration is the fix, so a migration has to tolerate its own leftovers
        ->and(schema::has_table('#PB#_ci_order_lines'))->toBeTrue();
});
