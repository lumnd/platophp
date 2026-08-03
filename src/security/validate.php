<?php

/**
 * Rule driven validation of a data set, with the error messages
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\security;

use plato\arr;
use plato\http\req;
use plato\log;

/**
 * Checks a data set against rules, and answers what was wrong with it.
 *
 * This is the half that **reports**; plato\cast is the half that **coerces**. Use this one when
 * the user has to be told what to fix.
 *
 *     $valid = validate::make(req::all(), [
 *         'email' => 'required|email',
 *         'again' => 'required|matches[email]',
 *     ]);
 *
 *     if ( $valid->fails() )
 *     {
 *         echo $valid->error('email');   // one field
 *         print_r($valid->errors());     // all of them
 *     }
 *
 *     $clean = $valid->validated();      // the fields that passed, after any sanitizing rule
 *
 * **Every call to make() is a new instance holding its own data, rules and errors**, so two forms
 * in one request cannot see each other and nothing has to be reset between requests. The same
 * applies inside a resident worker (queue / socket server), where a shared instance would have carried
 * one message's errors into the next.
 *
 * **The data set is always given.** Nothing is read from the request unless the caller says so
 * with from_request(), which takes req::all() -- GET, POST and the PUT / PATCH / DELETE bodies
 * alike. A rule set is therefore checked against whatever it is handed, and never silently passes
 * because the request method was not the expected one.
 *
 * Nothing is written back either: $_POST and req::$posts are left as they arrived, and the values
 * a sanitizing rule produced are read with validated().
 *
 * Rules are a `|` separated string, or an array of the same items. Each is one of:
 *
 * | Form                  | Meaning |
 * | ---                   | --- |
 * | `required`            | A rule method on this class, below |
 * | `maxlength[30]`       | The same with a parameter |
 * | `my_rule`             | A rule registered with validate::extend() |
 * | `'trim'`              | Any function; its return value replaces the value unless it is a bool |
 * | `$closure`            | Any callable, called with the value |
 * | `['name', $callable]` | The same, with a name to look the error message up under |
 *
 * A rule that answers false stops that field and records one error. Rules answering anything
 * else replace the value with what they returned, which is how `trim` works as a rule and what
 * validated() gives back.
 *
 * Order is decided here, not by the caller: plain functions used as rules run first (a `trim` has
 * to happen before `required` sees the value), then `required`, then the rest as they were given.
 *
 * A missing or empty value is only offered to the rules that are about presence -- `required` and
 * `matches`, plus anything registered with `extend($name, $rule, true)`. Every other rule is
 * skipped rather than run against ''.
 *
 * The rules this class implements: `required` `matches[field]` `regex_match[/re/]` `minlength[n]`
 * `maxlength[n]` `exactlength[n]` `min[n]` `max[n]` `numeric` `integer` `decimal` `url` `email`
 * `ip` `date` `password_strong`, plus the three container rules below.
 *
 * **A json body is checked the same way, once the shape is a rule of its own.** Rules are run
 * against values, and an array field offers its elements the rules one at a time -- which is right
 * for `emails[]` off a form, and says nothing about the array itself. So a field declared as one
 * value accepted a list, a field declared as a list accepted a string, and a rule that only ever
 * applies inside an array could not be named at all. Two spellings answer that:
 *
 *     validate::make(req::json(), [
 *         'name'         => 'scalar|required',   // one value, and a list is not one
 *         'tags'         => 'list',              // a json array
 *         'buyer'        => 'map|required',      // a json object
 *         'buyer[email]' => 'scalar|email',      // asked only when buyer arrived
 *         'items[*][sku]' => 'scalar|required',  // every element, named one by one
 *     ]);
 *
 * `scalar`, `list` and `map` are decided before the elements are walked into, and are the field's
 * whole verdict: a list answers for being a list and for `required`, and what is in it is described
 * by naming the elements. A scalar is a PHP scalar; null remains an absent value for `required` to
 * decide. `[*]` is resolved only across a list when the rules are set, so `items[*][sku]` becomes
 * `items[0][sku]`, `items[1][sku]`, one field name and one error per element. A field below a
 * **declared** one that arrived null is not asked at all, which is how an optional object holds
 * required properties.
 *
 * **Locale specific rules are not shipped.** An id card checksum, a national mobile number format
 * or a "this must be han characters" test belong to whoever knows which country the application is
 * in; the framework's part is extend(), which is how an application adds one:
 *
 *     validate::extend('idcard', [model\id_card::class, 'check']);
 *     validate::set_default_messages(['idcard' => 'The {field} field must be a valid id number.']);
 *
 * **Messages are plain strings on this class, not a language pack.** The shipped set is English --
 * see MESSAGES below -- and there are three ways to override, innermost first: the fourth argument
 * of set_rules() for one field, set_message() for one rule on one instance, and the static
 * set_default_messages() for the whole process. An application that speaks another language states
 * it once at bootstrap, from whatever translator it already has.
 *
 * @phpstan-consistent-constructor  make() builds with new static(), so a subclass keeps the
 *                                  no argument constructor and adds rule methods instead
 */
