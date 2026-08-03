<?php

/**
 * Request profiling panel: benchmarks, queries and request data
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\debug;

use plato\config;
use plato\database\db;
use plato\http\req;
use plato\http\upload;
use plato\plato;
use plato\str;
use Throwable;

/**
 * Builds the HTML panel that tpl::output() appends to a rendered page
 *
 * Every name in $_available_sections drives two members spelled after it: a
 * boolean property `_compile_<section>` deciding whether run() renders it, and
 * a method `_compile_<section>()` producing that section's markup. A new
 * section needs both, plus a matching key under `profiler` in config/config.php.
 *
 * Sections are enabled by default; one is only skipped when the profiler config
 * carries its key with an explicit false. The panel as a whole stays off until
 * enable_profiler() is called, which is what tpl::output() tests before asking
 * for the markup:
 *
 *     profiler::instance()->enable_profiler();
 *
 * The panel's own behaviour is plain DOM, so it works on a page that ships no
 * library at all. It used to call jQuery and layui's layer directly, which put a
 * requirement on the host page that the host page had no reason to meet -- layui
 * 2.9 keeps jQuery at `layui.$` and defines no global `$`, so on a layui back
 * office every toggle in the panel silently did nothing.
 */
class profiler
{
    public static $config = [];

    /**
     * The one instance, shared by this class and anything extending it
     *
     * @var self|null
     */
    protected static $_instance;

    protected $_compile_benchmarks;
    protected $_compile_config;
    protected $_compile_controller_info;
    protected $_compile_http_headers;
    protected $_compile_uri_string;
    protected $_compile_get;
    protected $_compile_post;
    protected $_compile_cookie_data;
    protected $_compile_session_data;
    protected $_compile_memory_usage;
    protected $_compile_queries;

    public $enable_profiler = false;

    /**
     * Section names, also the render order of the panel
     *
     * @var array
     */
    protected $_available_sections = [
        'benchmarks',
        'get',
        'memory_usage',
        'post',
        'uri_string',
        'controller_info',
        'queries',
        'http_headers',
        'cookie_data',
        'session_data',
        'config'
    ];

    /**
     * Number of queries to show before the query table starts out collapsed
     *
     * @var int
     */
    protected $_query_toggle_count = 25;

    /**
     * final because instance() builds the singleton with new static(): a subclass that
     * redeclared this with a different signature would break that call. Adding a section is
     * done with a property and a method, so extending never needs its own constructor.
     *
     * @param array $config Section switches, plus the query_toggle_count key
     */
    final public function __construct($config = [])
    {
        foreach ($this->_available_sections as $section)
        {
            if ( ! isset($config[$section]))
            {
                $var = '_compile_' . $section;
                $this->{$var} = true;
            }
        }

        $this->set_sections($config);
    }

    /**
     * Singleton, and the only place the profiler configuration is read.
     *
     * Reading it here rather than at class load keeps a request that never renders the panel from
     * paying for it and avoids a class-load-order dependency.
     *
     * `self` and not `static`: the instance is held in one static property shared by the class and
     * anything extending it, so the first caller decides what everybody gets. Promising a subclass
     * back would be a promise this cannot keep.
     *
     * @return self
     */
    public static function instance()
    {
        if (!self::$_instance instanceof self)
        {
            self::$config    = (array) config::instance('config')->get('profiler');
            self::$_instance = new self(self::$config);
        }

        return self::$_instance;
    }

    /**
     * Disable request-scoped profiling without constructing the lazy singleton.
     */
    public static function reset(): void
    {
        if ( self::$_instance instanceof self )
        {
            self::$_instance->enable_profiler(false);
        }
    }

    /**
     * @param mixed $val Anything that is not a boolean turns the panel on
     * @return void
     */
    public function enable_profiler($val = true)
    {
        $this->enable_profiler = is_bool($val) ? $val : true;
    }

    /**
     * Toggle sections from a config array
     *
     * Keys that are not section names are ignored, so the whole profiler config
     * array can be handed over as-is.
     *
     * @param array $config
     * @return void
     */
    public function set_sections($config)
    {
        if (isset($config['query_toggle_count']))
        {
            $this->_query_toggle_count = (int) $config['query_toggle_count'];
            unset($config['query_toggle_count']);
        }

        foreach ($config as $method => $enable)
        {
            if (in_array($method, $this->_available_sections))
            {
                $var = '_compile_' . $method;
                $this->{$var} = ($enable !== false);
            }
        }
    }

