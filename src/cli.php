<?php

/**
 * Command line argument parsing and terminal output
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato;

use Exception;

/**
 * Terminal input and output
 *
 * Static only. plato::registry() calls boot() under PHP_SAPI 'cli' and nowhere else, and boot()
 * throws outside a terminal, so this class must only be referenced from code that already knows
 * it runs in one — a stray `cli::` in a web request path reads empty arguments rather than argv.
 *
 * boot() records every argv entry twice: once under its 1-based position, and once under its
 * option name when the entry carries an '=' or starts with a dash. Leading dashes are stripped
 * and a valueless flag becomes true, so
 *
 *     php index.php user -v --name=John
 *
 * gives [1 => 'user', 2 => '-v', 'v' => true, 3 => '--name', 'name' => 'John'].
 * plato::run() dispatches on $args['ct'] / $args['ac'], and req::_hydrate() copies every named
 * option into $_GET, so CLI actions read their arguments the same way HTTP actions do.
 *
 * The severity methods (debug, info, notice, warning, error, critical, alert, emergency) mirror
 * the level names in log::$levels: log::save() calls them dynamically as cli::$level($msg) when
 * the process is attached to a terminal. Nothing references them statically — dropping one
 * silently breaks terminal logging for that level.
 *
 * Color is suppressed on Windows without ANSICON, and whenever self::$nocolor is set.
 */
class cli
{
    /** @var bool */
    public static $readline_support = false;

    /** @var string */
    public static $wait_msg = 'Press any key to continue...';

    /** @var bool */
    public static $nocolor = false;

    /** @var array */
    public static $args = [];

    protected static $foreground_colors = [
        'black'         => '0;30',
        'light_black'   => '1;30',
        'red'           => '0;31',
        'light_red'     => '1;31',
        'green'         => '0;32',
        'light_green'   => '1;32',
        'yellow'        => '0;33',
        'light_yellow'  => '1;33',
        'blue'          => '0;34',
        'light_blue'    => '1;34',
        'purple'        => '0;35',
        'light_purple'  => '1;35',
        'cyan'          => '0;36',
        'light_cyan'    => '1;36',
        'white'         => '0;37',
        'light_white'   => '1;37',
    ];

    protected static $background_colors = [
        'black'         => '40',
        'red'           => '41',
        'green'         => '42',
        'yellow'        => '43',
        'blue'          => '44',
        'magenta'       => '45',
        'cyan'          => '46',
        'white'         => '47',
    ];

    protected static $STDOUT;
    protected static $STDERR;

    /**
     * Whether boot() has already run
     *
     * @var bool
     */
    private static $_booted = false;

    /**
     * Parse argv into self::$args and open the standard streams.
     *
     * plato::registry() calls this under the cli SAPI. Parsing is explicit so merely referencing
     * this class from a web request has no process-wide side effects.
     *
     * @return void
     * @throws Exception When the process is not running under the cli SAPI
     */
    public static function boot()
    {
        if ( self::$_booted )
        {
            return;
        }

        if ( PHP_SAPI != 'cli' )
        {
            throw new Exception('cli class cannot be used outside of the command line.');
        }

        self::$_booted = true;

        for ($i = 1; $i < $_SERVER['argc']; $i++)
        {
            $arg = explode('=', $_SERVER['argv'][$i]);

            static::$args[$i] = $arg[0];

            if (count($arg) > 1 || strncmp($arg[0], '-', 1) === 0)
            {
                static::$args[ltrim($arg[0], '-')] = isset($arg[1]) ? $arg[1] : true;
            }
        }

        // Readline gives input() a bash-like line editor when the extension is present
        static::$readline_support = extension_loaded('readline');

        static::$STDERR = STDERR;
        static::$STDOUT = STDOUT;
    }

    /**
     * Whether boot() has run, i.e. whether the standard stream handles are open.
     *
     * For code that wants to write to STDOUT or STDERR through this class only when it is in
     * charge of them -- plato\debug\error_handler reports an uncaught exception that way.
     *
     * @return bool
     */
    public static function booted()
    {
        return self::$_booted;
    }

    /**
     * Returns an option by name, or by 1-based position for unnamed arguments.
     *
     * @param   string|int  $name     the name of the option (int if unnamed)
     * @param   mixed       $default  value to return if the option is not defined
     * @return  mixed
     */
    public static function option($name, $default = null)
    {
        if ( ! isset(static::$args[$name]))
        {
            return $default;
        }
        return static::$args[$name];
    }

    /**
     * Sets a command line option from code, so a caller can supply what argv did not.
     *
     * @param   string|int  $name   the name of the option (int if unnamed)
     * @param   mixed|null  $value  value to set, or null to delete the option
     * @return  void
     */
    public static function set_option($name, $value = null)
    {
        if ($value === null)
        {
            if (isset(static::$args[$name]))
            {
                unset(static::$args[$name]);
            }
        }
        else
        {
            static::$args[$name] = $value;
        }
    }

