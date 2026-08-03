<?php
/**
 * query: the fluent surface, asserted on the statement it compiles to.
 *
 * No server: a query holds state and to_sql() hands it to a grammar, so the whole builder is a pure
 * function of the calls made on it. connection::make() dials nothing until a statement is run,
 * which is what lets the builder be exercised on its own. What the grammar does with the state
 * belongs to grammarTest.php; what is checked here is which state each call records.
 *
 * Every assertion checks the bindings as well as the string. The two are read together and nothing
 * in the compiler checks that they line up, so a test that only looked at the SQL would pass while
 * the values went to the wrong placeholders.
 */

use plato\database\connection;
use plato\database\db;
use plato\database\query;

/**
 * A query against a table on a connection that never dials.
 *
 * @param string $table
 * @return query
 */
function query_test_builder(string $table = '#PB#_user'): query
{
    return connection::make('mysql', ['prefix' => 'plt'])->table($table);
}

/*
|--------------------------------------------------------------------------
| where
|--------------------------------------------------------------------------
*/

it('reads two arguments as an equality', function () {
    list($sql, $bindings) = query_test_builder()->where('id', 5)->where('name', 'like', 'a%')->to_sql();

    expect($sql)->toBe('SELECT * FROM `plt_user` WHERE `id` = ? AND `name` LIKE ?')
        ->and($bindings)->toBe([5, 'a%']);
});

it('takes a map of conditions as an AND of equalities', function () {
    list($sql, $bindings) = query_test_builder()->where(['status' => 1, 'type' => 2])->to_sql();

    expect($sql)->toBe('SELECT * FROM `plt_user` WHERE `status` = ? AND `type` = ?')
        ->and($bindings)->toBe([1, 2]);
});

it('takes a list of conditions, each with its own operator and boolean', function () {
    list($sql, $bindings) = query_test_builder()->where([['a', '>', 1], ['b', '=', 2, 'or']])->to_sql();

    expect($sql)->toBe('SELECT * FROM `plt_user` WHERE `a` > ? OR `b` = ?')
        ->and($bindings)->toBe([1, 2]);
});

it('wraps a closure in parentheses of its own', function () {
    list($sql, $bindings) = query_test_builder()
        ->where('x', 1)
        ->where(function (query $w) {
            $w->where('a', 1)->or_where('b', 2);
        })
        ->to_sql();

    expect($sql)->toBe('SELECT * FROM `plt_user` WHERE `x` = ? AND (`a` = ? OR `b` = ?)')
        ->and($bindings)->toBe([1, 1, 2]);
});

it('leaves out a closure that added no condition', function () {
    list($sql, $bindings) = query_test_builder()
        ->where('a', 1)
        ->where(function (query $w) {
        })
        ->to_sql();

    expect($sql)->toBe('SELECT * FROM `plt_user` WHERE `a` = ?')
        ->and($bindings)->toBe([1]);
});

it('turns a null value into IS NULL rather than a comparison that never matches', function () {
    list($sql, $bindings) = query_test_builder()
        ->where('a', null)
        ->where('b', '!=', null)
        ->where('c', 'is not', null)
        ->to_sql();

    expect($sql)->toBe('SELECT * FROM `plt_user` WHERE `a` IS NULL AND `b` IS NOT NULL AND `c` IS NOT NULL')
        ->and($bindings)->toBe([]);
});

it('turns an array value into IN', function () {
    list($sql, $bindings) = query_test_builder()->where('id', [1, 2, 3])->to_sql();

    expect($sql)->toBe('SELECT * FROM `plt_user` WHERE `id` IN (?, ?, ?)')
        ->and($bindings)->toBe([1, 2, 3]);
});

it('turns an array against an inequality into NOT IN', function () {
    list($sql, $bindings) = query_test_builder()->where('id', '<>', [1, 2])->to_sql();

    expect($sql)->toBe('SELECT * FROM `plt_user` WHERE `id` NOT IN (?, ?)')
        ->and($bindings)->toBe([1, 2]);
});