class validate
{
    /**
     * The rules this class implements, and the only methods a rule name may reach.
     *
     * An allowlist rather than method_exists(), so a rule named after one of the public methods
     * (`errors`, `data`, `run`) cannot be dispatched to it by accident.
     *
     * @var array<int, string>
     */
    public const RULES = [
        'required', 'matches', 'regex_match', 'minlength', 'maxlength', 'exactlength',
        'min', 'max', 'numeric', 'integer', 'decimal', 'url', 'email', 'ip', 'date',
        'password_strong',
    ];

    /**
     * Rules that have to see a missing value, because a missing value is what they are about.
     *
     * @var array<int, string>
     */
    public const IMPLICIT_RULES = ['required', 'matches'];

    /**
     * The rules about the shape of a value rather than about its content.
     *
     * Not methods, and deliberately not in RULES: a rule is offered one value at a time and an
     * array field is walked into before any of them runs, so the array itself is the one thing no
     * rule can see. These are answered in _execute() instead, where the value is still whole, and
     * they are the field's whole verdict -- `required` included.
     *
     * @var array<int, string>
     */
    public const CONTAINER_RULES = ['scalar', 'list', 'map'];

    /**
     * Rules registered with extend(), as `['rule' => callable, 'implicit' => bool]`.
     *
     * @var array<string, array<string, mixed>>
     */
    private static $_extensions = [];

    /**
     * The data under validation, as handed to make().
     *
     * @var array<string, mixed>
     */
    protected $_data = [];

    /**
     * Rules and results, keyed by field name
     *
     * @var array<string, array<string, mixed>>
     */
    protected $_field_data = [];

    /**
     * The first error of each field that produced one
     *
     * @var array<string, string>
     */
    protected $_errors = [];

    /**
     * Messages set through set_message(), keyed by rule
     *
     * @var array<string, string>
     */
    protected $_messages = [];

    /**
     * The message each built in rule fails with, before any override.
     *
     * English is the only language shipped. Applications hand their own text over with
     * set_default_messages(), from whatever translator they already use.
     *
     * `{field}` is the field's label and `{param}` the rule's argument; `%s` twice works too, see
     * _build_error_msg().
     *
     * @var array<string, string>
     */
    private const MESSAGES = [
        'required'        => 'The {field} field is required.',
        'regex_match'     => 'The {field} field is not in the correct format.',
        'matches'         => 'The {field} field does not match the {param} field.',
        'minlength'       => 'The {field} field must be at least {param} characters in length.',
        'maxlength'       => 'The {field} field cannot exceed {param} characters in length.',
        'exactlength'     => 'The {field} field must be exactly {param} characters in length.',
        'min'             => 'The {field} field must contain a number greater than or equal to {param}.',
        'max'             => 'The {field} field must contain a number less than or equal to {param}.',
        'numeric'         => 'The {field} field must contain only numbers.',
        'integer'         => 'The {field} field must contain an integer.',
        'decimal'         => 'The {field} field must contain a decimal number.',
        'url'             => 'The {field} field must contain a valid URL.',
        'email'           => 'The {field} field must contain a valid email address.',
        'ip'              => 'The {field} field must contain a valid IP.',
        'date'            => 'The {field} field must contain a valid Date.',
        'password_strong' => 'The {field} field must mix upper case, lower case and digits.',
        'scalar'          => 'The {field} field must be a single value.',
        'list'            => 'The {field} field must be a list of values.',
        'map'             => 'The {field} field must be a set of properties.',
        'not_set'         => 'Unable to access an error message corresponding to your field name {field}.',
    ];

    /**
     * What set_default_messages() was given, laid over MESSAGES.
     *
     * Kept separate from the shipped set so that reset_default_messages() has something pristine
     * to go back to -- merging into the shipped array would destroy it for the rest of the process.
     *
     * @var array<string, string>
     */
    protected static $_message_overrides = [];

    /**
     * Replace the shipped messages, wholesale or a few at a time.
     *
     * Static and not per instance: make() is called at every call site, so a language choice has
     * to be stated once during bootstrap rather than on each validation. A rule an application
     * added with extend() takes its message from here too, under its own name.
     *
     * This is also where a translated message set goes -- the messages are plain strings in
     * whatever language the application answers in:
     *
     *     validate::set_default_messages([
     *         'required'  => 'Le champ {field} est obligatoire.',
     *         'cn_mobile' => '{field} is not a valid mobile number.',
     *     ]);
     *
     * Merges, so an override names only what it changes.
     *
     * @param  array<string, string> $messages Rule name => message
     * @return void
     */
    public static function set_default_messages(array $messages)
    {
        self::$_message_overrides = $messages + self::$_message_overrides;
    }

