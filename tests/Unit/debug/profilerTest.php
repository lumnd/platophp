<?php

/**
 * plato\debug\profiler: keyword emphasis over already-highlighted SQL.
 *
 * This is the one piece of the panel that is logic rather than markup, and it is the piece that has
 * rotted twice without anyone noticing -- once by matching keywords inside longer words and inside
 * its own output, once by writing the separator of a multi-word keyword as `&nbsp;`, which stopped
 * being what highlight_string() emits in PHP 8.3. Neither failure was visible enough to report
 * itself, so both are pinned here.
 */

use plato\debug\profiler;
use plato\str;

/**
 * The method is protected because nothing outside the panel emphasises SQL. Reaching it by
 * reflection is deliberate: the alternative is widening the class's API for the benefit of a test.
 */
function emphasise(string $html): string
{
    $method = new ReflectionMethod(profiler::class, '_emphasise_sql');
    $method->setAccessible(true);

    return $method->invoke((new ReflectionClass(profiler::class))->newInstanceWithoutConstructor(), $html);
}

it('marks the keywords of a statement', function () {
    expect(emphasise('SELECT * FROM t WHERE a = 1 LIMIT 1'))
        ->toBe('<strong>SELECT</strong> * <strong>FROM</strong> t <strong>WHERE</strong> a = 1 <strong>LIMIT</strong> 1');
});

it('does not find a keyword inside a longer word', function () {
    // IN is in DISTINCT and in HAVING; a match there used to be made inside the <strong> a previous
    // pass had wrapped around the outer word
    expect(emphasise('SELECT DISTINCT a HAVING b'))
        ->toBe('<strong>SELECT</strong> <strong>DISTINCT</strong> a <strong>HAVING</strong> b');
});

it('marks ASC whole rather than the AS at the front of it', function () {
    expect(emphasise('ORDER BY a ASC'))->toBe('<strong>ORDER BY</strong> a <strong>ASC</strong>');
    expect(emphasise('ORDER BY a DESC'))->toBe('<strong>ORDER BY</strong> a <strong>DESC</strong>');
});

it('takes a multi-word keyword over its own parts', function () {
    expect(emphasise('a NOT IN (1) AND b NOT LIKE 2'))
        ->toBe('a <strong>NOT IN</strong> (1) <strong>AND</strong> b <strong>NOT LIKE</strong> 2');

    expect(emphasise('FROM a LEFT JOIN b ON b.id = a.id GROUP BY c'))
        ->toBe('<strong>FROM</strong> a <strong>LEFT JOIN</strong> b <strong>ON</strong> b.id = a.id <strong>GROUP BY</strong> c');
});

it('accepts either separator inside a multi-word keyword', function () {
    // highlight_string() emitted &nbsp; for a space before PHP 8.3 and a space from 8.3 on, and
    // this package supports both
    expect(emphasise('ORDER&nbsp;BY&nbsp;a'))->toBe('<strong>ORDER&nbsp;BY</strong>&nbsp;a');
});

it('leaves an identifier that merely spells a keyword alone', function () {
    // Lowercase is the only thing telling the column `count` apart from the function COUNT
    expect(emphasise('select * from t where a in (1)'))->toBe('select * from t where a in (1)');
    expect(emphasise('`count` = 1'))->toBe('`count` = 1');
    expect(emphasise('MAX_ROWS = 1'))->toBe('MAX_ROWS = 1');
});

it('never reaches inside a tag', function () {
    $html = '<span style="color: #007700" title="SELECT">SELECT</span>';

    expect(emphasise($html))
        ->toBe('<span style="color: #007700" title="SELECT"><strong>SELECT</strong></span>');
});

it('marks a keyword that highlight_code has already wrapped in a span', function () {
    $html = '<code><span style="color: #0000BB">SELECT </span><span style="color: #007700">* </span></code>';

    expect(emphasise($html))
        ->toBe('<code><span style="color: #0000BB"><strong>SELECT</strong> </span><span style="color: #007700">* </span></code>');
});

it('leaves SQL-looking text inside a string literal alone', function () {
    $html   = str::highlight_code('SELECT note FROM audit WHERE note = "DELETE FROM users"');
    $marked = emphasise($html);

    expect($marked)->toContain('<strong>SELECT</strong>')
        ->and($marked)->toContain('<strong>WHERE</strong>')
        ->and($marked)->not->toContain('<strong>DELETE</strong>')
        ->and($marked)->not->toContain('"<strong>FROM</strong>');
});

it('returns a statement with nothing to mark unchanged', function () {
    expect(emphasise(''))->toBe('');
    expect(emphasise('SHOW TABLES'))->toBe('SHOW TABLES');
});