it('routes the in and not in operators to where_in', function () {
    list($in) = query_test_builder()->where('id', 'in', [1, 2])->to_sql();
    list($not) = query_test_builder()->where('id', 'NOT IN', [1, 2])->to_sql();

    expect($in)->toBe('SELECT * FROM `plt_user` WHERE `id` IN (?, ?)')
        ->and($not)->toBe('SELECT * FROM `plt_user` WHERE `id` NOT IN (?, ?)');
});

it('routes the between operators to where_between', function () {
    list($sql, $bindings) = query_test_builder()->where('age', 'between', [18, 30])->to_sql();
    list($not, $not_bindings) = query_test_builder()->where('age', 'not between', [18, 30])->to_sql();

    expect($sql)->toBe('SELECT * FROM `plt_user` WHERE `age` BETWEEN ? AND ?')
        ->and($bindings)->toBe([18, 30])
        ->and($not)->toBe('SELECT * FROM `plt_user` WHERE `age` NOT BETWEEN ? AND ?')
        ->and($not_bindings)->toBe([18, 30]);
});

it('refuses a between that was not given exactly two bounds', function () {
    query_test_builder()->where('age', 'between', [18]);
})->throws(InvalidArgumentException::class, 'BETWEEN takes exactly two values');

it('routes the find_in_set operator, putting the value first', function () {
    list($sql, $bindings) = query_test_builder()->where('tags', 'find_in_set', 'a')->to_sql();

    expect($sql)->toBe('SELECT * FROM `plt_user` WHERE FIND_IN_SET(?, `tags`)')
        ->and($bindings)->toBe(['a']);
});

it('compares two columns without binding either of them', function () {
    list($sql, $bindings) = query_test_builder()->where_column('a.x', '>', 'b.y')->to_sql();

    expect($sql)->toBe('SELECT * FROM `plt_user` WHERE `a`.`x` > `b`.`y`')
        ->and($bindings)->toBe([]);
});

it('passes a raw condition through, prefix expanded and bindings kept', function () {
    list($sql, $bindings) = query_test_builder()
        ->where('a', 1)
        ->where_raw('LENGTH(#PB#_user.name) > ?', [3])
        ->to_sql();

    expect($sql)->toBe('SELECT * FROM `plt_user` WHERE `a` = ? AND LENGTH(plt_user.name) > ?')
        ->and($bindings)->toBe([1, 3]);
});

it('joins the next condition with OR for or_where', function () {
    list($sql, $bindings) = query_test_builder()->where('a', 1)->or_where('b', 2)->to_sql();

    expect($sql)->toBe('SELECT * FROM `plt_user` WHERE `a` = ? OR `b` = ?')
        ->and($bindings)->toBe([1, 2]);
});

it('writes the constant an empty IN means instead of an empty list', function () {
    list($in) = query_test_builder()->where_in('id', [])->to_sql();
    list($not) = query_test_builder()->where_not_in('id', [])->to_sql();

    expect($in)->toBe('SELECT * FROM `plt_user` WHERE 1 = 0')
        ->and($not)->toBe('SELECT * FROM `plt_user` WHERE 1 = 1');
});

it('takes a subquery as the right hand side of IN', function () {
    $sub = query_test_builder('#PB#_log')->select('uid')->where('n', 1);

    list($sql, $bindings) = query_test_builder()->where('a', 0)->where_in('id', $sub)->to_sql();

    expect($sql)->toBe(
        'SELECT * FROM `plt_user` WHERE `a` = ? AND `id` IN (SELECT `uid` FROM `plt_log` WHERE `n` = ?)'
    )->and($bindings)->toBe([0, 1]);
});

it('writes IS NULL and IS NOT NULL for the null helpers', function () {
    list($sql) = query_test_builder()->where_null('a')->where_not_null('b')->to_sql();

    expect($sql)->toBe('SELECT * FROM `plt_user` WHERE `a` IS NULL AND `b` IS NOT NULL');
});

/*
|--------------------------------------------------------------------------
| join
|--------------------------------------------------------------------------
*/

it('reads both sides of a join condition as column names', function () {
    list($sql, $bindings) = query_test_builder()
        ->join('#PB#_profile AS p', 'p.uid', '=', 'u.uid')
        ->to_sql();

    expect($sql)->toBe('SELECT * FROM `plt_user` INNER JOIN `plt_profile` AS `p` ON (`p`.`uid` = `u`.`uid`)')
        ->and($bindings)->toBe([]);
});