    /**
     * The messages in effect: the overrides, then the shipped ones.
     *
     * @param  string|null $rule One rule's message, or null for all of them
     * @return mixed  Null when nothing defines a message for that rule
     */
    public static function default_messages(?string $rule = null)
    {
        $messages = self::$_message_overrides + self::MESSAGES;

        return $rule === null ? $messages : ($messages[$rule] ?? null);
    }

    /**
     * Drop the overrides, so the shipped messages are in effect again.
     *
     * @return void
     */
    public static function reset_default_messages()
    {
        self::$_message_overrides = [];
    }

    /**
     * Whether run() has been through the rules already, so the readers can run it themselves.
     *
     * @var bool
     */
    protected $_ran = false;

    /**
     * The built-in rules, which are predicates and live in a class of their own.
     *
     * @var rules
     */
    protected $_rules;

    /**
     * Protected: instances come from make(), which is what guarantees the data and the rules
     * belong to each other.
     */
    protected function __construct()
    {
        // The lookup `matches[other_field]` needs. A field with no value resolves to null and
        // matches nothing, itself included.
        $this->_rules = new rules(function (string $field)
        {
            return $this->_field_data[$field]['value'] ?? null;
        });
    }

    /**
     * A validator over $data.
     *
     * @param  array<string, mixed>       $data     What to check
     * @param  array<mixed>|string        $rules    Rules, in either shape set_rules() takes
     * @param  array<string, string>      $messages Message overrides, keyed by rule
     * @return self
     */
    public static function make(array $data, $rules = [], array $messages = [])
    {
        $instance = new static();

        $instance->_data = $data;

        if ( $messages !== [] )
        {
            $instance->set_message($messages);
        }

        if ( $rules !== [] && $rules !== '' )
        {
            $instance->set_rules($rules);
        }

        return $instance;
    }

    /**
     * A validator over the request parameters, whatever the method was.
     *
     * req::all() is the union of GET, POST and the PUT / PATCH / DELETE bodies, so a json PUT is
     * checked the same way a form POST is. Whether there is anything to check at all is the
     * caller's decision -- guard the call with req::method() when one action both renders a form
     * and takes its submission.
     *
     * @param  array<mixed>|string   $rules
     * @param  array<string, string> $messages
     * @return self
     */
    public static function from_request($rules = [], array $messages = [])
    {
        return static::make(req::all(), $rules, $messages);
    }

    /**
     * Registers a rule under a name, so it can be used as a string like a built in one.
     *
     * The callable is handed the value, and the rule's `[parameter]` as a second argument when it
     * carries one. Returning false fails the field; returning anything other than a bool replaces
     * the value. The error message is looked up under the name, so give the pack a
     * `form_validate_<name>` line or pass one to set_message().
     *
     * @param  string   $name
     * @param  callable $rule
     * @param  bool     $implicit Whether the rule also runs on a missing or empty value, which is
     *                            what the built in `required` does
     * @return void
     */
    public static function extend($name, callable $rule, $implicit = false)
    {
        self::$_extensions[$name] = ['rule' => $rule, 'implicit' => (bool) $implicit];
    }

    /**
     * Whether a rule of that name has been registered.
     *
     * @param  string $name
     * @return bool
     */
    public static function has_extension($name)
    {
        return isset(self::$_extensions[$name]);
    }

    /**
     * Drops every registered rule, so a test does not leak its rules into the next one.
     *
     * @return void
     */
    public static function reset_extensions()
    {
        self::$_extensions = [];
    }

