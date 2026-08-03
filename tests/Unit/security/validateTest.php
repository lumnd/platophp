<?php
/**
 * validate: rule driven checking of a data set, with the messages.
 *
 * Every instance comes from make() and owns its data, so these cases assert on the instance rather
 * than on shared state -- that separation is the point of the class and is what the old singleton
 * could not give.
 */

use plato\http\req;
use plato\security\validate;

afterEach(function () {
    validate::reset_extensions();
    validate::reset_default_messages();
});

/*
|--------------------------------------------------------------------------
| Instances and data sources
|--------------------------------------------------------------------------
*/

it('gives every make() its own rules, data and errors', function () {
    $signup = validate::make(['email' => ''], ['email' => 'required']);
    $login  = validate::make(['email' => 'a@b.com'], ['email' => 'required']);

    expect($signup->fails())->toBeTrue()
        ->and($login->passes())->toBeTrue()
        ->and($login->errors())->toBe([]);

    // The failing one is still failing: neither instance reset or inherited the other's state
    expect($signup->errors())->toHaveKey('email');
});

it('checks the data it was handed whatever the request method is', function () {
    // The old class recorded no rules unless req::method() was POST, so run() answered true for a
    // json PUT -- the fields were never looked at
    $valid = validate::make(['email' => 'not-an-email'], ['email' => 'required|email']);

    expect($valid->fails())->toBeTrue()
        ->and($valid->errors())->toHaveKey('email');
});

it('reads every parameter set through from_request', function () {
    req::reset_input();
    $put = ['title' => ''];
    req::assign_values($put, 'PUT');

    // assign_values puts a non GET payload where req::all() will find it, which is the union the
    // validator sees
    expect(validate::from_request(['title' => 'required'])->fails())->toBeTrue();

    req::reset_input();
});

it('passes when no rules were set at all', function () {
    expect(validate::make(['anything' => 'goes'])->passes())->toBeTrue();
});

it('runs the rules once for the readers that need them', function () {
    // No explicit run(): errors() and validated() both have to answer for themselves
    $valid = validate::make(['age' => 'x'], ['age' => 'integer']);

    expect($valid->errors())->toHaveKey('age')
        ->and($valid->validated())->toBe([]);
});

