<?php

/**
 * A structural reading of one PHP file, for the architecture checks
 *
 * Built on token_get_all() rather than on regular expressions. The checks it feeds ask questions
 * -- "is this method public and static", "does this class name resolve to something that exists",
 * "which class does this string FQCN point at" -- that a pattern can only guess at:
 *
 *   - a signature broken over several lines is one token stream and several lines of text;
 *   - `'plato\\lock'` inside a docblock, a comment or a heredoc of PHP sample code looks exactly
 *     like the real thing to a pattern, and nothing like it to the tokenizer;
 *   - `function config(` matches `public static function config(`, `private function config(` and
 *     `$fn = function config_of(` all the same.
 *
 * The model is deliberately shallow: names, modifiers, positions and the strings a file holds.
 * Nothing here builds an AST or follows control flow -- the checks are about shape, not behaviour.
 *
 * @package  PlatoPHP
 * @license  MIT
 */

/**
 * One parsed file.
 *
 * @param string $path Absolute path
 * @param string $code Contents of the file
 *
 * @return array{
 *     path: string,
 *     namespace: string,
 *     uses: array<string, string>,
 *     classes: array<int, array<string, mixed>>,
 *     strings: array<int, array{value: string, line: int}>,
 *     calls: array<int, array{class: string, method: string, line: int}>,
 *     news: array<int, array{class: string, line: int}>,
 *     functions: array<int, array{name: string, line: int}>
 * }
 */
function plato_parse_php(string $path, string $code): array
{
    $tokens = plato_tokens($code);
    $count  = count($tokens);

    $file = [
        'path'      => $path,
        'namespace' => '',
        'uses'      => [],
        'classes'   => [],
        'strings'   => [],
        'calls'     => [],
        'news'      => [],
        'functions' => [],
        'tokens'    => $tokens,
    ];

    // Class bodies are found by brace depth: the depth the class opened at, and the first closing
    // brace back down to it
    $depth      = 0;
    $class      = null;
    $class_depth = 0;
    $modifiers  = [];

    for ( $i = 0; $i < $count; $i++ )
    {
        [$id, $text, $line] = $tokens[$i];

        // Interpolation opens with a named token but closes with a plain `}`. Count both sides or
        // the class depth drops early and every member after the first "{$value}" disappears.
        if ( in_array($id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true) )
        {
            $depth++;

            continue;
        }

        if ( $id === null )
        {
            if ( $text === '{' )
            {
                $depth++;
            }
            elseif ( $text === '}' )
            {
                $depth--;

                if ( $class !== null && $depth === $class_depth )
                {
                    $file['classes'][] = $class;
                    $class             = null;
                }
            }

            continue;
        }

        switch ( $id )
        {
            case T_NAMESPACE:
                // `namespace foo;` at the top, not `namespace\thing` used as a relative name
                $name = plato_read_name($tokens, $i + 1, $next);
                if ( $name !== '' && plato_next_significant($tokens, $next) === ';' )
                {
                    $file['namespace'] = $name;
                }
                break;

            case T_USE:
                // Only an import: a trait use lives inside a class body, a closure `use` is
                // followed by an opening parenthesis
                if ( $class !== null || plato_next_significant($tokens, $i + 1) === '(' )
                {
                    break;
                }

                foreach ( plato_read_use($tokens, $i + 1, $next) as $alias => $fqcn )
                {
                    $file['uses'][$alias] = $fqcn;
                }
                $i = $next;
                break;

            case T_CLASS:
            case T_INTERFACE:
            case T_TRAIT:
            case T_ENUM:
                // `Foo::class` is a T_CLASS too
                if ( plato_previous_significant($tokens, $i - 1) === '::' )
                {
                    break;
                }

                $name = plato_read_name($tokens, $i + 1, $next);
                if ( $name === '' )
                {
                    // An anonymous class; it declares no symbol this tool has anything to say about
                    break;
                }

                $class = [
                    'name'       => $name,
                    'kind'       => strtolower(substr(token_name($id), 2)),
                    'abstract'   => in_array('abstract', $modifiers, true),
                    'final'      => in_array('final', $modifiers, true),
                    'parents'    => plato_read_parents($tokens, $next),
                    'line'       => $line,
                    'methods'    => [],
                    'properties' => [],
                    'constants'  => [],
                ];

                $class_depth = $depth;
                $i           = $next - 1;
                break;

            case T_FUNCTION:
                $name = plato_read_name($tokens, $i + 1, $next);

                if ( $name === '' )
                {
                    // A closure or an arrow function
                    break;
                }

                if ( $class === null )
                {
                    $file['functions'][] = ['name' => $name, 'line' => $line];
                    break;
                }

                $class['methods'][] = [
                    'name'       => $name,
                    'visibility' => plato_visibility($modifiers),
                    'static'     => in_array('static', $modifiers, true),
                    'abstract'   => in_array('abstract', $modifiers, true),
                    'params'     => plato_read_params($tokens, $next),
                    'body'       => plato_read_body($tokens, $next),
                    'line'       => $line,
                ];

                $i = $next - 1;
                break;

            case T_CONST:
                if ( $class === null )
                {
                    break;
                }

                $name = plato_read_name($tokens, $i + 1, $next);
                if ( $name !== '' )
                {
                    $class['constants'][] = [
                        'name'       => $name,
                        'visibility' => plato_visibility($modifiers),
                        'line'       => $line,
                    ];
                }
                break;

            case T_VARIABLE:
                // A property is a variable that follows a modifier or a type at class body level;
                // anything inside a method is at a deeper brace level
                if ( $class !== null && $depth === $class_depth + 1 && $modifiers !== [] )
                {
                    $class['properties'][] = [
                        'name'       => ltrim($text, '$'),
                        'visibility' => plato_visibility($modifiers),
                        'static'     => in_array('static', $modifiers, true),
                        'line'       => $line,
                    ];
                }
                break;

            case T_NEW:
                $name = plato_read_name($tokens, $i + 1, $next);
                if ( $name !== '' )
                {
                    $file['news'][] = ['class' => $name, 'line' => $line];
                    $i              = $next - 1;
                }
                break;

            case T_CONSTANT_ENCAPSED_STRING:
                $file['strings'][] = [
                    'value' => plato_string_value($text),
                    'line'  => $line,
                ];
                break;

            case T_DOUBLE_COLON:
                $target = plato_previous_name($tokens, $i - 1);
                $method = plato_read_name($tokens, $i + 1, $next);

                if ( $target !== '' && $method !== '' )
                {
                    $file['calls'][] = ['class' => $target, 'method' => $method, 'line' => $line];
                }
                break;
        }

        // Modifiers accumulate until something that can carry them turns up, and reset on the
        // statement separators
        if ( in_array($id, [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY], true) )
        {
            $modifiers[] = strtolower($text);
        }
        elseif ( !in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) )
        {
            if ( in_array($id, [T_FUNCTION, T_VARIABLE, T_CONST, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) )
            {
                $modifiers = [];
            }
        }
    }

    if ( $class !== null )
    {
        $file['classes'][] = $class;
    }

    return $file;
}