it('binds a join condition marked as a value instead of quoting it', function () {
    list($sql, $bindings) = query_test_builder()
        ->join('#PB#_profile AS p', [['p.uid', '=', 'u.uid'], ['p.status', '=', 1, 'AND', 'value']])
        ->where('u.id', 7)
        ->to_sql();

    expect($sql)->toBe(
        'SELECT * FROM `plt_user` INNER JOIN `plt_profile` AS `p`'
        . ' ON (`p`.`uid` = `u`.`uid` AND `p`.`status` = ?) WHERE `u`.`id` = ?'
    )->and($bindings)->toBe([1, 7]);
});

it('writes the join type it was given', function () {
    list($left) = query_test_builder()->left_join('#PB#_p', 'a.id', '=', 'b.id')->to_sql();
    list($right) = query_test_builder()->right_join('#PB#_p', 'a.id', '=', 'b.id')->to_sql();
    list($outer) = query_test_builder()->join('#PB#_p', 'a.id', '=', 'b.id', 'left outer')->to_sql();
    list($cross) = query_test_builder()->join('#PB#_p', 'a.id', '=', 'b.id', 'cross')->to_sql();

    expect($left)->toContain(' LEFT JOIN `plt_p` ON (`a`.`id` = `b`.`id`)')
        ->and($right)->toContain(' RIGHT JOIN `plt_p` ON (`a`.`id` = `b`.`id`)')
        ->and($outer)->toContain(' LEFT OUTER JOIN `plt_p` ON (`a`.`id` = `b`.`id`)')
        ->and($cross)->toContain(' CROSS JOIN `plt_p` ON (`a`.`id` = `b`.`id`)');
});

it('leaves STRAIGHT_JOIN to stand on its own, without a second JOIN after it', function () {
    // STRAIGHT_JOIN is a join keyword, not a modifier on one: `STRAIGHT_JOIN JOIN` is a syntax
    // error to MySQL, and query::join() accepts the type, so the statement was unusable
    list($sql) = query_test_builder()->join('#PB#_p', 'a.id', '=', 'b.id', 'STRAIGHT_JOIN')->to_sql();

    expect($sql)->toContain(' STRAIGHT_JOIN `plt_p` ON (`a`.`id` = `b`.`id`)')
        ->and($sql)->not->toContain('STRAIGHT_JOIN JOIN');
});

it('refuses a join type that is not on the list', function () {
    query_test_builder()->join('#PB#_p', 'a.id', '=', 'b.id', 'sideways');
})->throws(InvalidArgumentException::class, "'SIDEWAYS' is not a join type");

/*
|--------------------------------------------------------------------------
| group by and having
|--------------------------------------------------------------------------
*/

it('collects group by from both varargs and arrays', function () {
    list($sql) = query_test_builder()->group_by('a', ['b', 'c'])->to_sql();

    expect($sql)->toBe('SELECT * FROM `plt_user` GROUP BY `a`, `b`, `c`');
});

it('moves what the where builder produced over to the havings', function () {
    $q = query_test_builder()->where('a', 1)->group_by('type')->having('total', '>', 5);

    list($sql, $bindings) = $q->to_sql();

    expect($sql)->toBe('SELECT * FROM `plt_user` WHERE `a` = ? GROUP BY `type` HAVING `total` > ?')
        ->and($bindings)->toBe([1, 5])
        // The where builder is borrowed, not shared: the condition must not be left in wheres too
        ->and($q->wheres)->toHaveCount(1)
        ->and($q->havings)->toHaveCount(1)
        ->and($q->wheres[0]['column'])->toBe('a')
        ->and($q->havings[0]['column'])->toBe('total');
});

it('takes two arguments and an OR for the having helpers', function () {
    list($sql, $bindings) = query_test_builder()
        ->group_by('t')
        ->having('a', 1)
        ->or_having('b', 2)
        ->having_raw('COUNT(*) > ?', [3])
        ->to_sql();

    expect($sql)->toBe('SELECT * FROM `plt_user` GROUP BY `t` HAVING `a` = ? OR `b` = ? AND COUNT(*) > ?')
        ->and($bindings)->toBe([1, 2, 3]);
});

/*
|--------------------------------------------------------------------------
| order, limit and offset
|--------------------------------------------------------------------------
*/