    /**
     * @return string
     */
    protected function _compile_benchmarks()
    {
        $profile = [];
        foreach (benchmark::$marker as $key => $val)
        {
            if (preg_match('/(.+?)_end$/i', $key, $match)
                && isset(benchmark::$marker[$match[1] . '_start']))
            {
                $profile[$match[1]] = benchmark::elapsed_time($match[1] . '_start', $key);
            }
        }

        $output = "\n\n"
            . '<div id="plato_profiler_benchmarks" class="pp-box pp-benchmarks">' . "\n"
            . '<div class="pp-head">BENCHMARKS</div>' . "\n"
            . "<table>\n";

        foreach ($profile as $key => $val)
        {
            $key = ucwords(str_replace(['_', '-'], ' ', $key));
            $output .= '<tr><td>' . $key . '</td><td class="pp-accent">' . $val . "</td></tr>\n";
        }

        return $output . "</table>\n</div>";
    }

    /**
     * @return string
     */
    protected function _compile_queries()
    {
        $db_config = config::instance('database')->get();
        $default   = (string) ($db_config['default'] ?? '');
        $database  = (string) ($db_config['connections'][$default]['database'] ?? $default);

        $queries = db::queries();

        if ( count($queries) == 0 )
        {
            return "\n\n"
                . '<div id="plato_profiler_queries" class="pp-box pp-queries">' . "\n"
                . '<div class="pp-head">QUERIES</div>' . "\n"
                . '<div class="pp-note">Database driver is not currently loaded</div>'
                . "\n</div>";
        }

        $output  = "\n\n";

        $hide_queries = (count($queries) > $this->_query_toggle_count) ? ' display:none' : '';
        $total_time = number_format(db::total_time(), 4) . ' seconds';

        $show_hide_js = '(<span class="plato_profiler_toggle">Hide</span>)';

        if ($hide_queries !== '')
        {
            $show_hide_js = '(<span class="plato_profiler_toggle">Show</span>)';
        }

        $output .= '<div class="pp-box pp-queries">' . "\n"
            . '<div class="pp-head">'
            . 'DATABASE: ' . htmlspecialchars($database) . '&nbsp;&nbsp;&nbsp;'
            . 'QUERIES: ' . count($queries) . ' (' . $total_time . ') ' . $show_hide_js . "</div>\n"
            . '<table class="pp-sql" style="' . $hide_queries . '" id="plato_profiler_queries_db_1' . "\">\n";

        foreach ($queries as $query)
        {
            $db_name = $query['connection'];
            $time = number_format((float) $query['time'], 4);
            // The log keeps the statement and its values apart, which is how they were sent. The
            // panel is for reading and for pasting into a client, so put them back together here
            $sql = $this->_query_sql($query);
            // ENT_QUOTES spelled out: the value goes into a single quoted attribute, and the
            // default flags only cover single quotes from PHP 8.1 on
            $val_raw = htmlspecialchars($sql, ENT_QUOTES, 'utf-8');
            // The <code> highlight_code() emits is what the panel styles for wrapping now. It used
            // to be rewritten to a <div> because only div was styled, which cost the one element
            // that says "this is source" to a reader and to a screen reader both
            $val = $this->_emphasise_sql(str::highlight_code($sql));

            $output .= '<tr><td class="pp-conn">' . $db_name . ' (' . $time . ')</td>'
                . '<td class="pp-stmt">' . $val
                . "<span class='btn-copy' data-text='$val_raw'>Copy</span></td></tr>\n";
        }

        $output .= "</table>\n</div>";

        return $output;
    }