/**
 * token_get_all(), with every token in the same [id, text, line] shape.
 *
 * @param string $code
 *
 * @return array<int, array{0: int|null, 1: string, 2: int}>
 */
function plato_tokens(string $code): array
{
    $out  = [];
    $line = 1;

    foreach ( token_get_all($code) as $token )
    {
        if ( is_array($token) )
        {
            $out[] = [$token[0], $token[1], $token[2]];
            $line  = $token[2] + substr_count($token[1], "\n");

            continue;
        }

        $out[] = [null, $token, $line];
    }

    return $out;
}

/**
 * Read a (possibly qualified) name starting at $from, skipping whitespace and comments.
 *
 * @param array<int, array{0: int|null, 1: string, 2: int}> $tokens
 * @param int                                               $from
 * @param int|null                                          $next  Set to the index just past the name
 *
 * @return string  Empty when there is no name there
 */
function plato_read_name(array $tokens, int $from, ?int &$next = null): string
{
    $count = count($tokens);
    $name  = '';

    for ( $i = $from; $i < $count; $i++ )
    {
        [$id, $text] = $tokens[$i];

        if ( $id !== null && in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) )
        {
            if ( $name !== '' )
            {
                break;
            }

            continue;
        }

        // The tokenizer hands qualified names back whole from PHP 8, and piecewise before it
        $is_name = $id !== null && (
            $id === T_STRING
            || defined('T_NAME_QUALIFIED') && $id === T_NAME_QUALIFIED
            || defined('T_NAME_FULLY_QUALIFIED') && $id === T_NAME_FULLY_QUALIFIED
            || defined('T_NAME_RELATIVE') && $id === T_NAME_RELATIVE
            || $id === T_NS_SEPARATOR
            || $id === T_CLASS && $name !== ''
        );

        if ( !$is_name )
        {
            break;
        }

        $name .= $text;
    }

    $next = $i;

    // The leading backslash is kept: `\Exception` inside `namespace plato` is the global class,
    // and `Exception` is plato\Exception. Dropping it here would make the two the same name
    return $name;
}