    /**
     * Adds the rules for one field, or for a whole form at once.
     *
     * A field name may address an array: `contacts[0][email]` is split into its keys and looked
     * up in the data on the way down. `[*]` stands for every element of a list, and is
     * resolved here, against the data make() was handed: `items[*][sku]` sets the same rules on
     * `items[0][sku]` and `items[1][sku]`, each of them a name that is read and reported on its
     * own. Nothing is set when the list is absent or is a map -- the field holding it says what it
     * has to be.
     *
     * Three shapes are accepted for a whole form:
     *
     *     ['email' => 'required|email']                             // name => rules
     *     ['email' => ['required', $closure]]                       // name => list of rules
     *     [['field' => 'email', 'label' => 'Email', 'rules' => ...]] // rows, with labels
     *
     * @param  mixed                 $field  Field name, or an array in one of the shapes above
     * @param  string                $label  Human name, put into the message as {field}
     * @param  mixed                 $rules  `|` separated string, or an array of rules
     * @param  array<string, string> $errors Messages for this field, keyed by rule
     * @return self
     */
    public function set_rules($field, $label = '', $rules = [], $errors = [])
    {
        if ( is_array($field) )
        {
            foreach ( $field as $key => $row )
            {
                // A row carrying its own field name, which is the shape that can also set a label
                if ( is_array($row) && isset($row['field'], $row['rules']) )
                {
                    $this->set_rules(
                        $row['field'],
                        isset($row['label']) ? $row['label'] : $row['field'],
                        $row['rules'],
                        (isset($row['errors']) && is_array($row['errors'])) ? $row['errors'] : []
                    );

                    continue;
                }

                // The compact shape: the key is the field name, the value is its rules
                if ( is_string($key) )
                {
                    $this->set_rules($key, '', $row);
                }
            }

            return $this;
        }

        if ( ! is_string($field) || $field === '' || empty($rules) )
        {
            return $this;
        }
        elseif ( ! is_array($rules) )
        {
            if ( ! is_string($rules) )
            {
                return $this;
            }

            // Split on pipes, except the ones inside a rule's [parameter]
            $rules = preg_split('/\|(?![^\[]*\])/', $rules);
        }

        // One name per element, before the keys below are read: `*` is not a key of the data, and
        // walking down to it literally would check something nobody sent
        if ( strpos($field, '[*]') !== false )
        {
            foreach ( $this->_element_names($field) as $name )
            {
                $this->set_rules($name, $label, $rules, $errors);
            }

            return $this;
        }

        $label = ($label === '') ? $field : $label;

        // An array field: keep the keys so _reduce_array() can walk down to the value
        $is_array = preg_match('/\[.*?\]/', $field) === 1;
        $indexes  = $is_array ? $this->_name_keys($field) : [];

        $this->_field_data[$field] = [
            'field'    => $field,
            'label'    => $label,
            'rules'    => $rules,
            'errors'   => $errors,
            'is_array' => $is_array,
            'keys'     => $indexes,
            'value'    => null,
        ];

        $this->_ran = false;

        return $this;
    }

    /**
     * Overrides the message of one rule, or of several at once.
     *
     * Takes precedence over the language pack, and is itself overridden by the per field
     * messages given to set_rules().
     *
     * @param  array<string, string>|string $lang Rule name, or rule => message
     * @param  string                       $val  Message, when $lang is one rule name
     * @return self
     */
    public function set_message($lang, $val = '')
    {
        if ( ! is_array($lang) )
        {
            $lang = [$lang => $val];
        }

        $this->_messages = array_merge($this->_messages, $lang);

        return $this;
    }

    /**
     * Runs every rule that has been set.
     *
     * Safe to call twice: the errors of the previous run are dropped first.
     *
     * @return bool True when nothing failed, including when no rules were set at all
     */
    public function run()
    {
        $this->_errors = [];
        $this->_ran = true;

        if ( $this->_field_data === [] )
        {
            return true;
        }

        // Collect the values first: matches[other_field] reads another field's value, which has
        // to be there before any rule runs
        foreach ( $this->_field_data as $field => $row )
        {
            $this->_field_data[$field]['value'] = $row['is_array'] === true
                ? $this->_reduce_array($this->_data, $row['keys'])
                : (isset($this->_data[$field]) ? $this->_data[$field] : null);
        }

        foreach ( $this->_field_data as $field => $row )
        {
            // What is below something that did not arrive is not asked. Only a field the rule set
            // declares itself counts as that something: it is the one that has said what has to be
            // there, so `buyer[email]` is skipped when an optional `buyer` is absent, while a lone
            // `contacts[0][email]` is still required of a form that posted nothing
            if ( $this->_absent_ancestor($row) )
            {
                continue;
            }

            $this->_execute($row, $row['rules'], $this->_field_data[$field]['value']);
        }

        return $this->_errors === [];
    }

    /**
     * Whether everything passed, running the rules first if that has not happened yet.
     *
     * @return bool
     */
    public function passes()
    {
        return $this->_ran ? ($this->_errors === []) : $this->run();
    }

    /**
     * Whether anything failed.
     *
     * @return bool
     */
    public function fails()
    {
        return ! $this->passes();
    }

    /**
     * Every error, keyed by field name, one per field.
     *
     * @return array<string, string>
     */
    public function errors()
    {
        $this->_ran || $this->run();

        return $this->_errors;
    }

