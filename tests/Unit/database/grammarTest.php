<?php
/**
 * grammar: the compiler, asserted on the statement and the bindings it returns.
 *
 * No server: a query plus a grammar is a pure function. The DDL half of the compiler is covered by
 * schemaTest.php; what is checked here is the SELECT chain and the write statements, plus the two
 * rules the grammar exists to enforce -- a value never reaches the statement, and an identifier is
 * a plain identifier.
 *
 * Both halves of every return value are asserted. A clause compiler must append to the bindings in
 * the same order its placeholders appear in the string it returns, and nothing in the compiler
 * checks that; a test that only looked at the SQL would pass while the values went to the wrong
 * placeholders.
 */

use plato\database\connection;
use plato\database\db;
use plato\database\grammar;
use plato\database\grammar\clickhouse;
use plato\database\query;

/**
 * A query against a table on a connection that never dials.
 *
 * @param string $table
 * @return query
 */
function grammar_test_query(string $table = '#PB#_user'): query
{
    return connection::make('mysql', ['prefix' => 'plt'])->table($table);
}

/**
 * Compile a SELECT with the MySQL grammar unless another dialect was passed.
 *
 * @param query        $q
 * @param grammar|null $grammar
 * @return array{0: string, 1: array<int, mixed>}
 */
function grammar_test_select(query $q, ?grammar $grammar = null): array
{
    return ($grammar ?? new grammar('plt'))->compile_select($q);
}

/*
|--------------------------------------------------------------------------
| Identifiers
|--------------------------------------------------------------------------
*/

it('quotes an identifier one segment at a time', function () {
    list($sql) = grammar_test_select(grammar_test_query()->select('a.id', 'b.*', '*'));

    expect($sql)->toBe('SELECT `a`.`id`, `b`.*, * FROM `plt_user`');
});

it('splits an alias off a column and quotes both halves', function () {
    list($sql) = grammar_test_select(grammar_test_query()->select('a.id AS uid', 'name as n'));

    expect($sql)->toBe('SELECT `a`.`id` AS `uid`, `name` AS `n` FROM `plt_user`');
});

it('quotes a non ASCII identifier rather than refusing it', function () {
    list($sql) = grammar_test_select(grammar_test_query()->select('名字'));

    expect($sql)->toBe('SELECT `名字` FROM `plt_user`');
});

it('refuses anything that is not a plain identifier', function ($column) {
    grammar_test_select(grammar_test_query()->select($column));
})->throws(InvalidArgumentException::class)->with([
    'COUNT(*)',
    'id`, (SELECT 1) AS x',
    "name' OR '1",
    'a b',
    '',
]);

it('names db::raw() as the way past the quoter', function () {
    try
    {
        grammar_test_select(grammar_test_query()->select('COUNT(*)'));
    }
    catch (InvalidArgumentException $e)
    {
        expect($e->getMessage())->toContain('db::raw()');
    }
});

it('passes an expression through untouched, prefix expanded', function () {
    list($sql, $bindings) = grammar_test_select(
        grammar_test_query()->select(db::raw('COUNT(*) AS c'), db::raw('#PB#_user.id'))
    );

    expect($sql)->toBe('SELECT COUNT(*) AS c, plt_user.id FROM `plt_user`')
        ->and($bindings)->toBe([])
        // A table is an expression too when it has to be, #!PB# included
        ->and(grammar_test_select(grammar_test_query()->from(db::raw('#!PB#_user AS u')))[0])
        ->toBe('SELECT * FROM #PB#_user AS u');
});

it('expands #PB# in a table name and leaves #!PB# as the literal', function () {
    $grammar = new grammar('plt');

    expect($grammar->substitute_prefix('#PB#_user'))->toBe('plt_user')
        ->and($grammar->substitute_prefix('#!PB#_user'))->toBe('#PB#_user')
        ->and($grammar->substitute_prefix('#PB#_a JOIN #!PB#_b'))->toBe('plt_a JOIN #PB#_b')
        ->and($grammar->substitute_prefix('no placeholder'))->toBe('no placeholder')
        ->and($grammar->prefix())->toBe('plt');
});

