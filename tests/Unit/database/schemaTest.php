<?php
/**
 * The schema builder, asserted on the SQL it compiles.
 *
 * No server: a blueprint plus a grammar is a pure function, and what a migration has to get right
 * is the statement. Running it belongs to tests/Feature/migrationCliTest.php.
 */

use plato\database\blueprint;
use plato\database\column;
use plato\database\db;
use plato\database\grammar;
use plato\database\grammar\clickhouse;

/**
 * Compile a blueprint with a given grammar.
 *
 * @param callable $build  Receives the blueprint
 * @param string   $action create|alter|drop|drop_if_exists
 *
 * @return array<int, string>
 */
function schema_sql(callable $build, string $action = 'create', ?grammar $grammar = null): array
{
    $table = new blueprint('user', $action);
    $build($table);

    return ($grammar ?? new grammar())->compile_schema($table);
}

/**
 * The first statement of a compilation, whitespace collapsed so a test can read as one line.
 *
 * @param callable $build
 * @param string   $action
 *
 * @return string
 */
function schema_line(callable $build, string $action = 'create', ?grammar $grammar = null): string
{
    $sql = schema_sql($build, $action, $grammar)[0] ?? '';

    return trim((string) preg_replace('/\s+/', ' ', $sql));
}

/*
|--------------------------------------------------------------------------
| Columns
|--------------------------------------------------------------------------
*/

it('compiles the column types', function () {
    $sql = schema_line(function (blueprint $t) {
        $t->string('a');
        $t->string('b', 64);
        $t->char('c', 8);
        $t->text('d');
        $t->integer('e');
        $t->big_integer('f');
        $t->decimal('g', 12, 4);
        $t->boolean('h');
        $t->json('i');
        $t->enum('j', ['on', 'off']);
    });

    expect($sql)->toContain('`a` VARCHAR(191) NOT NULL')
        ->and($sql)->toContain('`b` VARCHAR(64) NOT NULL')
        ->and($sql)->toContain('`c` CHAR(8) NOT NULL')
        ->and($sql)->toContain('`d` TEXT NOT NULL')
        ->and($sql)->toContain('`e` INT NOT NULL')
        ->and($sql)->toContain('`f` BIGINT NOT NULL')
        ->and($sql)->toContain('`g` DECIMAL(12,4) NOT NULL')
        ->and($sql)->toContain('`h` TINYINT(1) NOT NULL')
        ->and($sql)->toContain('`i` JSON NOT NULL')
        ->and($sql)->toContain("`j` ENUM('on', 'off') NOT NULL");
});

it('writes an auto incrementing primary key for id()', function () {
    $sql = schema_line(fn (blueprint $t) => $t->id());

    expect($sql)->toContain('`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT')
        ->and($sql)->toContain('PRIMARY KEY (`id`)');
});