    /**
     * Put the SQL keywords of an already-highlighted statement in bold.
     *
     * str::highlight_code() colours the statement, which says where the strings and the identifiers
     * are; this says where the clauses are. It runs over highlighted HTML, and that is the whole
     * difficulty -- four ways of getting it wrong, all of which the panel used to:
     *
     * A plain search for each keyword in turn has no word boundary, so `IN` matched the middle of
     * `DISTINCT` and of `HAVING`, and each match was made inside markup an earlier pass had already
     * emitted: `<strong>HAV<strong>IN</strong>G</strong>`. `AS` matched the front of `ASC` first and
     * left the C behind. One pass with a boundary on both sides fixes the family.
     *
     * The multi-word keywords were written with a literal `&nbsp;` between the words, which is what
     * highlight_string() emitted for a space until PHP 8.3 changed the markup. From 8.3 the spaces
     * are spaces, so `ORDER BY`, `GROUP BY`, `LEFT JOIN`, `NOT IN` and `NOT LIKE` matched nothing at
     * all and quietly stopped being highlighted. This package supports both, so the separator is
     * whichever of the two arrived, and the multi-word forms are tried before their own components
     * so that `ORDER BY` wins over `BY`.
     *
     * And the search saw the whole document, tags included. Splitting on tags first means the
     * pattern only ever runs over text -- a keyword can no longer be found inside an attribute, and
     * nothing can be inserted into the middle of one.
     *
     * Text inside a highlighted string is data rather than SQL grammar. The configured PHP string
     * colour identifies those spans on every supported PHP version, so their contents are left
     * alone even when a value happens to contain uppercase words such as `DELETE FROM`.
     *
     * Case sensitive on purpose: uppercase is the only thing separating the keyword `COUNT` from a
     * column somebody named `count`, and bolding half the identifiers in a statement helps nobody.
     *
     * @param  string $html Output of str::highlight_code()
     * @return string
     */
    protected function _emphasise_sql($html)
    {
        // Built once: the list is fixed, and a panel showing a hundred statements would otherwise
        // assemble the same pattern a hundred times
        static $pattern = null;

        if ($pattern === null)
        {
            $keywords = [
                // Longest first -- PCRE alternation takes the first branch that matches
                'LEFT JOIN', 'RIGHT JOIN', 'INNER JOIN', 'ORDER BY', 'GROUP BY', 'NOT IN', 'NOT LIKE',
                'SELECT', 'DISTINCT', 'FROM', 'WHERE', 'AND', 'OR', 'JOIN', 'ON', 'HAVING',
                'LIMIT', 'OFFSET', 'INSERT', 'INTO', 'VALUES', 'UPDATE', 'DELETE', 'SET',
                'IN', 'LIKE', 'AS', 'ASC', 'DESC', 'COUNT', 'MAX', 'MIN', 'AVG', 'SUM',
            ];

            $parts = [];

            foreach ($keywords as $word)
            {
                $parts[] = str_replace(' ', '(?:&nbsp;|\s)+', preg_quote($word, '/'));
            }

            $pattern = '/(?<![A-Za-z0-9_])(?:' . implode('|', $parts) . ')(?![A-Za-z0-9_])/';
        }

        // The delimiter is captured so the tags come back in place. The attributes highlight_code()
        // emits are colour declarations and hold no `>`, which is what makes this split sound
        $chunks = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($chunks === false)
        {
            return $html;
        }

        $string_color = strtolower(trim((string) ini_get('highlight.string')));
        $skip_stack   = [];

        foreach ($chunks as $i => $chunk)
        {
            if ($chunk === '')
            {
                continue;
            }

            if ($chunk[0] === '<')
            {
                if (preg_match('/^<span\b[^>]*\bstyle="color:\s*([^";]+)[^"]*"/i', $chunk, $match))
                {
                    $parent_skipped = $skip_stack !== [] && $skip_stack[count($skip_stack) - 1];
                    $skip_stack[] = $parent_skipped
                        || strtolower(trim($match[1])) === $string_color;
                }
                elseif (preg_match('/^<\/span\s*>$/i', $chunk) && $skip_stack !== [])
                {
                    array_pop($skip_stack);
                }

                continue;
            }

            if ($skip_stack !== [] && $skip_stack[count($skip_stack) - 1])
            {
                continue;
            }

            $marked = preg_replace($pattern, '<strong>$0</strong>', $chunk);

            // A statement long enough to exhaust the backtrack limit is still worth showing, just
            // without the bold
            $chunks[$i] = $marked === null ? $chunk : $marked;
        }

        return implode('', $chunks);
    }