    /**
     * The error of one field, or the first error there is.
     *
     * @param  string $field  Field name, or '' for the first error of the set
     * @param  string $prefix Wrapped around the message, an opening tag
     * @param  string $suffix The closing tag
     * @return string  Empty when the field has no error
     */
    public function error($field = '', $prefix = '', $suffix = '')
    {
        $errors = $this->errors();

        if ( $field === '' )
        {
            return $errors === [] ? '' : $prefix . reset($errors) . $suffix;
        }

        return isset($errors[$field]) ? $prefix . $errors[$field] . $suffix : '';
    }

    /**
     * The values of the fields that passed, after any sanitizing rule.
     *
     * A rule returning something other than a bool replaces the value, so `trim` reaches the
     * caller through here. **Nothing is written back to $_POST or req::$posts**: the input stays
     * the input and this is the output, which is the one place the coerced values exist.
     *
     * Array fields come back nested, so `contacts[0][email]` lands under
     * `['contacts' => [0 => ['email' => ...]]]`.
     *
     * @return array<string, mixed>
     */
    public function validated()
    {
        $this->_ran || $this->run();

        $out = [];

        foreach ( $this->_field_data as $field => $row )
        {
            if ( isset($this->_errors[$field]) || $row['value'] === null )
            {
                continue;
            }

            if ( $row['is_array'] === true )
            {
                arr::set($out, implode('.', $row['keys']), $row['value']);

                continue;
            }

            $out[$field] = $row['value'];
        }

        return $out;
    }

    /**
     * The data set this validator was built over, unchanged.
     *
     * @return array<string, mixed>
     */
    public function data()
    {
        return $this->_data;
    }

    /**
     * Re-orders the rules of a field so each one can assume the previous ones passed.
     *
     * Sanitizers first, then `required`, then the rest in the order they were given. A plain PHP
     * function used as a rule counts as a sanitizer: `trim` has to run before `required`, or a
     * value of '   ' is accepted and then emptied. Without `required` coming next, `maxlength[5]`
     * on an absent field would report a length problem instead of a missing one.
     *
     * @param  array<int, mixed> $rules
     * @return array<int, mixed>
     */
    protected function _prepare_rules($rules)
    {
        $sanitizers = [];
        $required   = [];
        $rest       = [];

        foreach ( $rules as $rule )
        {
            if ( $rule === 'required' )
            {
                $required[] = $rule;
            }
            elseif ( $this->_is_sanitizer($rule) )
            {
                $sanitizers[] = $rule;
            }
            else
            {
                $rest[] = $rule;
            }
        }

        return array_merge($sanitizers, $required, $rest);
    }

    /**
     * Whether a rule is a plain PHP function borrowed as a rule, rather than a check.
     *
     * @param  mixed $rule
     * @return bool
     */
    protected function _is_sanitizer($rule)
    {
        return is_string($rule)
            && strpos($rule, '[') === false
            && ! isset(self::$_extensions[$rule])
            && ! in_array($rule, self::RULES, true)
            && function_exists($rule);
    }

