<?php
/**
 * str: format detection, random strings, code highlighting and control-character stripping.
 */

use plato\str;

/**
 * Probe for the is_serialized() test: unserializing it must not run __wakeup().
 */
class strTestWakeupProbe
{
    public static $woken = false;

    public function __wakeup(): void
    {
        static::$woken = true;
    }
}

it('recognises json', function () {
    expect(str::is_json('{"a":1}'))->toBeTrue();
    expect(str::is_json('[1,2,3]'))->toBeTrue();
    expect(str::is_json('"plato"'))->toBeTrue();
    expect(str::is_json('null'))->toBeTrue();
});

it('rejects what is not json', function () {
    expect(str::is_json(''))->toBeFalse();
    expect(str::is_json('{"a":1'))->toBeFalse();
    expect(str::is_json('plato'))->toBeFalse();
});

it('recognises serialized values, including the false that looks like a failure', function () {
    expect(str::is_serialized(serialize(['a' => 1])))->toBeTrue();
    expect(str::is_serialized(serialize(null)))->toBeTrue();
    expect(str::is_serialized('b:0;'))->toBeTrue();
    expect(str::is_serialized(serialize(false)))->toBeTrue();
});

it('rejects what is not serialized', function () {
    expect(str::is_serialized(''))->toBeFalse();
    expect(str::is_serialized('plato'))->toBeFalse();
    expect(str::is_serialized('123'))->toBeFalse();
});

it('detects a serialized object without constructing it', function () {
    strTestWakeupProbe::$woken = false;

    expect(str::is_serialized(serialize(new strTestWakeupProbe())))->toBeTrue();
    expect(strTestWakeupProbe::$woken)->toBeFalse();
});

it('builds a random string of the requested length from the requested pool', function ($type, $pattern) {
    $str = str::random($type, 24);

    expect(strlen($str))->toBe(24);
    expect($str)->toMatch($pattern);
})->with([
    ['alnum', '/^[0-9a-zA-Z]{24}$/'],
    ['alpha', '/^[a-zA-Z]{24}$/'],
    ['numeric', '/^[0-9]{24}$/'],
    ['nozero', '/^[1-9]{24}$/'],
    ['distinct', '/^[2345679ACDEFHJKLMNPRSTUVWXYZ]{24}$/'],
    ['hexdec', '/^[0-9a-f]{24}$/'],
]);

