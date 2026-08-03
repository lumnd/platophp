<?php
/**
 * migrator: discovery, batching and rollback of migration files.
 *
 * The repository is kept in memory, so the ordering and the batch bookkeeping can be checked
 * without a reachable MySQL. Only the storage is faked; the migration files are real and are
 * written into a temporary directory per test.
 */

use plato\database\migrator;

/**
 * Migrator with an in memory repository and no advisory lock.
 */
class test_migrator extends migrator
{
    protected array $_records = [];

    public int $lock_count = 0;

    public int $release_count = 0;

    protected function _acquire_lock(): void
    {
        $this->lock_count++;
    }

    protected function _release_lock(): void
    {
        $this->release_count++;
    }

    protected function _ensure_repository(): void
    {
    }

    protected function _repository(): array
    {
        return $this->_records;
    }

    protected function _record(string $filename, int $batch): void
    {
        $this->_records[] = [
            'migration' => $filename,
            'batch'     => $batch,
        ];
    }

    protected function _latest_batch(): int
    {
        return $this->_records ? max(array_column($this->_records, 'batch')) : 0;
    }

    protected function _batch(int $batch): array
    {
        $files = [];
        foreach (array_reverse($this->_records) as $row)
        {
            if($row['batch'] === $batch)
            {
                $files[] = $row['migration'];
            }
        }

        return $files;
    }

    protected function _forget(string $filename): void
    {
        $this->_records = array_values(array_filter(
            $this->_records,
            fn (array $row): bool => $row['migration'] !== $filename
        ));
    }
}

/**
 * Write a migration file that appends a line to the log file when it runs, which is how the
 * tests observe the order up() and down() were called in.
 *
 * @param string $path     Migration directory
 * @param string $filename Migration file name
 * @param string $name     Marker written to the log file
 * @param string $log_file Log file the migration appends to
 * @return void
 */
function write_test_migration(string $path, string $filename, string $name, string $log_file): void
{
    $code = sprintf(<<<'PHP'
<?php

use plato\database\migration;

return new class extends migration
{
    public function up(): void
    {
        file_put_contents(%s, "up:%s\n", FILE_APPEND);
    }

    public function down(): void
    {
        file_put_contents(%s, "down:%s\n", FILE_APPEND);
    }
};
PHP, var_export($log_file, true), $name, var_export($log_file, true), $name);

    file_put_contents($path . DIRECTORY_SEPARATOR . $filename, $code);
}

beforeEach(function () {
    $suffix               = bin2hex(random_bytes(4));
    $this->migration_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'platophp_migrations_' . $suffix;
    $this->log_file       = $this->migration_path . DIRECTORY_SEPARATOR . 'migration.log';

    mkdir($this->migration_path);
});

afterEach(function () {
    foreach (glob($this->migration_path . DIRECTORY_SEPARATOR . '*') ?: [] as $file)
    {
        unlink($file);
    }
    rmdir($this->migration_path);
});

it('runs pending migrations once and reports their status', function () {
    $first  = '20260723_000001_create_first_table.php';
    $second = '20260723_000002_create_second_table.php';
    write_test_migration($this->migration_path, $first, 'first', $this->log_file);
    write_test_migration($this->migration_path, $second, 'second', $this->log_file);

    $migrator = new test_migrator($this->migration_path);

    expect($migrator->migrate())->toBe([$first, $second])
        ->and($migrator->migrate())->toBe([])
        ->and(file($this->log_file, FILE_IGNORE_NEW_LINES))->toBe(['up:first', 'up:second'])
        ->and($migrator->status())->toBe([
            ['migration' => $first, 'status' => 'ran', 'batch' => 1],
            ['migration' => $second, 'status' => 'ran', 'batch' => 1],
        ]);
});

it('rolls back only the latest migration batch in reverse order', function () {
    $first  = '20260723_000001_create_first_table.php';
    $second = '20260723_000002_create_second_table.php';
    write_test_migration($this->migration_path, $first, 'first', $this->log_file);
    write_test_migration($this->migration_path, $second, 'second', $this->log_file);

    $migrator = new test_migrator($this->migration_path);
    $migrator->migrate();

    expect($migrator->rollback())->toBe([$second, $first])
        ->and(file($this->log_file, FILE_IGNORE_NEW_LINES))->toBe([
            'up:first',
            'up:second',
            'down:second',
            'down:first',
        ])
        ->and($migrator->status())->toBe([
            ['migration' => $first, 'status' => 'pending', 'batch' => null],
            ['migration' => $second, 'status' => 'pending', 'batch' => null],
        ]);
});

it('rejects a migration file that does not return the migration contract', function () {
    $first    = '20260723_000001_create_first_table.php';
    $filename = '20260723_000002_invalid_migration.php';
    write_test_migration($this->migration_path, $first, 'first', $this->log_file);
    file_put_contents(
        $this->migration_path . DIRECTORY_SEPARATOR . $filename,
        "<?php\nreturn [];\n"
    );

    $migrator = new test_migrator($this->migration_path);

    // The lock is released even though the run blew up half way through
    expect(fn () => $migrator->migrate())
        ->toThrow(RuntimeException::class, 'plato\database\migration');
    expect($migrator->lock_count)->toBe(1)
        ->and($migrator->release_count)->toBe(1)
        ->and(file_exists($this->log_file))->toBeFalse();
});
