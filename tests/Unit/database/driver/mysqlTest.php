<?php
/**
 * driver\mysql: reading table metadata out of the SHOW statements.
 *
 * The fake answers each SHOW with fixed rows, so the parsing can be checked without a reachable
 * MySQL. Everything else this driver does is PDO, which needs a server to say anything about.
 */

use plato\database\driver\mysql;

class fake_mysql extends mysql
{
    /** @var array<int, string> Statements the driver asked for */
    public array $ran = [];

    public function __construct()
    {
        parent::__construct('fake_mysql', ['driver' => 'mysql', 'prefix' => 'plt']);
    }

    protected function _handle(bool $write)
    {
        return new stdClass();
    }

    protected function _fetch($handle, string $sql, array $bindings): array
    {
        $this->ran[] = $sql;

        return match (true)
        {
            str_starts_with($sql, 'SHOW TABLE STATUS') => [
                [
                    'Name'      => 'plt_article',
                    'Engine'    => 'InnoDB',
                    'Rows'      => 12,
                    'Collation' => 'utf8mb4_unicode_ci',
                    'Comment'   => 'article table',
                ],
            ],
            str_starts_with($sql, 'SHOW FULL COLUMNS') => [
                [
                    'Field'      => 'id',
                    'Type'       => 'int unsigned',
                    'Collation'  => null,
                    'Null'       => 'NO',
                    'Key'        => 'PRI',
                    'Default'    => null,
                    'Extra'      => 'auto_increment',
                    'Privileges' => 'select,insert,update,references',
                    'Comment'    => 'article id',
                ],
                [
                    'Field'      => 'title',
                    'Type'       => 'varchar(200)',
                    'Collation'  => 'utf8mb4_unicode_ci',
                    'Null'       => 'NO',
                    'Key'        => 'MUL',
                    'Default'    => null,
                    'Extra'      => '',
                    'Privileges' => 'select,insert,update,references',
                    'Comment'    => 'article title',
                ],
            ],
            str_starts_with($sql, 'SHOW INDEX') => [
                ['Key_name' => 'PRIMARY', 'Non_unique' => 0, 'Seq_in_index' => 1, 'Column_name' => 'id'],
                ['Key_name' => 'idx_title', 'Non_unique' => 1, 'Seq_in_index' => 1, 'Column_name' => 'title'],
            ],
            str_starts_with($sql, 'SHOW CREATE TABLE') => [
                ['Table' => 'plt_article', 'Create Table' => 'CREATE TABLE `plt_article` (...)'],
            ],
            default => [],
        };
    }
}

it('lists the tables it finds in the mysql metadata', function () {
    $db = new fake_mysql();

    expect($db->tables())->toBe([
        [
            'name'      => 'plt_article',
            'module'    => 'article',
            'comment'   => 'article table',
            'engine'    => 'InnoDB',
            'rows'      => 12,
            'collation' => 'utf8mb4_unicode_ci',
        ],
    ]);
});

it('reads columns, primary key, indexes and create sql of a table', function () {
    $db = new fake_mysql();

    $schema = $db->table_schema('plt_article');

    expect($schema['name'])->toBe('plt_article')
        ->and($schema['module'])->toBe('article')
        ->and($schema['comment'])->toBe('article table')
        // The name a model refers to the table by, with the prefix put back as the placeholder
        ->and($schema['model_table'])->toBe('#PB#_article')
        ->and($schema['primary_key'])->toBe(['id'])
        ->and($schema['columns'][0])->toMatchArray([
            'name'      => 'id',
            'base_type' => 'int',
            'length'    => null,
            'unsigned'  => true,
            'extra'     => 'auto_increment',
        ])
        ->and($schema['columns'][1])->toMatchArray([
            'name'      => 'title',
            'type'      => 'varchar(200)',
            'base_type' => 'varchar',
            'length'    => 200,
            'nullable'  => false,
            'comment'   => 'article title',
        ])
        ->and($schema['indexes']['PRIMARY'])->toBe([
            'unique'  => true,
            'columns' => ['id'],
        ])
        ->and($schema['indexes']['idx_title'])->toBe([
            'unique'  => false,
            'columns' => ['title'],
        ])
        ->and($schema['create_sql'])->toBe('CREATE TABLE `plt_article` (...)');
});

it('takes the table name with the prefix placeholder as well', function () {
    $db = new fake_mysql();

    expect($db->table_schema('#PB#_article')['name'])->toBe('plt_article');
});

it('rejects an unsafe table identifier before it queries anything', function () {
    $db = new fake_mysql();

    // The table name goes into the SHOW statements unquoted, so it is validated, not escaped
    expect(fn () => $db->table_schema('plt_article; DROP TABLE plt_article'))
        ->toThrow(InvalidArgumentException::class, 'is not a table name');
    expect($db->ran)->toBe([]);
});

it('says so when the table is not there', function () {
    $db = new fake_mysql();

    expect(fn () => $db->table_schema('plt_missing'))
        ->toThrow(InvalidArgumentException::class, 'does not exist');
});