/**
 * The name immediately before $from, for `something::` and `new something`.
 *
 * @param array<int, array{0: int|null, 1: string, 2: int}> $tokens
 * @param int                                               $from
 *
 * @return string
 */
function plato_previous_name(array $tokens, int $from): string
{
    $parts = [];

    for ( $i = $from; $i >= 0; $i-- )
    {
        [$id, $text] = $tokens[$i];

        if ( $id !== null && in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) )
        {
            if ( $parts !== [] )
            {
                break;
            }

            continue;
        }

        $is_name = $id !== null && (
            $id === T_STRING
            || $id === T_NS_SEPARATOR
            || defined('T_NAME_QUALIFIED') && $id === T_NAME_QUALIFIED
            || defined('T_NAME_FULLY_QUALIFIED') && $id === T_NAME_FULLY_QUALIFIED
            || defined('T_NAME_RELATIVE') && $id === T_NAME_RELATIVE
        );

        if ( !$is_name )
        {
            break;
        }

        array_unshift($parts, $text);
    }

    return implode('', $parts);
}

/**
 * The text of the next token that is not whitespace or a comment.
 *
 * @param array<int, array{0: int|null, 1: string, 2: int}> $tokens
 * @param int                                               $from
 *
 * @return string
 */
function plato_next_significant(array $tokens, int $from): string
{
    $count = count($tokens);

    for ( $i = $from; $i < $count; $i++ )
    {
        [$id, $text] = $tokens[$i];

        if ( $id !== null && in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) )
        {
            continue;
        }

        return $text;
    }

    return '';
}

/**
 * The text of the previous token that is not whitespace or a comment.
 *
 * @param array<int, array{0: int|null, 1: string, 2: int}> $tokens
 * @param int                                               $from
 *
 * @return string
 */
function plato_previous_significant(array $tokens, int $from): string
{
    for ( $i = $from; $i >= 0; $i-- )
    {
        [$id, $text] = $tokens[$i];

        if ( $id !== null && in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) )
        {
            continue;
        }

        return $text;
    }

    return '';
}

/**
 * Read one `use a\b as c, d\e;` statement.
 *
 * `use function` and `use const` are read as well and simply carry their own kind of name; the
 * checks that consume this only look up class names, so an extra entry costs nothing.
 *
 * @param array<int, array{0: int|null, 1: string, 2: int}> $tokens
 * @param int                                               $from
 * @param int|null                                          $next Set to the index of the `;`
 *
 * @return array<string, string>  Alias (lowercased last segment or the explicit one) => FQCN
 */
function plato_read_use(array $tokens, int $from, ?int &$next = null): array
{
    $count   = count($tokens);
    $imports = [];
    $name    = '';
    $alias   = '';
    $as      = false;

    for ( $i = $from; $i < $count; $i++ )
    {
        [$id, $text] = $tokens[$i];

        if ( $id === null && ($text === ';' || $text === '{') )
        {
            break;
        }

        if ( $id === null && $text === ',' )
        {
            if ( $name !== '' )
            {
                $imports[$alias !== '' ? $alias : plato_basename($name)] = $name;
            }

            $name  = '';
            $alias = '';
            $as    = false;

            continue;
        }

        if ( $id !== null && $id === T_AS )
        {
            $as = true;

            continue;
        }

        if ( $id !== null && in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) )
        {
            continue;
        }

        if ( $id !== null && in_array($id, [T_FUNCTION, T_CONST], true) )
        {
            continue;
        }

        $piece = $text;

        if ( $as )
        {
            $alias .= $piece;
        }
        else
        {
            $name .= $piece;
        }
    }

    if ( $name !== '' )
    {
        $imports[$alias !== '' ? $alias : plato_basename($name)] = trim($name, '\\');
    }

    $next = $i;

    return array_map(fn ($fqcn) => trim($fqcn, '\\'), $imports);
}

/**
 * The parameter list of a declaration whose name ends at $from, as normalised source.
 *
 * @param array<int, array{0: int|null, 1: string, 2: int}> $tokens
 * @param int                                               $from
 *
 * @return string  `(...)` included, whitespace collapsed
 */
function plato_read_params(array $tokens, int $from): string
{
    $count = count($tokens);
    $out   = '';
    $depth = 0;
    $open  = false;

    for ( $i = $from; $i < $count; $i++ )
    {
        [$id, $text] = $tokens[$i];

        if ( $id !== null && in_array($id, [T_COMMENT, T_DOC_COMMENT], true) )
        {
            continue;
        }

        if ( $id !== null && $id === T_WHITESPACE )
        {
            $out .= $open ? ' ' : '';

            continue;
        }

        if ( $id === null && $text === '(' )
        {
            $depth++;
            $open = true;
        }
        elseif ( $id === null && $text === ')' )
        {
            $depth--;
        }
        elseif ( !$open )
        {
            // Something other than the parameter list came first, so there is none
            break;
        }

        $out .= $text;

        if ( $open && $depth === 0 )
        {
            break;
        }
    }

    return preg_replace('/\s+/', ' ', trim($out)) ?? '';
}