it('drops the previous errors when run twice', function () {
    $valid = validate::make(['email' => ''], ['email' => 'required']);

    expect($valid->run())->toBeFalse()
        ->and($valid->run())->toBeFalse()
        ->and($valid->errors())->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Rule shapes
|--------------------------------------------------------------------------
*/

it('accepts the compact, the list and the row rule shapes', function () {
    $data = ['email' => 'a@b.com', 'age' => '20'];

    $compact = validate::make($data, ['email' => 'required|email']);
    $list    = validate::make($data, ['email' => ['required', 'email']]);
    $rows    = validate::make($data, [
        ['field' => 'email', 'label' => 'Email', 'rules' => 'required|email'],
        ['field' => 'age', 'label' => 'Age', 'rules' => 'required|integer|min[18]'],
    ]);

    expect($compact->passes())->toBeTrue()
        ->and($list->passes())->toBeTrue()
        ->and($rows->passes())->toBeTrue();
});

it('keeps a pipe inside a rule parameter out of the split', function () {
    $valid = validate::make(
        ['code' => 'ab'],
        ['code' => 'required|regex_match[/^(ab|cd)$/]']
    );

    expect($valid->passes())->toBeTrue();
});

it('takes a closure, and names it for the message with a two element rule', function () {
    $even = fn ($val) => ((int) $val % 2) === 0;

    $anonymous = validate::make(['n' => '3'], ['n' => [$even]]);
    $named     = validate::make(['n' => '3'], ['n' => [['even', $even]]]);

    expect($anonymous->error('n'))->toContain('Anonymous function')
        ->and($named->error('n'))->toContain('(even)');
});

it('registers a named rule with extend', function () {
    validate::extend('shouty', fn ($val) => $val === strtoupper($val));

    expect(validate::has_extension('shouty'))->toBeTrue()
        ->and(validate::make(['tag' => 'LOUD'], ['tag' => 'shouty'])->passes())->toBeTrue()
        ->and(validate::make(['tag' => 'quiet'], ['tag' => 'shouty'])->fails())->toBeTrue();
});

it('hands a registered rule its bracket parameter', function () {
    validate::extend('one_of', fn ($val, $param) => in_array($val, explode(',', $param), true));

    expect(validate::make(['size' => 'm'], ['size' => 'one_of[s,m,l]'])->passes())->toBeTrue()
        ->and(validate::make(['size' => 'xl'], ['size' => 'one_of[s,m,l]'])->fails())->toBeTrue();
});

it('fails a rule nobody can resolve, rather than skipping it', function () {
    $valid = validate::make(['a' => 'b'], ['a' => 'no_such_rule']);

    expect($valid->fails())->toBeTrue()
        ->and($valid->error('a'))->toContain('(no_such_rule)');
});

it('reports an unresolvable rule even on an empty field', function () {
    // callback_ was the pre 0.0.1 spelling, resolved through the call stack. A rule name that
    // matches nothing is a mistake in the rule set, so the empty value skip must not swallow it
    expect(validate::make(['a' => ''], ['a' => 'callback_check_it'])->fails())->toBeTrue()
        ->and(validate::make(['a' => ''], ['a' => 'maxlenght[5]'])->fails())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Ordering and empty values
|--------------------------------------------------------------------------
*/

it('reports the missing field rather than its length', function () {
    // required is hoisted, so maxlength does not get to answer first
    $valid = validate::make(['name' => ''], ['name' => 'maxlength[5]|required']);

    expect($valid->error('name'))->toBe('The name field is required.');
});

it('runs a plain function rule before required', function () {
    // '   ' is present as far as required is concerned, so trim has to happen first
    $valid = validate::make(['name' => '   '], ['name' => 'required|trim']);

    expect($valid->fails())->toBeTrue()
        ->and($valid->error('name'))->toBe('The name field is required.');
});

it('skips the rules that are not about presence when the value is empty', function () {
    // An absent optional field is not an error, and email never sees ''
    expect(validate::make(['nick' => ''], ['nick' => 'email|maxlength[3]'])->passes())->toBeTrue()
        ->and(validate::make([], ['nick' => 'email'])->passes())->toBeTrue();
});

it('runs an implicit registered rule on a missing value', function () {
    validate::extend('needed', fn ($val) => $val !== null && $val !== '', true);
    validate::extend('optional_check', fn () => false);

    expect(validate::make([], ['a' => 'needed'])->fails())->toBeTrue()
        ->and(validate::make([], ['a' => 'optional_check'])->passes())->toBeTrue();
});

it('reports one error per field, the first that failed', function () {
    $valid = validate::make(
        ['a' => '', 'b' => ''],
        ['a' => 'required|email', 'b' => 'required']
    );

    expect($valid->errors())->toHaveCount(2);
});

/*
|--------------------------------------------------------------------------
| Values out
|--------------------------------------------------------------------------
*/

it('answers the sanitized values without touching the input', function () {
    $_POST['name'] = '  Ada  ';
    $data = ['name' => '  Ada  ', 'note' => 'kept'];

    $valid = validate::make($data, ['name' => 'trim|required|maxlength[3]']);

    expect($valid->passes())->toBeTrue()
        ->and($valid->validated())->toBe(['name' => 'Ada'])
        ->and($_POST['name'])->toBe('  Ada  ')
        ->and($valid->data())->toBe($data);

    unset($_POST['name']);
});

it('leaves a failed field out of validated', function () {
    $valid = validate::make(
        ['ok' => 'a@b.com', 'bad' => 'nope'],
        ['ok' => 'email', 'bad' => 'email']
    );

    expect($valid->validated())->toBe(['ok' => 'a@b.com']);
});

it('compares one field against another with matches', function () {
    $rules = ['pass' => 'required', 'again' => 'required|matches[pass]'];

    expect(validate::make(['pass' => 'x1', 'again' => 'x1'], $rules)->passes())->toBeTrue()
        ->and(validate::make(['pass' => 'x1', 'again' => 'x2'], $rules)->fails())->toBeTrue();

    // A confirmation that is not there at all is a mismatch, not a skipped rule
    expect(validate::make(['pass' => 'x1'], ['pass' => 'required', 'again' => 'matches[pass]'])->fails())
        ->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Array fields
|--------------------------------------------------------------------------
*/

it('walks down to a nested field and gives it back nested', function () {
    $valid = validate::make(
        ['contacts' => [0 => ['email' => '  a@b.com ']]],
        ['contacts[0][email]' => 'trim|required|email']
    );

    expect($valid->passes())->toBeTrue()
        ->and($valid->validated())->toBe(['contacts' => [0 => ['email' => 'a@b.com']]]);
});

it('runs the rules over every element of an array field', function () {
    $rules = ['emails[]' => 'required|email'];

    expect(validate::make(['emails' => ['a@b.com', 'c@d.com']], $rules)->passes())->toBeTrue()
        ->and(validate::make(['emails' => ['a@b.com', 'nope']], $rules)->fails())->toBeTrue();
});

it('reports a missing nested field as missing', function () {
    $valid = validate::make(['contacts' => []], ['contacts[0][email]' => 'required']);

    expect($valid->fails())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The shape of a value: scalar, list and map
|--------------------------------------------------------------------------
*/

it('refuses a list where one value was declared', function () {
    // Without the rule the elements are offered 'required|maxlength[3]' one at a time, both pass,
    // and the field arrives as an array where a string was documented
    expect(validate::make(['name' => ['ok']], ['name' => 'required|maxlength[3]'])->passes())->toBeTrue()
        ->and(validate::make(['name' => ['ok']], ['name' => 'scalar|required|maxlength[3]'])->fails())->toBeTrue()
        ->and(validate::make(['name' => 'ok'], ['name' => 'scalar|required'])->passes())->toBeTrue();
});

it('asks a scalar field its other rules once the shape is right', function () {
    expect(validate::make(['age' => 'x'], ['age' => 'scalar|integer'])->error('age'))
        ->toBe('The age field must contain an integer.')
        ->and(validate::make(['name' => ['a']], ['name' => 'scalar'])->error('name'))
        ->toBe('The name field must be a single value.');
});

it('refuses objects and resources where a scalar was declared', function () {
    $resource = fopen('php://memory', 'r');

    expect(validate::make(['value' => new stdClass()], ['value' => 'scalar'])->fails())->toBeTrue()
        ->and(validate::make(['value' => $resource], ['value' => 'scalar'])->fails())->toBeTrue()
        ->and(validate::make(['value' => null], ['value' => 'scalar'])->passes())->toBeTrue()
        ->and(validate::make(['value' => null], ['value' => 'scalar|required'])->fails())->toBeTrue();

    fclose($resource);
});

it('wants a json array where a list was declared', function () {
    expect(validate::make(['tags' => ['a', 'b']], ['tags' => 'list'])->passes())->toBeTrue()
        ->and(validate::make(['tags' => 'a'], ['tags' => 'list'])->fails())->toBeTrue()
        ->and(validate::make(['tags' => ['x' => 'a']], ['tags' => 'list'])->fails())->toBeTrue()
        ->and(validate::make(['tags' => []], ['tags' => 'list'])->passes())->toBeTrue();
});

it('wants a json object where a map was declared', function () {
    expect(validate::make(['buyer' => ['name' => 'Ada']], ['buyer' => 'map'])->passes())->toBeTrue()
        ->and(validate::make(['buyer' => 'Ada'], ['buyer' => 'map'])->fails())->toBeTrue()
        ->and(validate::make(['buyer' => ['Ada']], ['buyer' => 'map'])->fails())->toBeTrue()
        // {} and [] are the same array once decoded, so an empty one is admitted either way
        ->and(validate::make(['buyer' => []], ['buyer' => 'map'])->passes())->toBeTrue();
});

it('asks required of the container itself, not of what is in it', function () {
    expect(validate::make([], ['tags' => 'list|required'])->error('tags'))
        ->toBe('The tags field is required.')
        ->and(validate::make(['tags' => []], ['tags' => 'list|required'])->fails())->toBeTrue()
        ->and(validate::make([], ['buyer' => 'map|required'])->fails())->toBeTrue()
        ->and(validate::make(['buyer' => []], ['buyer' => 'map|required'])->passes())->toBeTrue();
});

it('treats a blank container the same at the top level and below another field', function () {
    expect(validate::make(['tags' => ''], ['tags' => 'list'])->fails())->toBeTrue()
        ->and(validate::make(['root' => ['tags' => '']], ['root[tags]' => 'list'])->fails())->toBeTrue()
        ->and(validate::make(['buyer' => ''], ['buyer' => 'map'])->fails())->toBeTrue()
        ->and(validate::make(['root' => ['buyer' => '']], ['root[buyer]' => 'map'])->fails())->toBeTrue();
});

it('leaves the fields below a declared container that did not arrive alone', function () {
    $rules = ['buyer' => 'map', 'buyer[email]' => 'scalar|required|email'];

    // The optional object is absent: what it would have had to carry is not asked
    expect(validate::make([], $rules)->passes())->toBeTrue()
        ->and(validate::make(['buyer' => ['email' => 'a@b.com']], $rules)->passes())->toBeTrue()
        ->and(validate::make(['buyer' => []], $rules)->fails())->toBeTrue();

    // A form that declares only the nested name is unchanged: nothing above it said anything
    expect(validate::make([], ['buyer[email]' => 'required'])->fails())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| [*], the elements of an array field
|--------------------------------------------------------------------------
*/

it('names every element a [*] rule addresses', function () {
    $rules = ['items' => 'list|required', 'items[*][sku]' => 'scalar|required|maxlength[4]'];

    $valid = validate::make(['items' => [['sku' => 'a1'], ['sku' => 'toolong']]], $rules);

    // One name and one error per element, not one verdict for the whole array
    expect($valid->fails())->toBeTrue()
        ->and($valid->errors())->toHaveKey('items[1][sku]')
        ->and($valid->errors())->not->toHaveKey('items[0][sku]');

    expect(validate::make(['items' => [['sku' => 'a1'], ['sku' => 'b2']]], $rules)->passes())->toBeTrue();
});

it('does not read [*] as a key of the data', function () {
    // The old behaviour: the name was walked down literally, so every request failed on a key
    // nobody sent -- and the element rules were run against every property instead
    $valid = validate::make(['items' => [['sku' => 'a1']]], ['items[*][sku]' => 'required']);

    expect($valid->passes())->toBeTrue()
        ->and($valid->errors())->not->toHaveKey('items[*][sku]');
});

it('asserts nothing about elements that are not there', function () {
    expect(validate::make([], ['items[*][sku]' => 'required'])->passes())->toBeTrue()
        ->and(validate::make(['items' => []], ['items[*][sku]' => 'required'])->passes())->toBeTrue()
        ->and(validate::make(['items' => 'nope'], ['items[*][sku]' => 'required'])->passes())->toBeTrue();
});

it('expands [*] across lists only', function () {
    $data = ['items' => ['*' => ['sku' => 'a1'], 'a.b' => ['sku' => 'b2']]];
    $rules = ['items' => 'list', 'items[*][sku]' => 'required'];

    // Map keys are data, not field-name syntax. In particular, '*' must not recurse forever and a
    // dot must not be reinterpreted as another level when validated values are assembled.
    expect(validate::make($data, $rules)->errors())->toHaveKey('items')
        ->and(validate::make($data, ['items[*][sku]' => 'required'])->validated())->toBe([]);
});

it('reaches down both sides of a nested [*]', function () {
    $rules = ['orders[*][lines][*][qty]' => 'scalar|integer'];

    $data = ['orders' => [['lines' => [['qty' => '2'], ['qty' => 'x']]]]];

    expect(validate::make($data, $rules)->errors())->toHaveKey('orders[0][lines][1][qty]');
});

it('gives the elements back nested through validated', function () {
    $valid = validate::make(
        ['items' => [['sku' => ' a1 ']]],
        ['items[*][sku]' => 'trim|required']
    );

    expect($valid->passes())->toBeTrue()
        ->and($valid->validated())->toBe(['items' => [0 => ['sku' => 'a1']]]);
});

/*
|--------------------------------------------------------------------------
| Messages
|--------------------------------------------------------------------------
*/

it('prefers the per field message over the per rule one over the pack', function () {
    $from_pack = validate::make(['a' => ''], ['a' => 'required']);

    $per_rule = validate::make(['a' => ''], ['a' => 'required'], ['required' => 'rule level']);

    $per_field = validate::make(['a' => ''])
        ->set_message('required', 'rule level')
        ->set_rules('a', 'A', 'required', ['required' => 'field level']);

    expect($from_pack->error('a'))->toBe('The a field is required.')
        ->and($per_rule->error('a'))->toBe('rule level')
        ->and($per_field->error('a'))->toBe('field level');
});

it('fills the label and the parameter into the message', function () {
    $valid = validate::make(['pwd' => 'abc'], [
        ['field' => 'pwd', 'label' => 'Password', 'rules' => 'minlength[8]'],
    ]);

    expect($valid->error('pwd'))
        ->toBe('The Password field must be at least 8 characters in length.');
});

it('answers the first error when no field is named, and wraps it', function () {
    $valid = validate::make(['a' => ''], ['a' => 'required']);

    expect($valid->error())->toBe('The a field is required.')
        ->and($valid->error('a', '<i>', '</i>'))->toBe('<i>The a field is required.</i>')
        ->and($valid->error('b'))->toBe('');
});

it('says which way round min and max are', function () {
    expect(validate::make(['n' => '3'], ['n' => 'min[5]'])->error('n'))
        ->toContain('greater than or equal to 5')
        ->and(validate::make(['n' => '9'], ['n' => 'max[5]'])->error('n'))
        ->toContain('less than or equal to 5');
});

/*
|--------------------------------------------------------------------------
| Built in rules
|--------------------------------------------------------------------------
*/

it('treats 0 as a value that is present', function () {
    expect(validate::make(['n' => '0'], ['n' => 'required'])->passes())->toBeTrue()
        ->and(validate::make(['n' => 0], ['n' => 'required'])->passes())->toBeTrue();
});

it('checks the day itself, not just the shape of a date', function () {
    expect(validate::make(['d' => '2024-02-29'], ['d' => 'date'])->passes())->toBeTrue()
        ->and(validate::make(['d' => '2023-02-29'], ['d' => 'date'])->fails())->toBeTrue()
        ->and(validate::make(['d' => '2024-99-99'], ['d' => 'date'])->fails())->toBeTrue()
        ->and(validate::make(['d' => '2024-1-1'], ['d' => 'date'])->fails())->toBeTrue();
});

it('counts characters and not bytes for the length rules', function () {
    expect(validate::make(['s' => '中文字'], ['s' => 'exactlength[3]'])->passes())->toBeTrue()
        ->and(validate::make(['s' => '中文字'], ['s' => 'maxlength[2]'])->fails())->toBeTrue();
});

it('fails a length rule whose parameter is not a number', function () {
    expect(validate::make(['s' => 'abc'], ['s' => 'maxlength[x]'])->fails())->toBeTrue();
});

it('applies the numeric rules', function () {
    expect(validate::make(['n' => '-1.5'], ['n' => 'numeric'])->passes())->toBeTrue()
        ->and(validate::make(['n' => '1'], ['n' => 'decimal'])->fails())->toBeTrue()
        ->and(validate::make(['n' => '1.0'], ['n' => 'decimal'])->passes())->toBeTrue()
        ->and(validate::make(['n' => '-2'], ['n' => 'integer'])->passes())->toBeTrue()
        ->and(validate::make(['n' => '2.5'], ['n' => 'integer'])->fails())->toBeTrue();
});

it('applies the filter_var backed rules', function () {
    expect(validate::make(['u' => 'https://platophp.com'], ['u' => 'url'])->passes())->toBeTrue()
        ->and(validate::make(['u' => 'not a url'], ['u' => 'url'])->fails())->toBeTrue()
        ->and(validate::make(['i' => '::1'], ['i' => 'ip'])->passes())->toBeTrue()
        ->and(validate::make(['i' => 'abc1.2.3.4'], ['i' => 'ip'])->fails())->toBeTrue();
});

it('wants three character classes for a strong password', function () {
    expect(validate::make(['p' => 'Abcdef12'], ['p' => 'password_strong'])->passes())->toBeTrue()
        ->and(validate::make(['p' => 'abcdef12'], ['p' => 'password_strong'])->fails())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Locale specific rules are the application's, added through extend()
|--------------------------------------------------------------------------
*/

it('ships no locale specific rule', function () {
    // Locale-specific formats belong to the host application. Unregistered rules fail the field.
    foreach ( ['idcard', 'mobile', 'phone', 'chinese', 'username'] as $rule )
    {
        expect(validate::has_extension($rule))->toBeFalse()
            ->and(in_array($rule, validate::RULES, true))->toBeFalse()
            ->and(validate::make(['v' => '13800138000'], ['v' => $rule])->fails())->toBeTrue();
    }
});

it('takes a locale specific rule from the application', function () {
    validate::extend('cn_mobile', function ($val) {
        return (bool) preg_match('/^1[3-9]\d{9}$/', (string) $val);
    });

    expect(validate::make(['t' => '13800138000'], ['t' => 'cn_mobile'])->passes())->toBeTrue()
        ->and(validate::make(['t' => '12800138000'], ['t' => 'cn_mobile'])->fails())->toBeTrue();
});

it('takes one inline, without registering it at all', function () {
    $valid = validate::make(
        ['t' => '13800138000'],
        ['t' => [['cn_mobile', function ($val) { return (bool) preg_match('/^1[3-9]\d{9}$/', (string) $val); }]]]
    );

    expect($valid->passes())->toBeTrue()
        ->and(validate::has_extension('cn_mobile'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Messages are strings on the class, not a language pack
|--------------------------------------------------------------------------
*/

it('ships english messages for its own rules', function () {
    expect(validate::default_messages('required'))->toBe('The {field} field is required.')
        ->and(validate::default_messages('nothing_defines_this'))->toBeNull();
});

it('lets the application replace the shipped messages for the whole process', function () {
    validate::set_default_messages(['required' => '字段 {field} 不能为空']);

    $valid = validate::make([], ['email' => 'required']);

    expect($valid->fails())->toBeTrue()
        ->and($valid->error('email'))->toBe('字段 email 不能为空');
});

it('merges rather than replaces, so an override names only what it changes', function () {
    validate::set_default_messages(['required' => 'nope']);

    expect(validate::default_messages('required'))->toBe('nope')
        ->and(validate::default_messages('email'))->toBe('The {field} field must contain a valid email address.');
});

it('gives an application rule its message under its own name', function () {
    validate::extend('cn_mobile', fn ($val) => false);
    validate::set_default_messages(['cn_mobile' => '{field} 不是有效的手机号']);

    $valid = validate::make(['tel' => '123'], ['tel' => 'cn_mobile']);

    expect($valid->error('tel'))->toBe('tel 不是有效的手机号');
});

it('names the rule when nothing defines a message for it', function () {
    validate::extend('mystery', fn ($val) => false);

    expect(validate::make(['t' => 'x'], ['t' => 'mystery'])->error('t'))->toContain('(mystery)');
});

it('restores the shipped messages on reset', function () {
    validate::set_default_messages(['required' => 'nope']);
    validate::reset_default_messages();

    expect(validate::default_messages('required'))->toBe('The {field} field is required.');
});