    /**
     * The keys an array field name addresses, read the way _reduce_array() walks them: the name up
     * to the first bracket, then every non empty bracketed index after it.
     *
     * @param  string $field
     * @return array<int, string>
     */
    protected function _name_keys($field)
    {
        $position = strpos($field, '[');

        if ( $position === false )
        {
            return [$field];
        }

        $keys = [substr($field, 0, $position)];

        preg_match_all('/\[(.*?)\]/', substr($field, $position), $matches);

        foreach ( $matches[1] as $key )
        {
            if ( $key !== '' )
            {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * The names one `[*]` field name stands for, given the list the data carries.
     *
     * A wildcard never expands a map. Its keys are application data rather than list indexes, and
     * the field-name grammar cannot losslessly quote every possible key (`*`, `.`, or brackets).
     *
     * @param  string $field
     * @return array<int, string>  Empty when nothing is there to name
     */
    protected function _element_names($field)
    {
        $position = strpos($field, '[*]');

        if ( $position === false )
        {
            return [$field];
        }

        $prefix   = substr($field, 0, $position);
        $suffix   = substr($field, $position + 3);
        $elements = $this->_reduce_array($this->_data, $this->_name_keys($prefix));

        if ( ! is_array($elements) || arr::is_assoc($elements) )
        {
            return [];
        }

        $names = [];

        foreach ( array_keys($elements) as $key )
        {
            foreach ( $this->_element_names($prefix . '[' . $key . ']' . $suffix) as $name )
            {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Walks down the data along the keys of an array field name.
     *
     * @param  mixed             $array Data, or the value reached so far
     * @param  array<int|string> $keys  Keys taken out of the field name by set_rules()
     * @param  int               $i     Depth, used by the recursion
     * @return mixed  Null when a key along the way is missing
     */
    protected function _reduce_array($array, $keys, $i = 0)
    {
        if ( is_array($array) && isset($keys[$i]) )
        {
            return isset($array[$keys[$i]]) ? $this->_reduce_array($array[$keys[$i]], $keys, ($i + 1)) : null;
        }

        return $array;
    }

    /**
     * Runs the rules of one field, stopping at the first that fails.
     *
     * @param  array<string, mixed> $row   The field's entry of _field_data
     * @param  array<int, mixed>    $rules
     * @param  mixed                $value The value, or one element of it
     * @param  int|string|null      $index Which element, when the field is an array
     * @return void
     */
    protected function _execute($row, $rules, $value, $index = null)
    {
        // The shape of the field, decided while the value is still whole. An element is reached by
        // the recursion below and carries its own name, so the container is the whole field's
        if ( $index === null )
        {
            switch ( $this->_container($rules) )
            {
                case 'list':
                    $this->_elements($row, $rules, $value);

                    return;

                case 'map':
                    $this->_properties($row, $rules, $value);

                    return;

                case 'scalar':
                    if ( $value !== null && ! is_scalar($value) )
                    {
                        $this->_fail($row, 'scalar', null);

                        return;
                    }

                    // Answered; the rest of the rules are about the value and run as they always do
                    $rules = array_values(array_filter($rules, function ($rule)
                    {
                        return $rule !== 'scalar';
                    }));

                    break;
            }
        }

        // An array field: every element goes through the rules on its own
        if ( is_array($value) && $value !== [] )
        {
            foreach ( $value as $key => $val )
            {
                $this->_execute($row, $rules, $val, $key);
            }

            return;
        }

        $field = $row['field'];

        foreach ( $this->_prepare_rules($rules) as $rule )
        {
            $value = $this->_current_value($field, $index);

            [$name, $callable, $param, $implicit] = $this->_resolve_rule($rule);

            // A rule that resolves to nothing is a mistake in the rule set rather than a verdict
            // on the value, so it is reported even for an empty field, where the skip below would
            // otherwise hide the typo behind a pass
            if ( $callable === null )
            {
                $this->_log_unresolved($name);
                $this->_fail($row, $name, $param);

                return;
            }

            // An absent value is only the business of the rules that are about presence, so the
            // others are skipped rather than run against ''
            if ( ($value === null || $value === '') && $implicit === false )
            {
                continue;
            }

            $result = $this->_apply($callable, $param, $value);

            // A rule that answered something other than a bool has replaced the value
            if ( ! is_bool($result) )
            {
                $this->_store_value($field, $index, $result);
            }

            if ( $result === false )
            {
                $this->_fail($row, $name, $param);

                return;
            }
        }
    }

    /**
     * Whether a declared field above this one arrived null, so this one is not asked.
     *
     * @param  array<string, mixed> $row The field's entry of _field_data
     * @return bool
     */
    protected function _absent_ancestor($row)
    {
        if ( $row['is_array'] !== true )
        {
            return false;
        }

        $keys = $row['keys'];
        $name = array_shift($keys);

        // The name itself is the last one, and answers for itself
        array_pop($keys);

        while ( true )
        {
            if ( isset($this->_field_data[$name]) && $this->_field_data[$name]['value'] === null )
            {
                return true;
            }

            if ( $keys === [] )
            {
                return false;
            }

            $name .= '[' . array_shift($keys) . ']';
        }
    }

    /**
     * The container a field declared, or null when it declared none.
     *
     * @param  array<int, mixed> $rules
     * @return string|null
     */
    protected function _container($rules)
    {
        foreach ( $rules as $rule )
        {
            if ( is_string($rule) && in_array($rule, self::CONTAINER_RULES, true) )
            {
                return $rule;
            }
        }

        return null;
    }

    /**
     * A field declared `list`: what is asked is that it arrived, and arrived as a json array.
     *
     * Nothing here descends. The elements are named one by one by set_rules() and carry their own
     * rules, so the rules of the field are about the list itself -- which is the whole point of
     * declaring one.
     *
     * @param  array<string, mixed> $row
     * @param  array<int, mixed>    $rules
     * @param  mixed                $value
     * @return void
     */
    protected function _elements($row, $rules, $value)
    {
        $required = in_array('required', $rules, true);

        // Absent. A blank string did arrive, and is not a list.
        if ( $value === null )
        {
            if ( $required )
            {
                $this->_fail($row, 'required', null);
            }

            return;
        }

        if ( ! is_array($value) || arr::is_assoc($value) )
        {
            $this->_fail($row, 'list', null);

            return;
        }

        if ( $required && $value === [] )
        {
            $this->_fail($row, 'required', null);
        }
    }

    /**
     * A field declared `map`: a json object, which answers for itself and for nothing below it.
     *
     * @param  array<string, mixed> $row
     * @param  array<int, mixed>    $rules
     * @param  mixed                $value
     * @return void
     */
    protected function _properties($row, $rules, $value)
    {
        // Absent. A blank string did arrive, and is not a map.
        if ( $value === null )
        {
            if ( in_array('required', $rules, true) )
            {
                $this->_fail($row, 'required', null);
            }

            return;
        }

        // `{}` and `[]` are the same array once json_decode() is done with them, so an empty one
        // could have been written either way and is admitted as both. Anything else that is a list
        // was written as a list, and a list is not the structure that was declared
        if ( ! is_array($value) || ($value !== [] && ! arr::is_assoc($value)) )
        {
            $this->_fail($row, 'map', null);
        }
    }

    /**
     * Works out what a rule is: its name for the message, what to call, and its parameter.
     *
     * @param  mixed $rule One entry of a field's rule list
     * @return array{0: ?string, 1: ?callable, 2: ?string, 3: bool}  Name, callable, parameter,
     *               and whether it runs on a missing value. A null callable means nothing could
     *               be found to run, which _apply() turns into a failure.
     */
    protected function _resolve_rule($rule)
    {
        // A callable given inline: a closure, or an [$object, 'method'] pair
        if ( ! is_string($rule) && is_callable($rule) )
        {
            return [null, $rule, null, false];
        }

        // A named callable, ['a_name', $callable], so the error has a message to look up
        if ( is_array($rule) && isset($rule[0], $rule[1]) && is_string($rule[0]) && is_callable($rule[1]) )
        {
            return [$rule[0], $rule[1], null, false];
        }

        if ( ! is_string($rule) )
        {
            return [null, null, null, false];
        }

        // maxlength[30] -> rule maxlength, parameter 30
        $param = null;
        if ( preg_match('/(.*?)\[(.*)\]/', $rule, $match) )
        {
            $rule  = $match[1];
            $param = $match[2];
        }

        if ( isset(self::$_extensions[$rule]) )
        {
            return [$rule, self::$_extensions[$rule]['rule'], $param, self::$_extensions[$rule]['implicit']];
        }

        $implicit = in_array($rule, self::IMPLICIT_RULES, true);

        if ( in_array($rule, self::RULES, true) )
        {
            return [$rule, [$this, $rule], $param, $implicit];
        }

        // Not a rule of this class: try it as a plain function, so 'trim' works as a rule
        if ( function_exists($rule) )
        {
            return [$rule, $rule, $param, $implicit];
        }

        return [$rule, null, $param, $implicit];
    }

    /**
     * Calls one resolved rule.
     *
     * @param  callable    $callable
     * @param  string|null $param
     * @param  mixed       $value
     * @return mixed  False fails the field, a non bool replaces the value
     */
    protected function _apply($callable, $param, $value)
    {
        $params = ($param === null) ? [$value] : [$value, $param];

        return call_user_func_array($callable, $params);
    }

    /**
     * Records that a rule name matched nothing.
     *
     * @param  string|null $name
     * @return void
     */
    protected function _log_unresolved($name)
    {
        // The pre 0.0.1 spelling, worth naming its replacement rather than leaving a bare miss
        $hint = ($name !== null && strncmp('callback_', $name, 9) === 0)
            ? '; callback_ rules are gone, register it with validate::extend() or pass the callable'
            : '';

        log::debug('Unable to find validate rule: ' . (string) $name . $hint);
    }

    /**
     * The value a rule should see, which for an array field is one element of it.
     *
     * @param  string          $field
     * @param  int|string|null $index
     * @return mixed
     */
    protected function _current_value($field, $index)
    {
        $value = $this->_field_data[$field]['value'];

        if ( $index === null )
        {
            // A field declared as a scalar that arrived as an array has no value a rule can read
            return is_array($value) ? null : $value;
        }

        return (is_array($value) && array_key_exists($index, $value)) ? $value[$index] : null;
    }

    /**
     * Keeps what a rule returned, in place when the field is an array.
     *
     * @param  string          $field
     * @param  int|string|null $index
     * @param  mixed           $value
     * @return void
     */
    protected function _store_value($field, $index, $value)
    {
        if ( $index === null )
        {
            $this->_field_data[$field]['value'] = $value;

            return;
        }

        if ( is_array($this->_field_data[$field]['value']) )
        {
            $this->_field_data[$field]['value'][$index] = $value;
        }
    }

    /**
     * Records the one error of a field.
     *
     * @param  array<string, mixed> $row
     * @param  string|null          $name  Rule name, null for an unnamed callable
     * @param  string|null          $param
     * @return void
     */
    protected function _fail($row, $name, $param)
    {
        // A closure has no name to look a message up under; give it a name with
        // ['a_name', $closure] to get a real message
        $line = ($name === null)
            ? self::_not_set() . '(Anonymous function)'
            : $this->_get_error_message($name, $row['field']);

        $this->_errors[$row['field']] = $this->_build_error_msg(
            $line,
            $row['label'],
            $param === null ? '' : $param
        );
    }

    /**
     * The message of a rule, from the first of three places that has one.
     *
     * Per field (set_rules' fourth argument), then per rule (set_message()), then the process wide
     * defaults (set_default_messages(), falling back to the shipped English).
     *
     * @param  string $rule
     * @param  string $field
     * @return string  A placeholder naming the rule when nothing defines a message for it
     */
    protected function _get_error_message($rule, $field)
    {
        if ( isset($this->_field_data[$field]['errors'][$rule]) )
        {
            return $this->_field_data[$field]['errors'][$rule];
        }

        if ( isset($this->_messages[$rule]) )
        {
            return $this->_messages[$rule];
        }

        $line = self::default_messages($rule);

        if ( $line !== null )
        {
            return (string) $line;
        }

        return self::_not_set() . '(' . $rule . ')';
    }

    /**
     * The message used when nothing defines one for a rule.
     *
     * @return string
     */
    protected static function _not_set()
    {
        return (string) (self::default_messages('not_set') ?? 'Unable to access an error message.');
    }

    /**
     * Fills the field name and the rule parameter into a message.
     *
     * Both spellings are supported: `%s` twice for a sprintf template, or the named `{field}`
     * and `{param}`, which is what the shipped language packs use.
     *
     * @param  string $line  Message template
     * @param  string $field The field's human name
     * @param  mixed  $param The rule's parameter, when it took one
     * @return string
     */
    protected function _build_error_msg($line, $field = '', $param = '')
    {
        if ( strpos($line, '%s') !== false )
        {
            return sprintf($line, $field, $param);
        }

        return str_replace(['{field}', '{param}'], [$field, $param], $line);
    }

    //-------------------------------------------------------------
    // The built-in rules
    //
    // Every one of them lives in plato\security\rules, which documents them and can be read and
    // tested without a validator existing. They stay here as forwarding methods because they are
    // public: an application calling $v->email($x) directly has always been able to.
    //-------------------------------------------------------------

    /**
     * Emptiness as a validator means it: 0 and '0' are values, so empty() is not usable here.
     *
     * @param  mixed $val
     * @return bool
     */
    public function _empty($val)
    {
        return $this->_rules->is_empty($val);
    }

    /** @param mixed $val @return bool */
    public function required($val)
    {
        return $this->_rules->required($val);
    }

    /** @param string $str @param string $regex @return bool */
    public function regex_match($str, $regex)
    {
        return $this->_rules->regex_match($str, $regex);
    }

    /** @param string $str @param string $field @return bool */
    public function matches($str, $field)
    {
        return $this->_rules->matches($str, $field);
    }

    /** @param string $str @param int|string $val @return bool */
    public function maxlength($str, $val)
    {
        return $this->_rules->maxlength($str, $val);
    }

    /** @param string $str @param int|string $val @return bool */
    public function minlength($str, $val)
    {
        return $this->_rules->minlength($str, $val);
    }

    /** @param string $str @param int|string $val @return bool */
    public function exactlength($str, $val)
    {
        return $this->_rules->exactlength($str, $val);
    }

    /** @param string|float|int $val @param float|int $min @return bool */
    public function min($val, $min)
    {
        return $this->_rules->min($val, $min);
    }

    /** @param string|float|int $val @param float|int $max @return bool */
    public function max($val, $max)
    {
        return $this->_rules->max($val, $max);
    }

    /** @param string $str @return bool */
    public function numeric($str)
    {
        return $this->_rules->numeric($str);
    }

    /** @param string $str @return bool */
    public function integer($str)
    {
        return $this->_rules->integer($str);
    }

    /** @param string $str @return bool */
    public function decimal($str)
    {
        return $this->_rules->decimal($str);
    }

    /** @param string $val @return string|false */
    public function url($val)
    {
        return $this->_rules->url($val);
    }

    /** @param string $val @return string|false */
    public function email($val)
    {
        return $this->_rules->email($val);
    }

    /** @param string $val @return string|false */
    public function ip($val)
    {
        return $this->_rules->ip($val);
    }

    /** @param string $val @return bool */
    public function date($val)
    {
        return $this->_rules->date($val);
    }

    /** @param string $password @return bool */
    public function password_strong($password)
    {
        return $this->_rules->password_strong($password);
    }
}