it('addresses a path inside a JSON document with ->', function () {
    list($sql, $bindings) = grammar_test_select(
        grammar_test_query()->select('data->profile.city')->where('data->age', '>', 3)
    );

    expect($sql)->toBe("SELECT `data`->'\$.profile.city' FROM `plt_user` WHERE `data`->'\$.age' > ?")
        ->and($bindings)->toBe([3]);
});

it('refuses a JSON path that is not one', function () {
    grammar_test_select(grammar_test_query()->where('data->a b', 1));
})->throws(InvalidArgumentException::class, 'is not a JSON path');

it('refuses an operator that is not on the list', function () {
    grammar_test_select(grammar_test_query()->where('a', 'sounds like', 1));
})->throws(InvalidArgumentException::class, "'sounds like' is not a known operator");

it('upper cases a known operator', function () {
    list($sql) = grammar_test_select(grammar_test_query()->where('a', 'Not Like', 'x%'));

    expect($sql)->toBe('SELECT * FROM `plt_user` WHERE `a` NOT LIKE ?');
});

/*
|--------------------------------------------------------------------------
| Values
|--------------------------------------------------------------------------
*/

it('leaves every value as a placeholder and collects it in statement order', function () {
    list($sql, $bindings) = grammar_test_select(
        grammar_test_query()
            ->join('#PB#_p', [['p.uid', '=', 'u.uid'], ['p.type', '=', 'a', 'AND', 'value']])
            ->where('b', 'b')
            ->group_by('c')
            ->having('d', '>', 'd')
            ->order_by('e')
            ->limit(1)
    );

    expect($sql)->toBe(
        'SELECT * FROM `plt_user` INNER JOIN `plt_p` ON (`p`.`uid` = `u`.`uid` AND `p`.`type` = ?)'
        . ' WHERE `b` = ? GROUP BY `c` HAVING `d` > ? ORDER BY `e` ASC LIMIT 1'
    )->and($bindings)->toBe(['a', 'b', 'd']);
});

it('folds an expression bindings into the list where its placeholders are', function () {
    list($sql, $bindings) = grammar_test_select(
        grammar_test_query()
            ->where('a', 1)
            ->where('b', db::raw('IF(? > 1, ?, ?)', [2, 'y', 'n']))
            ->where('c', 3)
    );

    expect($sql)->toBe('SELECT * FROM `plt_user` WHERE `a` = ? AND `b` = IF(? > 1, ?, ?) AND `c` = ?')
        ->and($bindings)->toBe([1, 2, 'y', 'n', 3]);
});

it('compiles a subquery in place and takes its bindings with it', function () {
    $sub = grammar_test_query('#PB#_log')->select('uid')->where('n', 2);

    list($sql, $bindings) = grammar_test_select(
        grammar_test_query()->where('a', 1)->where_in('id', $sub)->where('z', 9)
    );

    expect($sql)->toBe(
        'SELECT * FROM `plt_user` WHERE `a` = ? AND `id` IN (SELECT `uid` FROM `plt_log` WHERE `n` = ?) AND `z` = ?'
    )->and($bindings)->toBe([1, 2, 9]);
});

it('reduces a value to something a driver can bind', function () {
    $grammar  = new grammar('plt');
    $bindings = [];

    $grammar->parameter(new DateTimeImmutable('2026-01-02 03:04:05'), $bindings);
    $grammar->parameter(true, $bindings);
    $grammar->parameter(false, $bindings);
    $grammar->parameter(['x' => 1], $bindings);
    $grammar->parameter(null, $bindings);

    expect($bindings)->toBe(['2026-01-02 03:04:05', 1, 0, '{"x":1}', null]);
});

it('renders a literal for a statement that has nowhere to bind it', function () {
    $grammar = new grammar('plt');

    expect($grammar->escape_literal(null))->toBe('NULL')
        ->and($grammar->escape_literal(true))->toBe('1')
        ->and($grammar->escape_literal(false))->toBe('0')
        ->and($grammar->escape_literal(12))->toBe('12')
        ->and($grammar->escape_literal(1.5))->toBe('1.5')
        ->and($grammar->escape_literal("it's"))->toBe("'it\\'s'")
        ->and($grammar->escape_literal("a\\b\nc\x1a"))->toBe("'a\\\\b\\nc\\Z'");
});