/**
 * The names a class declaration extends or implements.
 *
 * @param array<int, array{0: int|null, 1: string, 2: int}> $tokens
 * @param int                                               $from Index just past the class name
 *
 * @return array<int, string>  Names as written
 */
function plato_read_parents(array $tokens, int $from): array
{
    $count   = count($tokens);
    $parents = [];
    $name    = '';

    for ( $i = $from; $i < $count; $i++ )
    {
        [$id, $text] = $tokens[$i];

        if ( $id === null && ($text === '{' || $text === ';') )
        {
            break;
        }

        if ( $id !== null && in_array($id, [T_EXTENDS, T_IMPLEMENTS], true) )
        {
            continue;
        }

        if ( $id === null && $text === ',' || $id !== null && $id === T_WHITESPACE )
        {
            if ( $name !== '' )
            {
                $parents[] = $name;
                $name      = '';
            }

            continue;
        }

        if ( $id !== null && in_array($id, [T_COMMENT, T_DOC_COMMENT], true) )
        {
            continue;
        }

        $name .= $text;
    }

    if ( $name !== '' )
    {
        $parents[] = $name;
    }

    return $parents;
}

/**
 * The body of a declaration whose name ends at $from, as source.
 *
 * @param array<int, array{0: int|null, 1: string, 2: int}> $tokens
 * @param int                                               $from
 *
 * @return string  Empty for an abstract method or an interface declaration
 */
function plato_read_body(array $tokens, int $from): string
{
    $count = count($tokens);
    $depth = 0;
    $out   = '';

    for ( $i = $from; $i < $count; $i++ )
    {
        [$id, $text] = $tokens[$i];

        if ( in_array($id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true) )
        {
            $depth++;

            if ( $depth > 0 )
            {
                $out .= $text;
            }

            continue;
        }

        if ( $id === null && $text === ';' && $depth === 0 )
        {
            return '';
        }

        if ( $id === null && $text === '{' )
        {
            $depth++;

            if ( $depth === 1 )
            {
                continue;
            }
        }
        elseif ( $id === null && $text === '}' )
        {
            $depth--;

            if ( $depth === 0 )
            {
                break;
            }
        }

        if ( $depth > 0 )
        {
            $out .= $text;
        }
    }

    return $out;
}

/**
 * The value of a quoted string token, with the quotes removed and the escapes of a single quoted
 * string resolved.
 *
 * @param string $text
 *
 * @return string
 */
function plato_string_value(string $text): string
{
    $quote = $text[0] ?? '';
    $body  = substr($text, 1, -1);

    if ( $quote === "'" )
    {
        return str_replace(['\\\\', "\\'"], ['\\', "'"], $body);
    }

    return stripcslashes($body);
}

/**
 * The last segment of a qualified name.
 *
 * @param string $name
 *
 * @return string
 */
function plato_basename(string $name): string
{
    $pos = strrpos($name, '\\');

    return $pos === false ? $name : substr($name, $pos + 1);
}

/**
 * The visibility a set of modifiers describes.
 *
 * @param array<int, string> $modifiers
 *
 * @return string
 */
function plato_visibility(array $modifiers): string
{
    foreach ( ['private', 'protected', 'public'] as $level )
    {
        if ( in_array($level, $modifiers, true) )
        {
            return $level;
        }
    }

    return 'public';
}

/**
 * Resolve a name written inside a file to a fully qualified one.
 *
 * @param string               $name Name as written
 * @param array<string, mixed> $file Parsed file
 *
 * @return string
 */
function plato_resolve(string $name, array $file): string
{
    $name = trim($name);

    if ( $name === '' )
    {
        return '';
    }

    // Already fully qualified as written
    if ( str_starts_with($name, '\\') )
    {
        return trim($name, '\\');
    }

    $first = explode('\\', $name)[0];

    if ( isset($file['uses'][$first]) )
    {
        $rest = substr($name, strlen($first));

        return $file['uses'][$first] . $rest;
    }

    if ( in_array(strtolower($name), ['self', 'static', 'parent'], true) )
    {
        return $name;
    }

    return $file['namespace'] === '' ? $name : $file['namespace'] . '\\' . $name;
}