    /**
     * One logged statement as something worth reading.
     *
     * db::queries() keeps the statement and its bound values apart, because that is how they were
     * sent to the server. Folding them back together is a display concern and the connection's own
     * grammar knows how to quote for its engine, so ask it -- and fall back to the placeholders
     * with the values beside them if that connection is no longer configured.
     *
     * @param  array<string, mixed> $query One entry of db::queries()
     * @return string
     */
    protected function _query_sql(array $query)
    {
        $sql      = (string) $query['sql'];
        $bindings = (array) ($query['bindings'] ?? []);

        if ( !$bindings )
        {
            return $sql;
        }

        try
        {
            return db::connection((string) $query['connection'])->grammar()->interpolate($sql, $bindings);
        }
        catch (Throwable)
        {
            return $sql . ' -- ' . json_encode($bindings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    /**
     * @return string
     */
    protected function _compile_get()
    {
        $output = "\n\n"
            . '<div id="plato_profiler_get" class="pp-box pp-get">' . "\n"
            . '<div class="pp-head">GET DATA</div>' . "\n";

        if (count(req::$gets) === 0)
        {
            $output .= '<div class="pp-note">No GET data exists</div>';
        }
        else
        {
            $output .= "\n<table>\n";

            foreach (req::$gets as $key => $val)
            {
                if ( ! is_int($key))
                {
                    $key = "'" . htmlspecialchars($key, ENT_QUOTES, 'utf-8') . "'";
                }

                $val = (is_array($val) || is_object($val))
                    ? '<pre>' . htmlspecialchars(print_r($val, true), ENT_QUOTES, 'utf-8') . '</pre>'
                    : htmlspecialchars($val, ENT_QUOTES, 'utf-8');

                $output .= '<tr><td>&#36;_GET[' . $key . ']</td><td class="pp-accent">' . $val . "</td></tr>\n";
            }

            $output .= "</table>\n";
        }

        return $output . '</div>';
    }

    /**
     * Renders $_POST and $_FILES into one section.
     *
     * @return string
     */
    protected function _compile_post()
    {
        $output = "\n\n"
            . '<div id="plato_profiler_post" class="pp-box pp-post">' . "\n"
            . '<div class="pp-head">POST DATA</div>' . "\n";

        if (count(req::$posts) === 0 && count(upload::all()) === 0)
        {
            $output .= '<div class="pp-note">No POST data exists</div>';
        }
        else
        {
            $output .= "\n<table>\n";

            foreach (req::$posts as $key => $val)
            {
                if ( ! is_int($key))
                {
                    $key = "'" . htmlspecialchars($key, ENT_QUOTES, 'utf-8') . "'";
                }

                $val = (is_array($val) || is_object($val))
                    ? '<pre>' . htmlspecialchars(print_r($val, true), ENT_QUOTES, 'utf-8') . '</pre>'
                    : htmlspecialchars($val, ENT_QUOTES, 'utf-8');

                $output .= '<tr><td>&#36;_POST[' . $key . ']</td><td class="pp-accent">' . $val . "</td></tr>\n";
            }

            foreach (upload::all() as $key => $val)
            {
                if ( ! is_int($key))
                {
                    $key = "'" . htmlspecialchars($key, ENT_QUOTES, 'utf-8') . "'";
                }

                $val = (is_array($val) || is_object($val))
                    ? '<pre>' . htmlspecialchars(print_r($val, true), ENT_QUOTES, 'utf-8') . '</pre>'
                    : htmlspecialchars($val, ENT_QUOTES, 'utf-8');

                $output .= '<tr><td>&#36;_FILES[' . $key . ']</td><td class="pp-accent">' . $val . "</td></tr>\n";
            }

            $output .= "</table>\n";
        }

        return $output . '</div>';
    }

    /**
     * The request target, escaped.
     *
     * It is the one section built straight out of what the client sent, so it is also the one that
     * would put a `<script>` typed into a url onto the page of whoever has the panel enabled.
     *
     * @return string
     */
    protected function _compile_uri_string()
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');

        return "\n\n"
            . '<div id="plato_profiler_uri_string" class="pp-box">' . "\n"
            . '<div class="pp-head">URI STRING</div>' . "\n"
            . '<div class="pp-note">'
            . ($uri === '' ? 'No URI data exists' : htmlspecialchars($uri, ENT_QUOTES, 'utf-8'))
            . '</div></div>';
    }

    /**
     * Show the controller and action plato::run() dispatched to
     *
     * @return string
     */
    protected function _compile_controller_info()
    {
        if (plato::$ct === '' || plato::$ac === '')
        {
            $msg = "No controller were run";
        }
        else
        {
            $msg = plato::$ct . '/' . plato::$ac;
        }
        return "\n\n"
            . '<div id="plato_profiler_controller_info" class="pp-box pp-route">' . "\n"
            . '<div class="pp-head">CLASS/METHOD</div>' . "\n"
            . '<div class="pp-note">' . $msg
            . '</div></div>';
    }

    /**
     * @return string
     */
    protected function _compile_memory_usage()
    {
        return "\n\n"
            . '<div id="plato_profiler_memory_usage" class="pp-box pp-memory">' . "\n"
            . '<div class="pp-head">MEMORY USAGE</div>' . "\n"
            . '<div class="pp-note">'
            . number_format(memory_get_usage()) . ' bytes'
            . '</div></div>';
    }

    /**
     * Lists a fixed set of $_SERVER entries, empty string when one is missing.
     *
     * @return string
     */
    protected function _compile_http_headers()
    {
        $output = "\n\n"
            . '<div id="plato_profiler_http_headers" class="pp-box">' . "\n"
            . '<div class="pp-head">HTTP HEADERS '
            . '(<span class="plato_profiler_toggle">Show' . "</span>)</div>\n"
            . '<table style="display:none;" id="plato_profiler_httpheaders_table">' . "\n";

        foreach (['HTTP_ACCEPT', 'HTTP_USER_AGENT', 'HTTP_CONNECTION', 'SERVER_PORT', 'SERVER_NAME', 'REMOTE_ADDR', 'SERVER_SOFTWARE', 'HTTP_ACCEPT_LANGUAGE', 'SCRIPT_NAME', 'REQUEST_METHOD', 'HTTP_HOST', 'REMOTE_HOST', 'CONTENT_TYPE', 'SERVER_PROTOCOL', 'QUERY_STRING', 'HTTP_ACCEPT_ENCODING', 'HTTP_X_FORWARDED_FOR', 'HTTP_DNT'] as $header)
        {
            $val = isset($_SERVER[$header]) ? htmlspecialchars($_SERVER[$header], ENT_QUOTES, 'utf-8') : '';
            $output .= '<tr><td>' . $header . '</td><td>' . $val . "</td></tr>\n";
        }

        return $output . "</table>\n</div>";
    }

    /**
     * Says that the configuration is deliberately not shown.
     *
     * Dumping it would put the database password, the crypt key and every third party credential
     * the application configured into an HTML page -- one that is easy to leave enabled on a
     * staging host and easy to paste into a bug report. An empty table said nothing about why it
     * was empty, so it read as a section that was broken; this says which it is.
     *
     * @return string
     */
    protected function _compile_config()
    {
        return "\n\n"
            . '<div id="plato_profiler_config" class="pp-box">' . "\n"
            . '<div class="pp-head">CONFIG VARIABLES</div>' . "\n"
            . '<div class="pp-note">'
            . 'Not shown on purpose: the configuration holds credentials. Read it with '
            . htmlspecialchars('config::instance(\'config\')->get()') . ' where you need it'
            . "</div>\n</div>";
    }

    /**
     * @return string
     */
    protected function _compile_cookie_data()
    {
        $output = '<div id="plato_profiler_cookie" class="pp-box">'
            . '<div class="pp-head">COOKIE DATA (<span class="plato_profiler_toggle">Show</span>)</div>'
            . '<table style="display:none;" id="plato_profiler_cookie_data">';

        foreach (req::$cookies as $key => $val)
        {
            if (is_array($val) || is_object($val))
            {
                $val = print_r($val, true);
            }

            $output .= '<tr><td>' . htmlspecialchars((string) $key, ENT_QUOTES, 'utf-8') . '</td>'
                . '<td>' . htmlspecialchars($val, ENT_QUOTES, 'utf-8') . "</td></tr>\n";
        }

        return $output . "</table>\n</div>";
    }

    /**
     * @return string Empty when no session has been started
     */
    protected function _compile_session_data()
    {
        if ( ! isset($_SESSION))
        {
            return '';
        }

        $output = '<div id="plato_profiler_session" class="pp-box">'
            . '<div class="pp-head">SESSION DATA (<span class="plato_profiler_toggle">Show</span>)</div>'
            . '<table style="display:none;" id="plato_profiler_session_data">';

        foreach ($_SESSION as $key => $val)
        {
            if (is_array($val) || is_object($val))
            {
                $val = print_r($val, true);
            }

            $output .= '<tr><td>' . htmlspecialchars((string) $key, ENT_QUOTES, 'utf-8') . '</td>'
                . '<td>' . htmlspecialchars($val, ENT_QUOTES, 'utf-8') . "</td></tr>\n";
        }

        return $output . "</table>\n</div>";
    }

    /**
     * The one line worth reading without opening the panel at all.
     *
     * @return string
     */
    protected function _summary()
    {
        $total = plato::app_total();

        return count(db::queries()) . ' queries'
            . ' &middot; ' . number_format(db::total_time() * 1000, 1) . ' ms sql'
            . ' &middot; ' . number_format($total[0] * 1000, 1) . ' ms total'
            . ' &middot; ' . number_format($total[1] / 1024) . ' KB';
    }

    /**
     * Render the whole panel, styles and behaviour included
     *
     * The panel is a drawer along the bottom of the window, closed until it is asked for, and not
     * a block at the end of the document. Two reasons, both learned the hard way:
     *
     * A back office frame -- and every admin template of the last fifteen years is one -- fixes a
     * header and a side column over the viewport. A panel in the document flow renders underneath
     * them, so its first two hundred pixels are simply unreadable, and there is nothing to click
     * to get it out of the way. Fixed positioning is the only placement that does not depend on
     * what the host page's layout happens to be.
     *
     * And the panel is not what the developer is looking at. It is what they look at when they
     * want it. Appending a screenful of request data to the bottom of every page in development
     * charges the reader for it on every page, whether or not they asked.
     *
     * The open flag and the height go on <html> and into localStorage, so the drawer survives a
     * navigation -- half of what it is for is comparing one request with the next -- and so a host
     * page can react to it: `html.pp-open .my-content { bottom: var(--pp-height) }` gives the
     * drawer room instead of letting it cover the last rows of a list.
     *
     * @return string
     */
    public function run()
    {
        // Deliberately one <style> and one <script> in the panel and nothing in the host page's
        // head: the panel is appended to a document that has already been rendered, and asking an
        // application to install two assets before its debug tool works is how a debug tool ends
        // up uninstalled. Everything is prefixed and scoped under #plato_profiler for the same
        // reason -- this lands on a page whose own stylesheet is none of the framework's business
        $output = '<style>
            html{ --pp-height: 42vh; }
            html.pp-tall{ --pp-height: 88vh; }

            #plato_profiler, #plato_profiler *{ box-sizing: border-box; }
            #plato_profiler{
                position: fixed; left: 0; right: 0; bottom: 0; z-index: 2147482000;
                display: flex; flex-direction: column; height: var(--pp-height);
                background: #fff; border-top: 1px solid #d0d3d6; box-shadow: 0 -2px 14px rgba(0,0,0,.14);
                font: 12px/1.6 -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                color: #24292f; text-align: left;
                /* Moved out of the way and hidden with it: a fixed element parked below the fold
                   still answers hit tests, and an invisible panel that eats clicks on the page
                   underneath is a bug nobody would think to look for here */
                transform: translateY(100%); visibility: hidden;
                transition: transform .18s ease, visibility .18s ease, height .18s ease;
            }
            html.pp-open #plato_profiler{ transform: none; visibility: visible; }

            #plato_profiler .pp-bar{ display: flex; flex: 0 0 auto; align-items: center; height: 34px; padding: 0 6px 0 12px; background: #2f363d; color: #e6edf3; user-select: none; }
            #plato_profiler .pp-name{ font-weight: 600; letter-spacing: .4px; }
            #plato_profiler .pp-sum{ flex: 0 1 auto; min-width: 0; margin-left: 12px; overflow: hidden; color: #9aa9b6; text-overflow: ellipsis; white-space: nowrap; }
            #plato_profiler .pp-spacer{ flex: 1 1 auto; }
            #plato_profiler .pp-btn{ height: 24px; padding: 0 9px; border: 0; border-radius: 3px; background: none; color: #c2cfda; font: inherit; font-size: 13px; line-height: 24px; cursor: pointer; }
            #plato_profiler .pp-btn:hover{ background: rgba(255,255,255,.14); color: #fff; }

            #plato_profiler .pp-body{ flex: 1 1 auto; overflow-y: auto; padding: 10px 12px; background: #f4f5f7; }

            #plato_profiler .pp-box{ --pp-accent: #24292f; margin: 0 0 10px; border: 1px solid #e2e4e7; border-radius: 3px; background: #fff; overflow: hidden; }
            #plato_profiler .pp-box:last-child{ margin-bottom: 0; }
            #plato_profiler .pp-head{ padding: 7px 10px; border-bottom: 1px solid #eceef0; background: #fafbfc; color: var(--pp-accent); font-weight: 600; letter-spacing: .4px; }
            #plato_profiler .pp-note{ padding: 7px 10px; color: var(--pp-accent); word-break: break-word; }
            #plato_profiler .pp-accent{ color: var(--pp-accent); }
            #plato_profiler .pp-benchmarks{ --pp-accent: #a11; }
            #plato_profiler .pp-queries{ --pp-accent: #1749d6; }
            #plato_profiler .pp-get{ --pp-accent: #cd6e00; }
            #plato_profiler .pp-post{ --pp-accent: #0a0; }
            #plato_profiler .pp-memory{ --pp-accent: #5a0099; }
            #plato_profiler .pp-route{ --pp-accent: #995300; }

            #plato_profiler table{ width: 100%; margin: 0; border-collapse: collapse; }
            #plato_profiler td{ padding: 6px 10px; border-top: 1px solid #f2f3f4; vertical-align: top; word-break: break-word; }
            #plato_profiler tr:first-child td{ border-top: 0; }
            #plato_profiler td:first-child{ width: 34%; color: #57606a; }
            #plato_profiler pre{ margin: 0; white-space: pre-wrap; word-break: break-word; }

            #plato_profiler .pp-sql td:first-child{ width: 1%; white-space: nowrap; color: #a11; }
            #plato_profiler .pp-stmt{ display: flex; align-items: flex-start; gap: 10px; }
            #plato_profiler .pp-stmt code{ flex: 1 1 auto; display: block; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; line-height: 1.7; white-space: pre-wrap; word-break: break-word; }
            #plato_profiler .btn-copy{ flex: 0 0 auto; height: 22px; padding: 0 9px; border-radius: 2px; background: #eef1f4; color: #5b6875; font-size: 11px; line-height: 22px; white-space: nowrap; cursor: pointer; }
            #plato_profiler .btn-copy:hover{ background: #2f363d; color: #fff; }

            #plato_profiler .plato_profiler_toggle{ color: #0969da; font-weight: 400; cursor: pointer; }
            #plato_profiler .plato_profiler_toggle:hover{ text-decoration: underline; }

            #plato_profiler_launch{
                position: fixed; right: 16px; bottom: 16px; z-index: 2147482000;
                display: flex; align-items: center; height: 30px; padding: 0 12px;
                border: 0; border-radius: 15px; background: #2f363d; color: #fff;
                font: 600 12px/30px -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                box-shadow: 0 2px 8px rgba(0,0,0,.25); cursor: pointer;
                /* Faded, because it sits on top of every page in development and is the subject of
                   none of them. It comes up to full strength under the pointer */
                opacity: .68; transition: opacity .15s ease;
            }
            #plato_profiler_launch:hover{ opacity: 1; }
            #plato_profiler_launch b{ margin-left: 7px; padding: 0 6px; border-radius: 8px; background: rgba(255,255,255,.16); }
            html.pp-open #plato_profiler_launch{ display: none; }
            </style>';

        $output .= '<div id="plato_profiler">'
            . '<div class="pp-bar">'
            . '<span class="pp-name">Profiler</span>'
            . '<span class="pp-sum">' . $this->_summary() . '</span>'
            . '<span class="pp-spacer"></span>'
            . '<button type="button" class="pp-btn" data-pp="size" title="Toggle height">&#8597;</button>'
            . '<button type="button" class="pp-btn" data-pp="close" title="Close">&#10005;</button>'
            . '</div>'
            . '<div class="pp-body">';
        $fields_displayed = 0;

        foreach ($this->_available_sections as $section)
        {
            $var = '_compile_' . $section;
            if ($this->{$var} !== false)
            {
                $output .= $this->{$var}();
                $fields_displayed++;
            }
        }

        if ($fields_displayed === 0)
        {
            $output .= '<div class="pp-box"><div class="pp-note">'
                . 'No Profile data - all Profiler sections have been disabled.</div></div>';
        }

        $output .= '</div></div>'
            . '<button type="button" id="plato_profiler_launch">Profiler<b>'
            . count(db::queries()) . '</b></button>';
        // One delegated listener on the panel root rather than a listener per control: the panel
        // is appended to a finished document, so there is nothing to wait for and nothing to
        // rebind. layer is used when the host page happens to have it and skipped when it does not
        $output .= '
            <script>
            (function () {
                var root = document.getElementById("plato_profiler");

                if (!root) { return; }

                function said(ok) {
                    if (window.layer && typeof window.layer.msg === "function") {
                        window.layer.msg(ok ? "copied" : "copy failed");
                    }
                }

                // http, which is what a development back office is served over, has no
                // navigator.clipboard at all, and a permission the user refused rejects the promise
                function fallback(text) {
                    var box = document.createElement("textarea");
                    box.value = text;
                    box.style.position = "fixed";
                    box.style.opacity = "0";
                    document.body.appendChild(box);
                    box.select();

                    try {
                        said(document.execCommand("copy"));
                    } catch (e) {
                        said(false);
                    } finally {
                        document.body.removeChild(box);
                    }
                }

                function copy(text) {
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(text).then(function () {
                            said(true);
                        }, function () {
                            fallback(text);
                        });

                        return;
                    }

                    fallback(text);
                }

                // On <html> rather than on <body>: the drawer is styled before the body element is
                // reached on a page the shutdown handler flushes, and a host page is far more
                // likely to be managing classes on its own body than on the document element
                var doc = document.documentElement;

                function flag(name, on) {
                    doc.classList.toggle(name, on);

                    // Storage throws rather than returns when a browser is set to refuse it, and
                    // remembering the drawer is not worth taking a page down for
                    try { window.localStorage.setItem(name, on ? "1" : "0"); } catch (e) {}
                }

                function saved(name) {
                    try { return window.localStorage.getItem(name) === "1"; } catch (e) { return false; }
                }

                root.addEventListener("click", function (event) {
                    var act = event.target.closest("[data-pp]");

                    if (act) {
                        if (act.getAttribute("data-pp") === "close") {
                            flag("pp-open", false);
                        } else {
                            flag("pp-tall", !doc.classList.contains("pp-tall"));
                        }

                        return;
                    }

                    var toggle = event.target.closest(".plato_profiler_toggle");

                    if (toggle) {
                        // The box and not the nearest div: the heading is an element of its own
                        // now, and closest("div") would stop at it and find no table below
                        var section = toggle.closest(".pp-box");
                        var table = section ? section.querySelector("table") : null;

                        if (table) {
                            var hidden = table.style.display === "none";
                            table.style.display = hidden ? "table" : "none";
                            toggle.textContent = hidden ? "Hide" : "Show";
                        }

                        return;
                    }

                    var button = event.target.closest(".btn-copy");

                    if (button) {
                        copy(button.getAttribute("data-text") || "");
                    }
                });

                var launch = document.getElementById("plato_profiler_launch");

                if (launch) {
                    launch.addEventListener("click", function () { flag("pp-open", true); });
                }

                flag("pp-tall", saved("pp-tall"));
                flag("pp-open", saved("pp-open"));
            })();
            </script>
        ';
        return $output;
    }
}