    /**
     * Reads one line from the shell, through readline when available.
     *
     * @param   string  $prefix  text to show before the cursor
     * @return  string|false     false when STDIN is closed
     */
    public static function input($prefix = '')
    {
        if (static::$readline_support)
        {
            return readline($prefix);
        }

        echo $prefix;
        return fgets(STDIN);
    }

    /**
     * Asks the user for input.
     *
     * Takes its arguments by position and by type rather than by a fixed signature:
     *
     *     cli::prompt();                                       // wait for any key press
     *     $color = cli::prompt('Favorite color?');             // free-form answer
     *     $color = cli::prompt('Favorite color?', 'white');    // string second arg is a default
     *     $ready = cli::prompt('Ready?', ['y', 'n']);          // array second arg is a whitelist
     *     $ready = cli::prompt(['y', 'n']);                    // options with no question
     *
     * Passing true as the last argument makes the answer required. Required answers and answers
     * outside the whitelist re-ask by recursion, so a closed STDIN loops — only prompt when the
     * terminal is interactive.
     *
     * @return  string|null  the user input, or the default when nothing was typed
     */
    public static function prompt()
    {
        $args = func_get_args();

        $options = [];
        $output = '';
        $default = null;

        $arg_count = count($args);

        $required = end($args) === true;

        // The required flag is consumed here, so the count below only sees question / options
        if ($required === true)
        {
            --$arg_count;
        }

        switch ($arg_count)
        {
            case 2:
                if (is_array($args[1]))
                {
                    list($output, $options) = $args;
                }
                elseif (is_string($args[1]))
                {
                    list($output, $default) = $args;
                }

                break;

            case 1:
                if (is_array($args[0]))
                {
                    $options = $args[0];
                }
                elseif (is_string($args[0]))
                {
                    $output = $args[0];
                }

                break;
        }

        if ($output !== '')
        {
            $extra_output = '';

            if ($default !== null)
            {
                $extra_output = ' [ Default: "' . $default . '" ]';
            }
            elseif ($options !== [])
            {
                $extra_output = ' [ ' . implode(', ', $options) . ' ]';
            }

            fwrite(static::$STDOUT, $output . $extra_output . ': ');
        }

        $input = trim(static::input()) ?: $default;

        if (empty($input) && $required === true)
        {
            static::write('This is required.');
            static::new_line();

            $input = forward_static_call_array([__CLASS__, 'prompt'], $args);
        }

        if ( ! empty($options) && ! in_array($input, $options))
        {
            static::write('This is not a valid option. Please try again.');
            static::new_line();

            $input = forward_static_call_array([__CLASS__, 'prompt'], $args);
        }

        return $input;
    }

    /**
     * Writes a line to STDOUT, imploding arrays with a line break.
     *
     * @param string|array  $text        the text to output, or array of lines
     * @param string        $foreground  the foreground color
     * @param string        $background  the background color
     * @return void
     * @throws Exception    On an unknown color name
     */
    public static function write($text = '', $foreground = null, $background = null)
    {
        if (is_array($text))
        {
            $text = implode(PHP_EOL, $text);
        }

        if ($foreground || $background)
        {
            $text = static::color($text, $foreground, $background);
        }

        fwrite(static::$STDOUT, $text . PHP_EOL);
    }

    /**
     * Severity shorthands for write(). Each one only picks a color — everything still goes to
     * STDOUT. They exist so log::save() can call cli::$level($msg) with a log level name.
     *
     * @param string|array  $text        the text to output, or array of lines
     * @param string        $foreground  the foreground color
     * @param string        $background  the background color
     * @return void
     * @throws Exception    On an unknown color name
     */
    public static function error($text = '', $foreground = 'light_red', $background = null)
    {
        self::write($text, $foreground, $background);
    }

    public static function warning($text = '', $foreground = 'light_yellow', $background = null)
    {
        self::write($text, $foreground, $background);
    }

    public static function info($text = '', $foreground = 'light_white', $background = null)
    {
        self::write($text, $foreground, $background);
    }

    public static function notice($text = '', $foreground = 'light_cyan', $background = null)
    {
        self::write($text, $foreground, $background);
    }

    public static function debug($text = '', $foreground = 'light_green', $background = null)
    {
        self::write($text, $foreground, $background);
    }

    public static function critical($text = '', $foreground = 'light_red', $background = null)
    {
        self::write($text, $foreground, $background);
    }

    public static function alert($text = '', $foreground = 'light_purple', $background = null)
    {
        self::write($text, $foreground, $background);
    }

