<?php

/**
 * The built-in validation rules, as predicates
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\security;

/**
 * What `required`, `email`, `maxlength[30]` and the rest actually decide.
 *
 * Lifted out of plato\security\validate, which owns the other four jobs of a validator: holding the
 * data and the results, turning a rule string into a callable, walking the fields, and building
 * the error messages. None of those is a rule, and a rule needs none of them -- everything here
 * takes a value and answers, with one exception noted below.
 *
 * Keeping them apart buys two things. A rule can be read and tested without a validator existing;
 * and `validate` stops growing every time a rule is added, which is the direction a validator
 * always grows in.
 *
 * **`matches[password]` is the exception.** It compares against another field, which only the
 * validator knows about, so this class is handed a lookup when it is built:
 *
 *     new rules(fn (string $field) => $data[$field] ?? null);
 *
 * The lookup answers null for a field that is absent *and* for one holding null, which is the
 * distinction isset() made before and the one `matches` wants: a field nobody filled in does not
 * match anything, itself included.
 *
 * Every rule is public and the names are the rule names, because that is how validate resolves
 * them -- `[$rules, 'email']`. validate keeps a forwarding method per rule, so an application
 * calling `$v->email($x)` still works.
 */
class rules
{
    /**
     * Reads another field's value; null when it has none.
     *
     * @var callable|null
     */
    private $_sibling;

    /**
     * @param callable|null $sibling fn (string $field): mixed, for the `matches` rule
     */
    public function __construct(?callable $sibling = null)
    {
        $this->_sibling = $sibling;
    }

    /**
     * Emptiness as a validator means it: 0 and '0' are values, so empty() is not usable here.
     *
     * @param  mixed $val
     * @return bool
     */
    public function is_empty($val)
    {
        return ($val === false || $val === null || $val === '' || $val === []);
    }

    /**
     * Rule: the field has to carry something.
     *
     * @param  mixed $val
     * @return bool
     */
    public function required($val)
    {
        return ! $this->is_empty($val);
    }

    /**
     * Rule: the value has to match a pattern, `regex_match[/^[a-z]+$/]`.
     *
     * @param  string $str
     * @param  string $regex Full pattern, delimiters included
     * @return bool
     */
    public function regex_match($str, $regex)
    {
        return (bool) preg_match($regex, (string) $str);
    }

    /**
     * Rule: the value has to equal another field's, `matches[password]`.
     *
     * @param  string $str
     * @param  string $field Name of the other field, which needs rules of its own to be read
     * @return bool
     */
    public function matches($str, $field)
    {
        if ( $this->_sibling === null )
        {
            return false;
        }

        $other = ($this->_sibling)($field);

        return $other !== null && $str === $other;
    }

    /**
     * Rule: at most $val characters, counted as characters and not as bytes.
     *
     * @param  string     $str
     * @param  int|string $val
     * @return bool  False when $val is not a number, so a broken rule fails loudly
     */
    public function maxlength($str, $val)
    {
        if ( ! is_numeric($val) )
        {
            return false;
        }

        return $val >= mb_strlen((string) $str);
    }

    /**
     * Rule: at least $val characters.
     *
     * @param  string     $str
     * @param  int|string $val
     * @return bool
     */
    public function minlength($str, $val)
    {
        if ( ! is_numeric($val) )
        {
            return false;
        }

        return $val <= mb_strlen((string) $str);
    }

    /**
     * Rule: exactly $val characters.
     *
     * @param  string     $str
     * @param  int|string $val
     * @return bool
     */
    public function exactlength($str, $val)
    {
        if ( ! is_numeric($val) )
        {
            return false;
        }

        return mb_strlen((string) $str) === (int) $val;
    }

    /**
     * Rule: a number, not below $min.
     *
     * @param   string|float|int  $val
     * @param   float|int         $min
     * @return  bool
     */
    public function min($val, $min)
    {
        return is_numeric($val) ? ($val >= $min) : false;
    }

    /**
     * Rule: a number, not above $max.
     *
     * @param   string|float|int  $val
     * @param   float|int         $max
     * @return  bool
     */
    public function max($val, $max)
    {
        return is_numeric($val) ? ($val <= $max) : false;
    }

    /**
     * Rule: digits, with an optional sign and one decimal point.
     *
     * @param  string $str
     * @return bool
     */
    public function numeric($str)
    {
        return (bool) preg_match('/^[\-+]?[0-9]*\.?[0-9]+$/', (string) $str);
    }

    /**
     * Rule: digits with an optional sign.
     *
     * @param  string $str
     * @return bool
     */
    public function integer($str)
    {
        return (bool) preg_match('/^[\-+]?[0-9]+$/', (string) $str);
    }

    /**
     * Rule: a number that has a decimal point, `1` fails and `1.0` passes.
     *
     * @param  string $str
     * @return bool
     */
    public function decimal($str)
    {
        return (bool) preg_match('/^[\-+]?[0-9]+\.[0-9]+$/', (string) $str);
    }

    /**
     * Rule: a url filter_var() accepts.
     *
     * @param  string $val
     * @return string|false  The url itself when valid; only the false matters to the validator
     */
    public function url($val)
    {
        return filter_var($val, FILTER_VALIDATE_URL);
    }

    /**
     * Rule: an email address filter_var() accepts.
     *
     * @param  string $val
     * @return string|false  The address itself when valid
     */
    public function email($val)
    {
        return filter_var($val, FILTER_VALIDATE_EMAIL);
    }

    /**
     * Rule: an ip address, v4 or v6.
     *
     * @param  string $val
     * @return string|false  The address itself when valid
     */
    public function ip($val)
    {
        return filter_var($val, FILTER_VALIDATE_IP);
    }

    /**
     * Rule: a `YYYY-MM-DD` date that exists in the calendar.
     *
     * The shape is checked first and then the day itself, so `2024-02-30` and `2024-99-99` fail
     * where a pattern alone would have taken them.
     *
     * @param  string $val
     * @return bool
     */
    public function date($val)
    {
        if ( ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $val, $parts) )
        {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    /**
     * Rule: the password mixes upper case, lower case and digits.
     *
     * Length is not checked here -- pair this with `minlength[8]`.
     *
     * @param  string $password
     * @return bool
     */
    public function password_strong($password)
    {
        $classes = 0;

        foreach ( ['/[A-Z]/', '/[a-z]/', '/[0-9]/'] as $pattern )
        {
            if ( preg_match($pattern, (string) $password) > 0 )
            {
                $classes++;
            }
        }

        return $classes === 3;
    }
}