it('builds the fixed-width random types', function () {
    expect(str::random('basic'))->toMatch('/^[0-9]+$/');
    expect(str::random('sha1'))->toMatch('/^[0-9a-f]{40}$/');
    // 8-4-4-4-12, version 4, variant 10xx
    expect(str::random('uuid'))->toMatch('/^[0-9a-f]{8}(-[0-9a-f]{4}){2}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});

it('does not draw random strings from the deterministic mt_rand state', function () {
    try
    {
        mt_srand(42);
        $first = str::random('alnum', 32);

        mt_srand(42);
        $second = str::random('alnum', 32);

        expect($second)->not->toBe($first);
    }
    finally
    {
        mt_srand();
    }
});

it('honours the requested length for the unique type, capped at the md5 width', function () {
    expect(strlen(str::random('unique')))->toBe(16);
    expect(strlen(str::random('unique', 20)))->toBe(20);
    expect(strlen(str::random('unique', 32)))->toBe(32);
    expect(strlen(str::random('unique', 40)))->toBe(32);
});

it('refuses a random type it does not know', function () {
    str::random('web', 16);
})->throws(InvalidArgumentException::class);

it('normalises the highlighter markup the same way whatever the php version', function () {
    // PHP versions wrap highlighted output differently; the public result stays consistent.
    $out = str::highlight_code("\$a = 1;\nif (\$a) { echo 'hi'; }\n");

    expect($out)->toStartWith('<code>');
    expect($out)->toEndWith('</code>');
    expect($out)->not->toContain('<pre>');
});

it('puts the tokens the highlighter would eat back the way they were', function () {
    $out = str::highlight_code('<?php echo "a\\nb"; ?>');

    expect($out)->toContain('&lt;?');
    expect($out)->toContain('?&gt;');
    expect($out)->toContain('\\n');
});

it('strips control characters but keeps tab, newline and carriage return', function () {
    expect(str::remove_invisible_characters("pl\x00a\x08to"))->toBe('plato');
    expect(str::remove_invisible_characters("a\tb\nc\r\nd"))->toBe("a\tb\nc\r\nd");
    expect(str::remove_invisible_characters(''))->toBe('');
});

it('strips the url encoded forms and the sequences a first pass would re-form', function () {
    expect(str::remove_invisible_characters('pl%00a%7fto'))->toBe('plato');
    // Removing the inner %00 joins the leftovers into a new one, which needs a second pass
    expect(str::remove_invisible_characters('%%0000'))->toBe('');
    expect(str::remove_invisible_characters('pl%00ato', false))->toBe('pl%00ato');
});

/* Unique ids: unique_id(). */

it('builds a 19 digit id that fits a signed bigint', function () {
    $id = str::unique_id();

    expect($id)->toMatch('/^[0-9]{19}$/');
    expect($id)->toStartWith(date('ymdHi'));
    // The width is the whole point: 19 digits is a mysql bigint, so the column is a number.
    // Both sides are 19 digits, so comparing them as strings compares them as numbers
    expect(strcmp($id, '9223372036854775807'))->toBeLessThan(0);
});

it('carries the process slot, so two processes do not share a lane by default', function () {
    $id = str::unique_id();

    expect(substr($id, 12, 3))->toBe(str_pad((string) (getmypid() % 1000), 3, '0', STR_PAD_LEFT));
});

it('never repeats within one process', function () {
    // The 10001st call crosses the counter's whole range. It must wait for a new timestamp rather
    // than return the first value again
    $ids = [];

    for ($i = 0; $i < 10001; $i++)
    {
        $ids[str::unique_id()] = true;
    }

    expect(count($ids))->toBe(10001);
});

it('does not restart the counter each second', function () {
    // A counter reset on the second boundary would hand out the same low values every second,
    // which is exactly the collision the process slot cannot cover
    $first = (int) substr(str::unique_id(), -4);
    $ids   = [];

    for ($i = 0; $i < 5; $i++)
    {
        $ids[] = (int) substr(str::unique_id(), -4);
    }

    expect($ids)->toBe(array_map(fn ($n) => ($first + $n) % 10000, [1, 2, 3, 4, 5]));
});

/* Placeholders: format(). */

it('substitutes placeholders by name', function () {
    expect(str::format('hi {name}, {n} unread', ['name' => 'plato', 'n' => 3]))
        ->toBe('hi plato, 3 unread');
    expect(str::format('{a}{a}{a}', ['a' => 'x']))->toBe('xxx');
    expect(str::format('nothing to do', ['a' => 1]))->toBe('nothing to do');
    expect(str::format('{a}'))->toBe('{a}');
});

it('leaves a placeholder with no value standing', function () {
    // Blanking it would delete text on a typo and leave nothing to notice
    expect(str::format('hi {name}, {missing}', ['name' => 'plato']))->toBe('hi plato, {missing}');
});

it('skips a value with no string form, and writes null as empty', function () {
    $out = str::format('{arr}|{obj}|{null}|{num}', [
        'arr'  => [1, 2],
        'obj'  => new stdClass(),
        'null' => null,
        'num'  => 1.5,
    ]);

    expect($out)->toBe('{arr}|{obj}||1.5');
});

it('takes the string form of an object that has one', function () {
    $value = new class {
        public function __toString(): string
        {
            return 'stringified';
        }
    };

    expect(str::format('{v}', ['v' => $value]))->toBe('stringified');
});

it('substitutes simultaneously, so one value cannot feed the next', function () {
    // A sequential str_replace would go on to replace the {b} this value introduced
    expect(str::format('{a}', ['a' => '{b}', 'b' => 'leaked']))->toBe('{b}');
});

/* Masking: mask(). */

it('keeps the ends and covers the middle', function () {
    expect(str::mask('13800138000', 3, 4))->toBe('138****8000');
    expect(str::mask('nb@example.com', 2, 11))->toBe('nb*example.com');
    expect(str::mask('abcdef', 0, 0))->toBe('******');
    expect(str::mask('abcdef', 2, 0))->toBe('ab****');
    expect(str::mask('abcdef', 0, 2))->toBe('****ef');
});

it('counts characters and not bytes', function () {
    expect(str::mask('张三丰', 1, 1))->toBe('张*丰');
});

it('masks a value too short to keep that much of, rather than exposing it', function () {
    expect(str::mask('12', 3, 4))->toBe('**');
    // Exactly as long as what was asked to be kept still masks: nothing would be covered otherwise
    expect(str::mask('abcdef', 3, 3))->toBe('******');
    expect(str::mask(''))->toBe('');
});

it('treats a negative keep as none, and takes any mask string', function () {
    expect(str::mask('abcdef', -3, 2))->toBe('****ef');
    expect(str::mask('abcdef', 2, 2, '.'))->toBe('ab..ef');
});

it('refuses an empty mask, which would truncate instead of cover', function () {
    str::mask('13800138000', 3, 4, '');
})->throws(InvalidArgumentException::class);

/* Byte counts: format_size(). */

it('scales a byte count to a unit', function () {
    expect(str::format_size(512))->toBe('512 b');
    expect(str::format_size(1536))->toBe('1.5 kb');
    expect(str::format_size(1048576))->toBe('1 mb');
    expect(str::format_size(1073741824))->toBe('1 gb');
    expect(str::format_size(1536, 3))->toBe('1.5 kb');
    expect(str::format_size(1600, 3))->toBe('1.563 kb');
});

it('reports a zero or negative span in bytes instead of dividing by zero', function () {
    // Zero and negative values must not enter logarithmic scaling.
    expect(str::format_size(0))->toBe('0 b');
    expect(str::format_size(-2048))->toBe('-2048 b');
});

it('stops at the largest unit it knows', function () {
    expect(str::format_size(1024 ** 6))->toBe('1024 pb');
});

/* Sharding: bucket(). */

it('maps a string into range, the same way every time', function () {
    $one = str::bucket('user-7', 64);

    expect($one)->toBeInt()->toBeGreaterThanOrEqual(0)->toBeLessThan(64);
    expect(str::bucket('user-7', 64))->toBe($one);
    expect(str::bucket('', 64))->toBeGreaterThanOrEqual(0);
    expect(str::bucket('user-7', 1))->toBe(0);
});

it('scatters consecutive ids instead of lining them up', function () {
    // The reason this hashes rather than taking a modulo: sequential ids must not fill one
    // bucket before touching the next
    $buckets = [];

    for ($i = 1; $i <= 200; $i++)
    {
        $buckets[str::bucket('user-' . $i, 8)] = true;
    }

    expect(count($buckets))->toBe(8);
});

it('refuses a bucket count below one', function () {
    str::bucket('user-7', 0);
})->throws(InvalidArgumentException::class);