it('puts the bindings back in one placeholder at a time', function () {
    $grammar = new grammar('plt');

    expect($grammar->interpolate('SELECT ?, ?, ?', ["it's", null, 3]))
        ->toBe("SELECT 'it\\'s', NULL, 3")
        // Nothing left to substitute stays a placeholder rather than shifting the rest along
        ->and($grammar->interpolate('SELECT ?, ?', [1]))->toBe('SELECT 1, ?')
        ->and($grammar->interpolate('SELECT ?', []))->toBe('SELECT ?');
});

/*
|--------------------------------------------------------------------------
| SELECT clauses
|--------------------------------------------------------------------------
*/

it('compiles an aggregate instead of the columns', function () {
    $count = grammar_test_query();
    $count->aggregate = ['function' => 'count', 'column' => '*'];

    $distinct = grammar_test_query()->distinct();
    $distinct->aggregate = ['function' => 'COUNT', 'column' => 'a.id'];

    expect(grammar_test_select($count)[0])->toBe('SELECT COUNT(*) AS `aggregate` FROM `plt_user`')
        ->and(grammar_test_select($distinct)[0])
        ->toBe('SELECT COUNT(DISTINCT `a`.`id`) AS `aggregate` FROM `plt_user`');
});

it('puts the clauses in the order MySQL wants them', function () {
    $sub = grammar_test_query('#PB#_b')->select('id');

    list($sql) = grammar_test_select(
        grammar_test_query()
            ->select('id')
            ->force_index('idx_a')
            ->join('#PB#_p', 'p.uid', '=', 'u.uid')
            ->where('a', 1)
            ->group_by('b')
            ->having('c', '>', 2)
            ->union($sub)
            ->order_by('d')
            ->limit(5)
            ->offset(10)
            ->lock_for_update()
    );

    expect($sql)->toBe(
        'SELECT `id` FROM `plt_user` FORCE INDEX (`idx_a`) INNER JOIN `plt_p` ON (`p`.`uid` = `u`.`uid`)'
        . ' WHERE `a` = ? GROUP BY `b` HAVING `c` > ? UNION (SELECT `id` FROM `plt_b`)'
        . ' ORDER BY `d` ASC LIMIT 5 OFFSET 10 FOR UPDATE'
    );
});

it('nests a where group without repeating the leading boolean', function () {
    list($sql, $bindings) = grammar_test_select(
        grammar_test_query()->where(function (query $w) {
            $w->where('a', 1)->or_where('b', 2);
        })->where('c', 3)
    );

    expect($sql)->toBe('SELECT * FROM `plt_user` WHERE (`a` = ? OR `b` = ?) AND `c` = ?')
        ->and($bindings)->toBe([1, 2, 3]);
});

it('writes the constant an empty IN means, since IN () is a syntax error', function () {
    expect(grammar_test_select(grammar_test_query()->where_in('id', []))[0])
        ->toBe('SELECT * FROM `plt_user` WHERE 1 = 0')
        // NOT IN on an empty set matches everything
        ->and(grammar_test_select(grammar_test_query()->where_not_in('id', []))[0])
        ->toBe('SELECT * FROM `plt_user` WHERE 1 = 1');
});

it('parenthesises an expression on the right hand side of IN', function () {
    list($sql, $bindings) = grammar_test_select(
        grammar_test_query()->where_in('id', db::raw('SELECT id FROM x WHERE k = ?', [4]))
    );

    expect($sql)->toBe('SELECT * FROM `plt_user` WHERE `id` IN (SELECT id FROM x WHERE k = ?)')
        ->and($bindings)->toBe([4]);
});

