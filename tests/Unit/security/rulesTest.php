<?php

/**
 * plato\security\rules: the built-in rules, on their own.
 *
 * No validator, no data, no bootstrap — which is the point of them being a class of their own.
 * plato\security\validate is tested against the same rules through the validator; what is asserted
 * here is the predicate itself, including the edges a validator test would not reach for.
 */

use plato\security\rules;

it('tells an empty value from a falsy one', function () {
    $rules = new rules();

    foreach ([false, null, '', []] as $empty)
    {
        expect($rules->is_empty($empty))->toBeTrue();
        expect($rules->required($empty))->toBeFalse();
    }

    // The whole reason empty() is not usable here: 0 and '0' are values somebody typed
    foreach ([0, '0', 0.0, '0.0', ' '] as $value)
    {
        expect($rules->is_empty($value))->toBeFalse();
        expect($rules->required($value))->toBeTrue();
    }
});

it('compares against another field through the lookup it was given', function () {
    $rules = new rules(fn (string $field) => ['password' => 's3cret', 'blank' => null][$field] ?? null);

    expect($rules->matches('s3cret', 'password'))->toBeTrue()
        ->and($rules->matches('other', 'password'))->toBeFalse()
        // A field nobody filled in matches nothing, itself included
        ->and($rules->matches('', 'blank'))->toBeFalse()
        ->and($rules->matches('x', 'absent'))->toBeFalse();
});

it('matches nothing at all without a lookup', function () {
    expect((new rules())->matches('x', 'password'))->toBeFalse();
});

it('counts length in characters rather than bytes', function () {
    $rules = new rules();

    // Four characters, twelve bytes
    expect($rules->exactlength('中文汉字', 4))->toBeTrue()
        ->and($rules->maxlength('中文汉字', 4))->toBeTrue()
        ->and($rules->maxlength('中文汉字', 3))->toBeFalse()
        ->and($rules->minlength('中文汉字', 5))->toBeFalse();
});

it('fails a length rule whose parameter is not a number, rather than guessing', function () {
    $rules = new rules();

    foreach (['maxlength', 'minlength', 'exactlength'] as $rule)
    {
        expect($rules->$rule('abc', 'not a number'))->toBeFalse();
    }
});

it('refuses a non numeric value for min and max', function () {
    $rules = new rules();

    expect($rules->min(5, 1))->toBeTrue()
        ->and($rules->min('abc', 1))->toBeFalse()
        ->and($rules->max(5, 10))->toBeTrue()
        ->and($rules->max('abc', 10))->toBeFalse();
});

it('separates numeric, integer and decimal', function () {
    $rules = new rules();

    expect($rules->numeric('-1.5'))->toBeTrue()
        ->and($rules->numeric('1'))->toBeTrue()
        ->and($rules->numeric('1.'))->toBeFalse()
        ->and($rules->integer('-12'))->toBeTrue()
        ->and($rules->integer('1.0'))->toBeFalse()
        // 1 is a number without a decimal point, so it is not a decimal
        ->and($rules->decimal('1'))->toBeFalse()
        ->and($rules->decimal('1.0'))->toBeTrue();
});

it('checks a date against the calendar and not only against a pattern', function () {
    $rules = new rules();

    expect($rules->date('2024-02-29'))->toBeTrue()      // a leap year
        ->and($rules->date('2023-02-29'))->toBeFalse()  // not one
        ->and($rules->date('2024-02-30'))->toBeFalse()
        ->and($rules->date('2024-99-99'))->toBeFalse()
        ->and($rules->date('2024-2-9'))->toBeFalse()    // the shape is fixed
        ->and($rules->date(''))->toBeFalse();
});

it('asks a strong password for three character classes and says nothing about length', function () {
    $rules = new rules();

    expect($rules->password_strong('Ab1'))->toBeTrue()
        ->and($rules->password_strong('abcdefghij'))->toBeFalse()
        ->and($rules->password_strong('ABCDEF123'))->toBeFalse()
        ->and($rules->password_strong('Abcdefgh'))->toBeFalse();
});

it('validates urls, addresses and ips through filter_var', function () {
    $rules = new rules();

    expect($rules->url('https://platophp.com/a?b=1'))->not->toBeFalse()
        ->and($rules->url('not a url'))->toBeFalse()
        ->and($rules->email('a@example.com'))->not->toBeFalse()
        ->and($rules->email('a@@example.com'))->toBeFalse()
        ->and($rules->ip('127.0.0.1'))->not->toBeFalse()
        ->and($rules->ip('::1'))->not->toBeFalse()
        ->and($rules->ip('999.1.1.1'))->toBeFalse();
});

it('matches a pattern as it was written, delimiters included', function () {
    $rules = new rules();

    expect($rules->regex_match('abc', '/^[a-z]+$/'))->toBeTrue()
        ->and($rules->regex_match('ab1', '/^[a-z]+$/'))->toBeFalse();
});