    public static function emergency($text = '', $foreground = 'light_blue', $background = null)
    {
        self::write($text, $foreground, $background);
    }

    /**
     * @param   int  $num  the number of times to beep
     * @return  void
     */
    public static function beep($num = 1)
    {
        echo str_repeat("\x07", $num);
    }

    /**
     * Sleeps for the given number of seconds, or waits for a key press when given none.
     *
     * @param   int   $seconds    number of seconds; 0 waits for input instead
     * @param   bool  $countdown  print the remaining seconds while sleeping
     * @return  void
     */
    public static function wait($seconds = 0, $countdown = false)
    {
        if ($countdown === true)
        {
            $time = $seconds;

            while ($time > 0)
            {
                fwrite(static::$STDOUT, $time . '... ');
                sleep(1);
                $time--;
            }
            static::write();
        }
        else
        {
            if ($seconds > 0)
            {
                sleep($seconds);
            }
            else
            {
                static::write(static::$wait_msg);
                static::input();
            }
        }
    }

    /**
     * @return bool
     */
    public static function is_windows()
    {
        return 'win' === strtolower(substr(php_uname("s"), 0, 3));
    }

    /**
     * @param   int  $num  number of lines to output
     * @return  void
     */
    public static function new_line($num = 1)
    {
        for ($i = 0; $i < $num; $i++)
        {
            static::write();
        }
    }

    /**
     * Clears the screen of output
     *
     * @return void
     */
    public static function clear_screen()
    {
        static::is_windows()

            // cmd.exe has no clear sequence, but its buffer is short enough to scroll away
            ? static::new_line(40)

            : fwrite(static::$STDOUT, chr(27) . "[H" . chr(27) . "[2J");
    }

    /**
     * Wraps text in ANSI color codes, or returns it untouched when the terminal cannot show them.
     *
     * @param   string  $text        the text to color
     * @param   string  $foreground  the foreground color
     * @param   string  $background  the background color
     * @param   string  $format      other formatting to apply. Currently only 'underline' is understood
     * @return  string  the color coded string
     * @throws  Exception  On a color name that is not in the tables above
     */
    public static function color($text, $foreground, $background = null, $format = null)
    {
        if ( static::is_windows() && ! isset($_SERVER['ANSICON']) )
        {
            return $text;
        }

        if ( static::$nocolor )
        {
            return $text;
        }

        if ( ! array_key_exists($foreground, static::$foreground_colors))
        {
            throw new Exception('Invalid CLI foreground color: ' . $foreground);
        }

        if ( $background !== null && ! array_key_exists($background, static::$background_colors))
        {
            throw new Exception('Invalid CLI background color: ' . $background);
        }

        $string = "\033[" . static::$foreground_colors[$foreground] . "m";

        if ($background !== null)
        {
            $string .= "\033[" . static::$background_colors[$background] . "m";
        }

        if ($format === 'underline')
        {
            $string .= "\033[4m";
        }

        $string .= $text . "\033[0m";

        return $string;
    }

    /**
     * Launches a background process and returns without waiting for it.
     *
     * Provides no security of its own: $call is handed to the shell as written and must already
     * be escaped by the caller.
     *
     * @param   string  $call    the system call to make
     * @param   string  $output  file the process output is redirected to (ignored on Windows)
     * @return  void
     */
    public static function spawn($call, $output = '/dev/null')
    {
        if (static::is_windows())
        {
            pclose(popen('start /b ' . $call, 'r'));
        }
        else
        {
            pclose(popen($call . ' > ' . $output . ' &', 'r'));
        }
    }

    /**
     * Sets the STDERR handle and returns the previous one. Call with no argument to read it.
     *
     * A string is opened with mode "w", truncating an existing file.
     *
     * @param   resource|string|null  $fh  Opened filehandle or string filename.
     * @return  resource  the handle in use before this call
     */
    public static function stderr($fh = null)
    {
        $orig = static::$STDERR;

        if (! is_null($fh))
        {
            if (is_string($fh))
            {
                $fh = fopen($fh, "w");
            }
            static::$STDERR = $fh;
        }

        return $orig;
    }

    /**
     * Sets the STDOUT handle and returns the previous one. Call with no argument to read it.
     *
     * Everything write() emits goes through this handle, so pointing it at a file captures all
     * terminal output including the severity shorthands.
     *
     * A string is opened with mode "w", truncating an existing file.
     *
     * @param   resource|string|null  $fh  Opened filehandle or string filename.
     * @return  resource  the handle in use before this call
     */
    public static function stdout($fh = null)
    {
        $orig = static::$STDOUT;

        if (! is_null($fh))
        {
            if (is_string($fh))
            {
                $fh = fopen($fh, "w");
            }
            static::$STDOUT = $fh;
        }

        return $orig;
    }
}
