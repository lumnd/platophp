<?php
/**
 * arr: dot-notated array access and recursive merge.
 */

use plato\arr;

it('reads a nested key by walking the dots', function () {
    $data = ['database' => ['master' => ['host' => '127.0.0.1']]];

    expect(arr::get($data, 'database.master.host'))->toBe('127.0.0.1');
    expect(arr::get($data, 'database.master.port', 3306))->toBe(3306);
});

it('prefers a literal key over walking it', function () {
    // A key that contains dots still resolves, because the direct lookup comes first
    expect(arr::get(['a.b' => 1, 'a' => ['b' => 2]], 'a.b'))->toBe(1);
});

it('reads a list of keys at once', function () {
    $data = ['a' => 1, 'b' => ['c' => 2]];

    expect(arr::get($data, ['a', 'b.c']))->toBe(['a' => 1, 'b.c' => 2]);
});

it('creates the missing levels when writing', function () {
    $data = [];
    arr::set($data, 'log.file.level', 'debug');

    expect($data)->toBe(['log' => ['file' => ['level' => 'debug']]]);
});

it('removes a nested key and leaves its siblings', function () {
    $data = ['log' => ['file' => 1, 'redis' => 2]];

    expect(arr::del($data, 'log.file'))->toBeTrue();
    expect($data)->toBe(['log' => ['redis' => 2]]);
    expect(arr::del($data, 'log.missing'))->toBeFalse();
});

it('reports whether a nested key exists, null value included', function () {
    $data = ['a' => ['b' => null]];

    expect(arr::key_exists($data, 'a.b'))->toBeTrue();
    expect(arr::key_exists($data, 'a.c'))->toBeFalse();
});

it('tells a list apart from a map', function () {
    expect(arr::is_assoc(['a', 'b']))->toBeFalse();
    expect(arr::is_assoc([]))->toBeFalse();
    expect(arr::is_assoc(['a' => 1]))->toBeTrue();
    // Gaps and reordering both break the 0..n-1 run
    expect(arr::is_assoc([1 => 'a', 0 => 'b']))->toBeTrue();
});

it('keeps only the keys carrying a prefix', function () {
    $data = ['db_host' => 'h', 'db_port' => 1, 'app' => 'x'];

    expect(arr::filter_prefixed($data, 'db_'))->toBe(['host' => 'h', 'port' => 1]);
    expect(arr::filter_prefixed($data, 'db_', false))->toBe(['db_host' => 'h', 'db_port' => 1]);
});

it('merges recursively, appending a colliding numeric key', function () {
    $merged = arr::merge(['a' => ['x' => 1], 'list' => [1]], ['a' => ['y' => 2], 'list' => [2]]);

    expect($merged['a'])->toBe(['x' => 1, 'y' => 2]);
    expect($merged['list'])->toBe([1, 2]);
});

it('merges recursively, overwriting a colliding numeric key', function () {
    // What config overlaying needs: an application entry replaces the framework one
    $merged = arr::merge_assoc(['a' => ['x' => 1], 'list' => [1]], ['a' => ['y' => 2], 'list' => [2]]);

    expect($merged['a'])->toBe(['x' => 1, 'y' => 2]);
    expect($merged['list'])->toBe([2]);
});

it('refuses a non-array argument when merging', function () {
    // The variadic signature rejects these as a TypeError.
    expect(fn () => arr::merge(['a'], 'b'))->toThrow(TypeError::class);
    expect(fn () => arr::merge_assoc('a', ['b']))->toThrow(TypeError::class);
});

it('matches a prefix literally rather than as a regex', function () {
    expect(arr::filter_prefixed(['a.b' => 1, 'axb' => 2], 'a.'))->toBe(['b' => 1]);
    expect(arr::filter_prefixed(['x[1]_k' => 1], 'x[1]_'))->toBe(['k' => 1]);
    expect(arr::filter_prefixed(['a/b_k' => 1], 'a/b_'))->toBe(['k' => 1]);
});

it('reads a key holding null as present rather than missing', function () {
    $data = ['a' => ['b' => null]];

    expect(arr::get($data, 'a.b', 'fallback'))->toBeNull();
    expect(arr::key_exists($data, 'a.b'))->toBeTrue();
});

it('merges more than two arrays, left to right', function () {
    $merged = arr::merge_assoc(['a' => 1], ['a' => 2, 'b' => 1], ['b' => 2]);

    expect($merged)->toBe(['a' => 2, 'b' => 2]);
});

/*
 * Row list reshaping: group_by / pluck / sort / tree.
 */

it('groups rows by a field, keeping every row in its group', function () {
    $rows = [
        ['id' => 1, 'dept' => 'sales'],
        ['id' => 2, 'dept' => 'ops'],
        ['id' => 3, 'dept' => 'sales'],
    ];

    $groups = arr::group_by($rows, 'dept');

    expect(array_keys($groups))->toBe(['sales', 'ops']);
    expect(arr::pluck($groups['sales'], 'id'))->toBe([1, 3]);
    expect($groups['ops'])->toHaveCount(1);
});

it('groups by a nested field and by a callback', function () {
    $rows = [
        ['user' => ['city' => 'HK'], 'n' => 3],
        ['user' => ['city' => 'SG'], 'n' => 8],
    ];

    expect(array_keys(arr::group_by($rows, 'user.city')))->toBe(['HK', 'SG']);

    $by_size = arr::group_by($rows, fn ($row) => $row['n'] > 5 ? 'big' : 'small');
    expect(array_keys($by_size))->toBe(['small', 'big']);
});