it('takes the union bindings before the outer ones when the unions are the FROM target', function () {
    $b = grammar_test_query('#PB#_b')->select('id')->where('k', 1);
    $c = grammar_test_query('#PB#_c')->select('id')->where('k', 2);

    $through_from = grammar_test_query()->select('id')->union($b)->union_all($c)
        ->as_union_table('u')->where('id', '>', 3);
    $appended = grammar_test_query()->select('id')->where('id', '>', 3)->union($b)->union_all($c);

    expect(grammar_test_select($through_from))->toBe([
        'SELECT `id` FROM ((SELECT `id` FROM `plt_b` WHERE `k` = ?)'
        . ' UNION ALL (SELECT `id` FROM `plt_c` WHERE `k` = ?)) AS `u` WHERE `id` > ?',
        [1, 2, 3],
    ])->and(grammar_test_select($appended))->toBe([
        'SELECT `id` FROM `plt_user` WHERE `id` > ?'
        . ' UNION (SELECT `id` FROM `plt_b` WHERE `k` = ?) UNION ALL (SELECT `id` FROM `plt_c` WHERE `k` = ?)',
        [3, 1, 2],
    ]);
});

it('gives an OFFSET without a LIMIT the largest LIMIT there is', function () {
    expect(grammar_test_select(grammar_test_query()->offset(5))[0])
        ->toBe('SELECT * FROM `plt_user` LIMIT 18446744073709551615 OFFSET 5')
        ->and(grammar_test_select(grammar_test_query()->limit(2)->offset(5))[0])
        ->toBe('SELECT * FROM `plt_user` LIMIT 2 OFFSET 5')
        ->and(grammar_test_select(grammar_test_query())[0])->toBe('SELECT * FROM `plt_user`');
});

it('lets the ceiling stand in for a missing LIMIT and cap a larger one', function () {
    expect(grammar_test_select(grammar_test_query()->max_select_limit(300))[0])
        ->toBe('SELECT * FROM `plt_user` LIMIT 300')
        ->and(grammar_test_select(grammar_test_query()->max_select_limit(300)->limit(1000))[0])
        ->toBe('SELECT * FROM `plt_user` LIMIT 300')
        ->and(grammar_test_select(grammar_test_query()->max_select_limit(300)->offset(5))[0])
        ->toBe('SELECT * FROM `plt_user` LIMIT 300 OFFSET 5');
});

it('refuses a where clause of a type it does not know', function () {
    $q = grammar_test_query();
    $q->wheres[] = ['type' => 'telepathy', 'boolean' => 'AND'];

    grammar_test_select($q);
})->throws(InvalidArgumentException::class, 'Unknown where clause: telepathy');

/*
|--------------------------------------------------------------------------
| INSERT
|--------------------------------------------------------------------------
*/

it('writes one VALUES group per row, off the first row column list', function () {
    list($sql, $bindings) = (new grammar('plt'))->compile_insert(
        grammar_test_query(),
        [['a' => 1, 'b' => 'x'], ['a' => 2, 'b' => 'y']]
    );

    expect($sql)->toBe('INSERT INTO `plt_user` (`a`, `b`) VALUES (?, ?), (?, ?)')
        ->and($bindings)->toBe([1, 'x', 2, 'y']);
});

it('writes NULL for a column a later row left out rather than shifting the values along', function () {
    list($sql, $bindings) = (new grammar('plt'))->compile_insert(
        grammar_test_query()->ignore(),
        [['a' => 1, 'b' => 'x'], ['a' => 2]]
    );

    expect($sql)->toBe('INSERT IGNORE INTO `plt_user` (`a`, `b`) VALUES (?, ?), (?, ?)')
        ->and($bindings)->toBe([1, 'x', 2, null]);
});

it('takes a bare column name in an upsert from the INSERT and a pair as a value', function () {
    list($sql, $bindings) = (new grammar('plt'))->compile_insert(
        grammar_test_query(),
        [['a' => 1, 'b' => 2]],
        ['b', 'c' => 5]
    );

    expect($sql)->toBe(
        'INSERT INTO `plt_user` (`a`, `b`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `b` = VALUES(`b`), `c` = ?'
    )->and($bindings)->toBe([1, 2, 5]);
});

it('refuses an insert with no row', function () {
    (new grammar('plt'))->compile_insert(grammar_test_query(), []);
})->throws(InvalidArgumentException::class, 'insert() needs at least one row');

/*
|--------------------------------------------------------------------------
| UPDATE and DELETE
|--------------------------------------------------------------------------
*/

