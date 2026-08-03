<?php
/**
 * cast: coercion of a request value to a type.
 *
 * The two things worth pinning are the boundaries of each type and the fact that nothing here
 * escapes -- a value comes back shaped, not rewritten.
 */

use plato\cast;

it('casts the scalar types', function () {
    expect(cast::to('42abc', 'int'))->toBe(42)
        ->and(cast::to('3.5', 'float'))->toBe(3.5)
        ->and(cast::to('0', 'bool'))->toBeFalse()
        ->and(cast::to('anything', 'bool'))->toBeTrue()
        ->and(cast::to(7, 'string'))->toBe('7');
});

it('never lets gt0 go below zero', function () {
    expect(cast::to('-5', 'gt0'))->toBe(0)
        ->and(cast::to('5', 'gt0'))->toBe(5);
});

it('returns the value untouched when no type is asked for', function () {
    expect(cast::to(' <b>kept</b> '))->toBe(' <b>kept</b> ');
});

it('leaves html and quotes alone, because encoding belongs at the output point', function () {
    // The removed filter::filter() answered '&lt;b&gt;hi&lt;/b&gt;' here, and swapped ASCII
    // parentheses for full width ones on its default type
    expect(cast::to('<b>hi</b>', 'string'))->toBe('<b>hi</b>')
        ->and(cast::to("O'Brien (2)", 'string'))->toBe("O'Brien (2)");
});

it('keeps null distinguishable from zero and empty string', function () {
    expect(cast::to(null, 'int'))->toBeNull()
        ->and(cast::to('', 'int'))->toBe(0);
});

it('trims a string before coercing it', function () {
    expect(cast::to('  spaced  ', 'string'))->toBe('spaced');
});

it('coerces an array element by element, recursively', function () {
    expect(cast::to(['1', ['2', '3']], 'int'))->toBe([1, [2, 3]]);
});

it('accepts an email under a long tld', function () {
    // filter::test_email()'s pattern ended in [a-z]{2,6}, so this address was silently blanked
    expect(cast::to('someone@example.technology', 'email'))->toBe('someone@example.technology');
});

it('accepts ipv6, not only ipv4', function () {
    expect(cast::to('2001:db8::1', 'ip'))->toBe('2001:db8::1')
        ->and(cast::to('192.168.0.1', 'ip'))->toBe('192.168.0.1')
        ->and(cast::to('abc1.2.3.4xyz', 'ip'))->toBe('');
});

it('blanks a malformed value, or throws when the caller asked to be told', function () {
    expect(cast::to('not-an-email', 'email'))->toBe('');
    expect(fn () => cast::to('not-an-email', 'email', true))->toThrow(Exception::class);
});

it('does not throw on an absent email even when asked to, because required is validate\'s job', function () {
    expect(cast::to('', 'email', true))->toBe('');
});

it('strips what var and hash do not allow', function () {
    expect(cast::to('a_b-c.d', 'var'))->toBe('a_bcd')
        ->and(cast::to('a_b-c.d', 'hash'))->toBe('abcd');
});

it('refuses a type it does not know instead of silently applying another one', function () {
    // Neither misspellings nor arbitrary PHP function names are valid cast types.
    expect(fn () => cast::to('id', 'strng'))->toThrow(Exception::class)
        ->and(fn () => cast::to('whoami', 'exec'))->toThrow(Exception::class);
});