it('drops a row that does not carry the grouping field at all', function () {
    // Grouping these under '' would merge them with the rows whose value really is ''
    $groups = arr::group_by([['dept' => ''], ['id' => 9], ['dept' => null]], 'dept');

    expect($groups)->toHaveCount(1);
    expect($groups[''])->toBe([['dept' => '']]);
});

it('plucks a column, and keys it by another field', function () {
    $rows = [['id' => 7, 'name' => 'a'], ['id' => 9, 'name' => 'b']];

    expect(arr::pluck($rows, 'name'))->toBe(['a', 'b']);
    expect(arr::pluck($rows, 'name', 'id'))->toBe([7 => 'a', 9 => 'b']);
    // A null value field keeps the whole row, which is how a list gets re-keyed
    expect(arr::pluck($rows, null, 'id'))->toBe([7 => $rows[0], 9 => $rows[1]]);
});

it('plucks a nested field, which array_column cannot reach', function () {
    $rows = [['user' => ['id' => 1]], ['user' => ['id' => 2]]];

    expect(arr::pluck($rows, 'user.id'))->toBe([1, 2]);
});

it('neither deduplicates nor drops empty values while plucking', function () {
    $rows = [['id' => 1], ['id' => 0], ['id' => 1], ['id' => null], ['name' => 'no id here']];

    // The row missing the field is skipped, the way array_column skips it; nothing else is
    expect(arr::pluck($rows, 'id'))->toBe([1, 0, 1, null]);
});

it('sorts rows by a field in both directions', function () {
    $rows = [['n' => 3], ['n' => 1], ['n' => 2]];

    expect(arr::pluck(arr::sort($rows, 'n'), 'n'))->toBe([1, 2, 3]);
    expect(arr::pluck(arr::sort($rows, 'n', 'desc'), 'n'))->toBe([3, 2, 1]);
});

it('sorts by several keys, left to right', function () {
    $rows = [
        ['dept' => 'b', 'age' => 30],
        ['dept' => 'a', 'age' => 20],
        ['dept' => 'b', 'age' => 40],
        ['dept' => 'a', 'age' => 50],
    ];

    $sorted = arr::sort($rows, ['dept' => 'asc', 'age' => 'desc']);

    expect(arr::pluck($sorted, 'dept'))->toBe(['a', 'a', 'b', 'b']);
    expect(arr::pluck($sorted, 'age'))->toBe([50, 20, 40, 30]);
});

it('keeps equal rows in the order they arrived', function () {
    // usort has been stable since PHP 8.0
    $rows = [['k' => 1, 'tag' => 'first'], ['k' => 1, 'tag' => 'second'], ['k' => 0, 'tag' => 'third']];

    expect(arr::pluck(arr::sort($rows, 'k'), 'tag'))->toBe(['third', 'first', 'second']);
});

it('refuses a sort direction that is neither asc nor desc', function () {
    // Silently treating a typo as ascending sorts the wrong way round and says nothing
    expect(fn () => arr::sort([['n' => 1]], 'n', 'descending'))
        ->toThrow(InvalidArgumentException::class);
});

it('builds a tree out of a flat id and parent list', function () {
    $rows = [
        ['id' => 1, 'pid' => 0, 'name' => 'root'],
        ['id' => 2, 'pid' => 1, 'name' => 'child'],
        ['id' => 3, 'pid' => 2, 'name' => 'grandchild'],
        ['id' => 4, 'pid' => 0, 'name' => 'second root'],
    ];

    $tree = arr::tree($rows);

    expect($tree)->toHaveCount(2);
    expect($tree[0]['name'])->toBe('root');
    expect($tree[0]['children'][0]['name'])->toBe('child');
    expect($tree[0]['children'][0]['children'][0]['name'])->toBe('grandchild');
    // A leaf still carries the key, so a template can loop over it without checking
    expect($tree[0]['children'][0]['children'][0]['children'])->toBe([]);
    expect($tree[1]['children'])->toBe([]);
});

it('treats a database string parent id the same as the integer root', function () {
    $tree = arr::tree([['id' => '1', 'pid' => '0'], ['id' => '2', 'pid' => '1']]);

    expect($tree)->toHaveCount(1);
    expect($tree[0]['children'])->toHaveCount(1);
});

it('promotes an orphan to the top instead of losing it', function () {
    // What a filtered query looks like: node 5's parent was filtered out of the result set
    $tree = arr::tree([['id' => 1, 'pid' => 0], ['id' => 5, 'pid' => 99]]);

    expect(arr::pluck($tree, 'id'))->toBe([1, 5]);
});

it('drops rows whose parents form a cycle rather than recursing forever', function () {
    $tree = arr::tree([['id' => 1, 'pid' => 0], ['id' => 2, 'pid' => 3], ['id' => 3, 'pid' => 2]]);

    expect(arr::pluck($tree, 'id'))->toBe([1]);
});

it('survives two rows sharing an id, one of them its own parent', function () {
    // Nothing stops a query from returning this, and without the path check node 1 would be
    // its own child all the way down
    $tree = arr::tree([['id' => 1, 'pid' => 0], ['id' => 1, 'pid' => 1]]);

    expect($tree)->toHaveCount(1);
    expect($tree[0]['children'])->toBe([]);
});

it('takes its own field names for the tree', function () {
    $rows = [
        ['uid' => 'a', 'parent' => null, 'label' => 'top'],
        ['uid' => 'b', 'parent' => 'a', 'label' => 'under'],
    ];

    $tree = arr::tree($rows, 'uid', 'parent', 'kids', null);

    expect($tree)->toHaveCount(1);
    expect($tree[0]['kids'][0]['label'])->toBe('under');
});