it('puts the SET values ahead of the WHERE values', function () {
    list($sql, $bindings) = (new grammar('plt'))->compile_update(
        grammar_test_query()->where('id', 9)->order_by('id')->limit(2),
        ['a' => 1, 'data->x' => 2]
    );

    expect($sql)->toBe("UPDATE `plt_user` SET `a` = ?, `data`->'\$.x' = ? WHERE `id` = ? ORDER BY `id` ASC LIMIT 2")
        ->and($bindings)->toBe([1, 2, 9]);
});

it('refuses an update with no column', function () {
    (new grammar('plt'))->compile_update(grammar_test_query()->where('id', 1), []);
})->throws(InvalidArgumentException::class, 'update() needs at least one column');

it('builds one CASE per column and an ELSE that leaves the other rows alone', function () {
    list($sql, $bindings) = (new grammar('plt'))->compile_update_batch(
        grammar_test_query()->where('t', 1),
        [['id' => 1, 'a' => 'x'], ['id' => 2, 'a' => 'y', 'b' => 3]],
        'id'
    );

    // Per column and not per row: row 1 sets no `b`, and without the ELSE arm it would be blanked
    expect($sql)->toBe(
        'UPDATE `plt_user` SET `a` = (CASE `id` WHEN ? THEN ? WHEN ? THEN ? ELSE `a` END),'
        . ' `b` = (CASE `id` WHEN ? THEN ? ELSE `b` END) WHERE `t` = ? AND `id` IN (?, ?)'
    )->and($bindings)->toBe([1, 'x', 2, 'y', 2, 3, 1, 1, 2]);
});

it('adds the key list to the caller WHERE rather than replacing it', function () {
    $q = grammar_test_query()->where('t', 1);

    (new grammar('plt'))->compile_update_batch($q, [['id' => 1, 'a' => 'x']], 'id');

    expect($q->wheres)->toHaveCount(1)
        ->and($q->wheres[0]['column'])->toBe('t');
});

it('refuses a batch row with no key and a batch with nothing but the key', function () {
    expect(fn () => (new grammar('plt'))->compile_update_batch(grammar_test_query(), [['a' => 1]], 'id'))
        ->toThrow(InvalidArgumentException::class, "update_batch(): every row needs a 'id' value")
        ->and(fn () => (new grammar('plt'))->compile_update_batch(grammar_test_query(), [['id' => 1]], 'id'))
        ->toThrow(InvalidArgumentException::class, 'update_batch() needs at least one column besides the key');
});

it('compiles a delete with its where, order and limit', function () {
    list($sql, $bindings) = (new grammar('plt'))->compile_delete(
        grammar_test_query()->ignore()->where('id', 1)->order_by('id', 'desc')->limit(3)
    );

    expect($sql)->toBe('DELETE IGNORE FROM `plt_user` WHERE `id` = ? ORDER BY `id` DESC LIMIT 3')
        ->and($bindings)->toBe([1]);
});

it('merges into a JSON column by path, casting an array to a document', function () {
    list($sql, $bindings) = (new grammar('plt'))->compile_update_json(
        grammar_test_query()->where('id', 1),
        'data',
        ['a.b' => 1, 'list' => [1, 2]]
    );

    expect($sql)->toBe(
        "UPDATE `plt_user` SET `data` = JSON_SET(`data`, '\$.a.b', ?, '\$.list', CAST(? AS JSON)) WHERE `id` = ?"
    )->and($bindings)->toBe([1, '[1,2]', 1]);
});

it('refuses an update_json with no path', function () {
    (new grammar('plt'))->compile_update_json(grammar_test_query()->where('id', 1), 'data', []);
})->throws(InvalidArgumentException::class, 'update_json() needs at least one path');

/*
|--------------------------------------------------------------------------
| ClickHouse
|--------------------------------------------------------------------------
*/

it('puts the ClickHouse only clauses where ClickHouse wants them', function () {
    $q = grammar_test_query()
        ->select('a')
        ->where('b', 1)
        ->option('final', true)
        ->option('sample', '0.1')
        ->option('prewhere', db::raw('day = ?', ['2026-01-01']))
        ->option('settings', ['max_threads' => 4, 'log_comment' => 'x']);

    list($sql, $bindings) = grammar_test_select($q, new clickhouse('plt'));

    expect($sql)->toBe(
        'SELECT `a` FROM `plt_user` FINAL SAMPLE 0.1 PREWHERE day = ? WHERE `b` = ?'
        . " SETTINGS max_threads = 4, log_comment = 'x'"
    )
        // PREWHERE goes through parameter(), so its bindings land ahead of the WHERE ones
        ->and($bindings)->toBe(['2026-01-01', 1]);
});