it('orders by columns, by an array and by a raw expression', function () {
    list($sql) = query_test_builder()
        ->order_by('a')
        ->order_by('b', 'DESC')
        ->order_by(['c' => 'desc', 'd'])
        ->order_by_raw('FIELD(#PB#_user.id, 1)')
        ->to_sql();

    expect($sql)->toBe(
        'SELECT * FROM `plt_user` ORDER BY `a` ASC, `b` DESC, `c` DESC, `d` ASC, FIELD(plt_user.id, 1)'
    );
});

it('writes LIMIT and OFFSET, page() counting from one', function () {
    list($limit) = query_test_builder()->limit(10)->offset(20)->to_sql();
    list($page) = query_test_builder()->page(3, 20)->to_sql();
    list($first) = query_test_builder()->page(1, 20)->to_sql();

    expect($limit)->toBe('SELECT * FROM `plt_user` LIMIT 10 OFFSET 20')
        ->and($page)->toBe('SELECT * FROM `plt_user` LIMIT 20 OFFSET 40')
        ->and($first)->toBe('SELECT * FROM `plt_user` LIMIT 20 OFFSET 0');
});

it('clamps a negative limit or offset to zero', function () {
    list($sql) = query_test_builder()->limit(-5)->offset(-1)->to_sql();

    expect($sql)->toBe('SELECT * FROM `plt_user` LIMIT 0 OFFSET 0');
});

it('lifts the limit again when it is set back to null', function () {
    list($sql) = query_test_builder()->limit(10)->limit(null)->to_sql();

    expect($sql)->toBe('SELECT * FROM `plt_user`');
});

/*
|--------------------------------------------------------------------------
| union
|--------------------------------------------------------------------------
*/

it('appends a union after the first statement, its bindings following the outer ones', function () {
    $sub = query_test_builder('#PB#_b')->select('id')->where('k', 9);

    list($sql, $bindings) = query_test_builder()->select('id')->where('k', 1)->union($sub)->to_sql();

    expect($sql)->toBe('SELECT `id` FROM `plt_user` WHERE `k` = ? UNION (SELECT `id` FROM `plt_b` WHERE `k` = ?)')
        ->and($bindings)->toBe([1, 9]);
});

it('takes a union as a string with bindings of its own', function () {
    list($sql, $bindings) = query_test_builder()
        ->select('id')
        ->union('SELECT id FROM x WHERE k = ?', false, [7])
        ->to_sql();

    expect($sql)->toBe('SELECT `id` FROM `plt_user` UNION (SELECT id FROM x WHERE k = ?)')
        ->and($bindings)->toBe([7]);
});

it('selects from the unions instead of from the table for as_union_table()', function () {
    $b = query_test_builder('#PB#_b')->select('id')->where('k', 9);
    $c = query_test_builder('#PB#_c')->select('id')->where('z', 3);

    list($sql, $bindings) = query_test_builder()
        ->select('id')
        ->union($b)
        ->union_all($c)
        ->as_union_table('u')
        ->where('id', '>', 0)
        ->to_sql();

    // The union bindings come first here, because the unions are now the FROM target
    expect($sql)->toBe(
        'SELECT `id` FROM ((SELECT `id` FROM `plt_b` WHERE `k` = ?)'
        . ' UNION ALL (SELECT `id` FROM `plt_c` WHERE `z` = ?)) AS `u` WHERE `id` > ?'
    )->and($bindings)->toBe([9, 3, 0]);
});

/*
|--------------------------------------------------------------------------
| select, from and the flags
|--------------------------------------------------------------------------
*/

it('selects every column when none was asked for', function () {
    list($sql) = query_test_builder()->to_sql();

    expect($sql)->toBe('SELECT * FROM `plt_user`');
});

it('spreads a one element array but reads a longer one as column and alias', function () {
    list($spread) = query_test_builder()->select(['a'])->to_sql();
    list($aliased) = query_test_builder()->select(['a.id', 'uid'])->to_sql();
    list($varargs) = query_test_builder()->select('a', 'b')->to_sql();

    expect($spread)->toBe('SELECT `a` FROM `plt_user`')
        ->and($aliased)->toBe('SELECT `a`.`id` AS `uid` FROM `plt_user`')
        ->and($varargs)->toBe('SELECT `a`, `b` FROM `plt_user`');
});

