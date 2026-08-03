<?php

/**
 * URL rewrite: rule driven link substitution over rendered output
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\http;

use plato\log;
use plato\plato;

/**
 * Rule driven link rewriting over rendered html.
 *
 * @deprecated Use route::url() instead. Generating the url up front is both cheaper and safer than
 *             running regular expressions across a finished page: route::url() and
 *             route::resolve() read the same configuration, so a generated url is one the router
 *             accepts, whereas the rules loaded here are a second source of truth that can drift
 *             from what the router and the web server actually do.
 *
 * Rules live in data/rewrite.ini, one per line, source and target separated by four or more
 * spaces so that either side may itself contain spaces:
 *
 *     ^article/(\d+)$        /a/$1.html
 *     # lines beginning with # are ignored
 *
 * The source side is compiled into a regular expression and the target is used as a preg_replace
 * replacement, so whoever can write that file controls the regex engine on every page render.
 * Treat the file as code, not as data.
 */
class rewrite
{
    /**
     * Compiled rules, pattern => replacement.
     *
     * @var array<string, string>
     */
    public static $rules = array();

    /** Whether the rule file has been read */
    protected static $is_load = false;

    /**
     * Load data/rewrite.ini.
     *
     * A malformed rule is skipped with a log line rather than taking the request down. Every valid
     * line contains a source and destination separated by four spaces.
     *
     * @return void
     */
    protected static function _load_rule()
    {
        self::$is_load = true;

        $rulefile = plato::data_path('rewrite.ini');

        if ( $rulefile === '' || !is_file($rulefile) )
        {
            return;
        }

        $lines = @file($rulefile);

        if ( $lines === false )
        {
            log::warning('rewrite rule file is not readable: ' . $rulefile);
            return;
        }

        foreach ( $lines as $no => $line )
        {
            $line = trim($line);

            if ( $line === '' || $line[0] === '#' )
            {
                continue;
            }

            $parts = preg_split('/[ ]{4,}/', $line, 2);

            if ( !is_array($parts) || count($parts) < 2 )
            {
                log::warning('rewrite rule has no target, line ' . ($no + 1));
                continue;
            }

            $s = rtrim($parts[0]);
            $t = ltrim($parts[1]);

            if ( $s === '' || $t === '' )
            {
                continue;
            }

            $pattern = self::_compile($s);

            if ( $pattern === '' )
            {
                log::warning('rewrite rule does not compile, line ' . ($no + 1) . ': ' . $s);
                continue;
            }

            self::$rules[$pattern] = $t;
        }
    }

    /**
     * Compile a rule source into a regular expression, '' when it does not compile.
     *
     * The rule is anchored inside the <rw> markers that a template puts around the links it wants
     * rewritten, so a rule cannot match arbitrary text elsewhere on the page.
     *
     * @param string $source
     * @return string
     */
    protected static function _compile($source)
    {
        $bare = preg_replace('#(^[\^]|[\$]$)#', '', $source);
        $head = $source[0] === '^' ? '<rw>' . $bare : '<rw>(.*)' . $bare;
        $body = substr($source, -1) === '$' ? $head . '</rw>' : $head . '([^<]*)</rw>';

        $pattern = '~' . $body . '~iU';

        // Compile once here, so a broken rule is refused at load time instead of turning every
        // page render into a preg_replace() warning
        if ( @preg_match($pattern, '') === false )
        {
            return '';
        }

        return $pattern;
    }

    /**
     * Rewrite the links in a rendered page.
     *
     * @param string $html
     * @return string
     */
    public static function convert_html(&$html)
    {
        $html = self::_apply((string) $html);
        $html = (string) preg_replace('#</?rw>#', '', $html);

        return $html;
    }

    /**
     * Rewrite a single url.
     *
     * @param string $url
     * @return string
     */
    public static function convert_url($url)
    {
        return self::_apply((string) $url);
    }

    /**
     * Run every rule over a subject.
     *
     * preg_replace() returns null when a pattern hits the backtracking or recursion limit, and
     * these patterns carry an ungreedy (.*) prefix, so a large page and an unlucky rule can get
     * there. Returning that null would blank the page, so the subject is left as it was and the
     * offending rule is reported instead.
     *
     * @param string $subject
     * @return string
     */
    protected static function _apply($subject)
    {
        if ( !self::$is_load )
        {
            self::_load_rule();
        }

        foreach ( self::$rules as $pattern => $replacement )
        {
            $result = @preg_replace($pattern, $replacement, $subject);

            if ( $result === null )
            {
                log::warning('rewrite rule failed with pcre error ' . preg_last_error() . ': ' . $pattern);
                continue;
            }

            $subject = $result;
        }

        return $subject;
    }

    /**
     * Drop the loaded rules so the file is read again on next use.
     *
     * @return void
     */
    public static function reset()
    {
        self::$rules   = array();
        self::$is_load = false;
    }
}