it('takes a PREWHERE that is a plain string, prefix expanded', function () {
    list($sql, $bindings) = grammar_test_select(
        grammar_test_query()->option('prewhere', '#PB#_user.day > 0')->where('a', 1),
        new clickhouse('plt')
    );

    expect($sql)->toBe('SELECT * FROM `plt_user` PREWHERE plt_user.day > 0 WHERE `a` = ?')
        ->and($bindings)->toBe([1]);
});

it('refuses a ClickHouse setting name or sample that is not one', function () {
    expect(fn () => grammar_test_select(
        grammar_test_query()->option('settings', ['max threads' => 1]),
        new clickhouse('plt')
    ))->toThrow(InvalidArgumentException::class, "'max threads' is not a setting name")
        ->and(fn () => grammar_test_select(
            grammar_test_query()->option('sample', 'half'),
            new clickhouse('plt')
        ))->toThrow(InvalidArgumentException::class, "'half' is not a number");
});

it('refuses a ClickHouse row lock and index hint instead of dropping them silently', function () {
    expect(fn () => grammar_test_select(grammar_test_query()->lock_for_update(), new clickhouse('plt')))
        ->toThrow(RuntimeException::class, 'ClickHouse has no row locks')
        ->and(fn () => grammar_test_select(grammar_test_query()->force_index('idx_a'), new clickhouse('plt')))
        ->toThrow(RuntimeException::class, 'ClickHouse has no index hints');
});

it('spells a ClickHouse write as a mutation and requires a WHERE', function () {
    $grammar = new clickhouse('plt');

    list($update, $update_bindings) = $grammar->compile_update(grammar_test_query()->where('id', 1), ['a' => 2]);
    list($delete, $delete_bindings) = $grammar->compile_delete(grammar_test_query()->where('id', 1));

    expect($update)->toBe('ALTER TABLE `plt_user` UPDATE `a` = ? WHERE `id` = ?')
        ->and($update_bindings)->toBe([2, 1])
        ->and($delete)->toBe('ALTER TABLE `plt_user` DELETE WHERE `id` = ?')
        ->and($delete_bindings)->toBe([1])
        // A mutation with no WHERE would rewrite every part on disk
        ->and(fn () => $grammar->compile_update(grammar_test_query(), ['a' => 2]))
        ->toThrow(RuntimeException::class, 'would rewrite the whole table')
        ->and(fn () => $grammar->compile_delete(grammar_test_query()))
        ->toThrow(RuntimeException::class, 'would rewrite the whole table');
});

it('refuses the ClickHouse writes it has no statement for', function () {
    $grammar = new clickhouse('plt');

    expect(fn () => $grammar->compile_update_batch(grammar_test_query(), [['id' => 1, 'a' => 2]], 'id'))
        ->toThrow(RuntimeException::class, 'cannot be batched by key')
        ->and(fn () => $grammar->compile_update_json(grammar_test_query(), 'data', ['a' => 1]))
        ->toThrow(RuntimeException::class, 'ClickHouse has no JSON_SET')
        ->and(fn () => $grammar->compile_insert(grammar_test_query()->ignore(), [['a' => 1]]))
        ->toThrow(RuntimeException::class, 'ClickHouse has no INSERT IGNORE')
        ->and(fn () => $grammar->compile_insert(grammar_test_query(), [['a' => 1]], ['a']))
        ->toThrow(RuntimeException::class, 'ClickHouse has no ON DUPLICATE KEY UPDATE');
});

it('escapes a tab for ClickHouse and does not reach for MySQL escape', function () {
    $grammar = new clickhouse('plt');

    expect($grammar->escape_literal("a\tb"))->toBe("'a\\tb'")
        ->and($grammar->escape_literal("a\x1ab"))->toBe("'a\x1ab'")
        ->and((new grammar('plt'))->escape_literal("a\tb"))->toBe("'a\tb'");
});