it('writes DISTINCT for distinct()', function () {
    list($sql) = query_test_builder()->distinct()->select('a')->to_sql();

    expect($sql)->toBe('SELECT DISTINCT `a` FROM `plt_user`')
        ->and(query_test_builder()->distinct()->distinct(false)->distinct)->toBeFalse();
});

it('takes the table from from(), with an alias as an array or a string', function () {
    list($string) = query_test_builder('')->from('#PB#_user AS u')->to_sql();
    list($array) = query_test_builder('')->from(['#PB#_user', 'u'])->to_sql();

    expect($string)->toBe('SELECT * FROM `plt_user` AS `u`')
        ->and($array)->toBe('SELECT * FROM `plt_user` AS `u`');
});

it('writes FORCE INDEX for the index hint, and drops it again for no names', function () {
    list($sql) = query_test_builder()->force_index('idx_a', 'idx_b')->to_sql();

    expect($sql)->toBe('SELECT * FROM `plt_user` FORCE INDEX (`idx_a`, `idx_b`)')
        ->and(query_test_builder()->force_index()->index_hint)->toBeNull();
});

it('sends a locking read to the write connection', function () {
    $update = query_test_builder()->lock_for_update();
    $shared = query_test_builder()->lock_shared();

    expect($update->to_sql()[0])->toBe('SELECT * FROM `plt_user` FOR UPDATE')
        ->and($update->use_master)->toBeTrue()
        ->and($shared->to_sql()[0])->toBe('SELECT * FROM `plt_user` LOCK IN SHARE MODE')
        ->and($shared->use_master)->toBeTrue();
});

it('records the flags the compiler and the connection read', function () {
    $q = query_test_builder()->ignore()->on_master()->option('final', true)->option('sample', '0.1');

    expect($q->ignore)->toBeTrue()
        ->and($q->use_master)->toBeTrue()
        ->and($q->options)->toBe(['final' => true, 'sample' => '0.1']);
});

/*
|--------------------------------------------------------------------------
| max_select_limit
|--------------------------------------------------------------------------
*/

it('takes the select ceiling from the connection config', function () {
    $db = connection::make('mysql', ['prefix' => 'plt', 'max_select_limit' => 300]);

    expect($db->table('#PB#_user')->to_sql()[0])->toBe('SELECT * FROM `plt_user` LIMIT 300')
        ->and(query_test_builder()->to_sql()[0])->toBe('SELECT * FROM `plt_user`');
});

it('lets the ceiling cap a larger limit but not raise a smaller one', function () {
    list($over) = query_test_builder()->limit(1000)->max_select_limit(300)->to_sql();
    list($under) = query_test_builder()->limit(10)->max_select_limit(300)->to_sql();

    expect($over)->toBe('SELECT * FROM `plt_user` LIMIT 300')
        ->and($under)->toBe('SELECT * FROM `plt_user` LIMIT 10');
});

it('lifts the ceiling again for max_select_limit(null)', function () {
    $db = connection::make('mysql', ['prefix' => 'plt', 'max_select_limit' => 300]);

    expect($db->table('#PB#_user')->max_select_limit(null)->to_sql()[0])->toBe('SELECT * FROM `plt_user`');
});

/*
|--------------------------------------------------------------------------
| to_raw_sql
|--------------------------------------------------------------------------
*/

it('puts the values back into the statement for to_raw_sql()', function () {
    $sql = query_test_builder()->where('name', "it's")->where('id', 5)->to_raw_sql();

    expect($sql)->toBe("SELECT * FROM `plt_user` WHERE `name` = 'it\\'s' AND `id` = 5");
});

it('keeps an expression with bindings of its own in placeholder order', function () {
    list($sql, $bindings) = query_test_builder()
        ->where('a', db::raw('IF(? > 1, ?, ?)', [1, 'y', 'n']))
        ->where('b', 2)
        ->to_sql();

    expect($sql)->toBe('SELECT * FROM `plt_user` WHERE `a` = IF(? > 1, ?, ?) AND `b` = ?')
        ->and($bindings)->toBe([1, 'y', 'n', 2]);
});