it('applies the modifiers', function () {
    $sql = schema_line(function (blueprint $t) {
        $t->string('a')->nullable();
        $t->integer('b')->unsigned()->default(0);
        $t->string('c')->default('x')->comment('note');
        $t->timestamp('d')->use_current(true);
    });

    expect($sql)->toContain('`a` VARCHAR(191) NULL')
        ->and($sql)->toContain('`b` INT UNSIGNED NOT NULL DEFAULT 0')
        ->and($sql)->toContain("`c` VARCHAR(191) NOT NULL DEFAULT 'x' COMMENT 'note'")
        ->and($sql)->toContain('`d` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
});

it('tells a default of zero apart from no default', function () {
    $with    = schema_line(fn (blueprint $t) => $t->integer('a')->default(0));
    $without = schema_line(fn (blueprint $t) => $t->integer('a'));

    // The column definition and not the whole statement: DEFAULT CHARSET is in every one of them
    expect($with)->toContain('`a` INT NOT NULL DEFAULT 0')
        ->and($without)->toContain('`a` INT NOT NULL')
        ->and($without)->not->toContain('`a` INT NOT NULL DEFAULT');
});

it('escapes a string default rather than interpolating it', function () {
    $sql = schema_line(fn (blueprint $t) => $t->string('a')->default("it's"));

    expect($sql)->toContain("DEFAULT 'it\\'s'");
});

it('lets a raw expression through as a default', function () {
    $sql = schema_line(fn (blueprint $t) => $t->integer('a')->default(db::raw('(1 + 1)')));

    expect($sql)->toContain('DEFAULT (1 + 1)');
});

it('writes timestamps() as two nullable columns', function () {
    $sql = schema_line(fn (blueprint $t) => $t->timestamps());

    expect($sql)->toContain('`created_at` TIMESTAMP NULL')
        ->and($sql)->toContain('`updated_at` TIMESTAMP NULL');
});

/*
|--------------------------------------------------------------------------
| Indexes
|--------------------------------------------------------------------------
*/

it('writes the indexes inside CREATE TABLE', function () {
    $sql = schema_line(function (blueprint $t) {
        $t->id();
        $t->string('email');
        $t->integer('status');
        $t->unique('email', 'uniq_email');
        $t->index(['status', 'id'], 'idx_status_id');
    });

    expect($sql)->toContain('UNIQUE KEY `uniq_email` (`email`)')
        ->and($sql)->toContain('KEY `idx_status_id` (`status`, `id`)');
});

it('names an index after its table and columns when it was not given one', function () {
    $sql = schema_line(function (blueprint $t) {
        $t->string('email');
        $t->index('email');
    });

    expect($sql)->toContain('KEY `idx_user_email` (`email`)');
});

it('keeps a generated index name inside the 64 characters MySQL allows', function () {
    $long = str_repeat('column_name_that_is_long', 4);

    $sql = schema_line(function (blueprint $t) use ($long) {
        $t->string($long);
        $t->index($long);
    });

    expect($sql)->toMatch('/KEY `[0-9a-z_]{1,64}` /');
});

it('collects the primary key of several columns into one clause', function () {
    $sql = schema_line(function (blueprint $t) {
        $t->integer('a');
        $t->integer('b');
        $t->primary(['a', 'b']);
    });

    expect($sql)->toContain('PRIMARY KEY (`a`, `b`)');
});

/*
|--------------------------------------------------------------------------
| Table options
|--------------------------------------------------------------------------
*/

it('defaults to InnoDB and utf8mb4', function () {
    $sql = schema_line(fn (blueprint $t) => $t->id());

    expect($sql)->toContain('ENGINE=InnoDB')
        ->and($sql)->toContain('DEFAULT CHARSET=utf8mb4');
});

it('takes the engine, the charset and the comment it was given', function () {
    $sql = schema_line(function (blueprint $t) {
        $t->id();
        $t->engine('MyISAM');
        $t->charset('latin1', 'latin1_bin');
        $t->comment('Accounts');
    });

    expect($sql)->toContain('ENGINE=MyISAM')
        ->and($sql)->toContain('DEFAULT CHARSET=latin1')
        ->and($sql)->toContain('COLLATE=latin1_bin')
        ->and($sql)->toContain("COMMENT='Accounts'");
});

/*
|--------------------------------------------------------------------------
| Alter and drop
|--------------------------------------------------------------------------
*/

it('adds, modifies and drops columns in one ALTER', function () {
    $sql = schema_line(function (blueprint $t) {
        $t->string('nickname')->nullable()->after('email');
        $t->string('email', 255)->change();
        $t->drop_column('legacy');
    }, 'alter');

    expect($sql)->toStartWith('ALTER TABLE `user` ')
        ->and($sql)->toContain('ADD COLUMN `nickname` VARCHAR(191) NULL AFTER `email`')
        ->and($sql)->toContain('MODIFY COLUMN `email` VARCHAR(255) NOT NULL')
        ->and($sql)->toContain('DROP COLUMN `legacy`');
});

it('puts a table rename in a statement of its own', function () {
    $statements = schema_sql(function (blueprint $t) {
        $t->string('a');
        $t->rename('account');
    }, 'alter');

    expect($statements)->toHaveCount(2)
        ->and($statements[0])->toContain('ADD COLUMN `a`')
        ->and($statements[1])->toBe('ALTER TABLE `user` RENAME TO `account`');
});

it('drops indexes and the primary key', function () {
    $sql = schema_line(function (blueprint $t) {
        $t->drop_index('idx_status');
        $t->drop_primary();
    }, 'alter');

    expect($sql)->toContain('DROP INDEX `idx_status`')
        ->and($sql)->toContain('DROP PRIMARY KEY');
});

it('compiles the two drops', function () {
    expect(schema_line(fn (blueprint $t) => null, 'drop'))->toBe('DROP TABLE `user`')
        ->and(schema_line(fn (blueprint $t) => null, 'drop_if_exists'))
        ->toBe('DROP TABLE IF EXISTS `user`');
});

it('refuses an identifier that is not one', function () {
    schema_line(fn (blueprint $t) => $t->string('name); DROP TABLE user; --'));
})->throws(InvalidArgumentException::class);

/*
|--------------------------------------------------------------------------
| ClickHouse
|--------------------------------------------------------------------------
*/

it('writes ClickHouse types, with nullability inside the type', function () {
    $sql = schema_line(function (blueprint $t) {
        $t->big_integer('id')->unsigned();
        $t->string('email');
        $t->integer('status')->nullable();
        $t->primary('id');
    }, 'create', new clickhouse());

    expect($sql)->toContain('`id` UInt64')
        ->and($sql)->toContain('`email` String')
        ->and($sql)->toContain('`status` Nullable(Int32)')
        ->and($sql)->not->toContain('NOT NULL')
        ->and($sql)->toContain('ENGINE = MergeTree')
        ->and($sql)->toContain('ORDER BY (`id`)');
});

it('takes the ClickHouse sorting key from an explicit option', function () {
    $sql = schema_line(function (blueprint $t) {
        $t->datetime('day');
        $t->string('metric');
        $t->option('order_by', ['day', 'metric']);
        $t->option('partition_by', 'toYYYYMM(day)');
    }, 'create', new clickhouse());

    expect($sql)->toContain('PARTITION BY toYYYYMM(day)')
        ->and($sql)->toContain('ORDER BY (`day`, `metric`)');
});

it('refuses a ClickHouse table with no sorting key at all', function () {
    schema_line(fn (blueprint $t) => $t->string('a'), 'create', new clickhouse());
})->throws(RuntimeException::class);

it('refuses AUTO_INCREMENT on ClickHouse instead of dropping it silently', function () {
    schema_line(fn (blueprint $t) => $t->id(), 'create', new clickhouse());
})->throws(RuntimeException::class);

it('refuses a secondary index on ClickHouse', function () {
    schema_line(function (blueprint $t) {
        $t->index('status', 'idx_status');
    }, 'alter', new clickhouse());
})->throws(RuntimeException::class);

it('splits a ClickHouse alter into one statement per change', function () {
    $statements = schema_sql(function (blueprint $t) {
        $t->string('nickname');
        $t->drop_column('legacy');
    }, 'alter', new clickhouse());

    expect($statements)->toHaveCount(2)
        ->and($statements[0])->toContain('ADD COLUMN `nickname` String')
        ->and($statements[1])->toContain('DROP COLUMN `legacy`');
});

/*
|--------------------------------------------------------------------------
| column
|--------------------------------------------------------------------------
*/

it('tells an attribute that was set from one that was not', function () {
    $set = (new column('a', 'integer'))->default(0);

    expect($set->has('default'))->toBeTrue()
        ->and($set->get('default'))->toBe(0)
        ->and((new column('a', 'integer'))->has('default'))->toBeFalse();
});
