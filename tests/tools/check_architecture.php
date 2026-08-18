<?php

/**
 * The architecture gate: rules a reviewer would otherwise have to remember
 *
 * Run from the repository root:
 *
 *     composer check:architecture
 *     composer check:architecture -- --update-api    # accept the current public surface
 *
 * Everything here is checked against a structural reading of the source (tests/tools/lib/
 * php_parser.php), not against patterns over the text. A rule that cannot tell a declaration from
 * the same words inside a comment is a rule that gets switched off the first time it cries wolf.
 *
 * The seven things it will not let through:
 *
 *   1. layout        namespace, directory and class name are one and the same
 *   2. references    every plato\ name mentioned -- imported, extended, called, or written out as
 *                    a string FQCN in a callable -- resolves to something that exists
 *   3. lazy init     the three axes documented in CONTRIBUTING.md, and nothing else
 *   4. config owner  one class owns one section of config/config.php; the rest read through it
 *   5. resources     a class that opens a connection or a handle registers it with plato\runtime
 *   6. reset         every reset() is classified: request scoped ones are called by
 *                    plato::reset_request(), configuration ones put $_config back to null
 *   7. public API    the public surface matches the recorded snapshot
 *
 * Adding a rule here is cheaper than finding out in somebody else's project. Adding an exception
 * is fine too -- every table below takes a reason, and an entry without one is not an exception,
 * it is a note to nobody.
 *
 * @package  PlatoPHP
 * @license  MIT
 */

require __DIR__ . '/lib/php_parser.php';

$root = dirname(__DIR__, 2);
$src  = $root . '/src';

/**
 * Classes allowed a boot-once entry point of their own (axis B), and why.
 *
 * @var array<string, string>
 */
const AXIS_B = [
    'src/cli.php' => 'parses argv and opens STDOUT/STDERR; plato::_bootstrap() calls it',
    'src/log.php' => 'registers the shutdown flush and the request id; plato::_bootstrap() calls it',
];

/**
 * Owners of the sections of config/config.php that expose the axis A accessor.
 *
 * @var array<string, string>
 */
const CONFIG_OWNERS = [
    'cookie'   => 'src/http/resp.php',
    'date'     => 'src/date.php',
    'lock'     => 'src/lock.php',
    'request'  => 'src/http/req.php',
    'security' => 'src/security/security.php',
    'sign'     => 'src/security/sign.php',
];

/**
 * Static state that belongs to one request, and is therefore cleared by plato::reset_request()
 * before a resident worker takes the next one.
 *
 * The value is the call reset_request() has to make. A class in this table that reset_request()
 * does not call leaves one request's state visible to the next one.
 *
 * @var array<string, string>
 */
const REQUEST_RESET = [
    'plato\http\req'             => 'reset_input',
    'plato\http\upload'          => 'reset',
    'plato\http\route'           => 'reset',
    'plato\http\envelope'        => 'reset',
    'plato\http\resp'            => 'reset',
    'plato\tpl'                  => 'reset',
    'plato\debug\profiler'       => 'reset',
    'plato\debug\error_handler'  => 'reset',
    'plato\log'                  => 'reset',
];

/**
 * reset() methods that put a configuration back to the file rather than clearing request state.
 *
 * Axis A: `$_config = null`, so the next config() reads config/*.php again. Nothing in
 * reset_request() calls these -- an application that overrode a setting means the override to
 * survive the request.
 *
 * @var array<string, string>
 */
const CONFIG_RESET = [
    'plato\cache\cache'      => 'cache settings, including which driver is built',
    'plato\date'             => 'date settings: the display timezone and the default format',
    'plato\security\sign'    => 'signing settings: algorithm, style and the excluded fields',
    'plato\cache\redis'      => 'connection settings of the shared redis instances',
    'plato\http\route'       => 'router settings; the same class also clears request state',
    'plato\http\envelope'    => 'envelope settings; the same class also clears request state',
    'plato\http\req'         => 'request settings; reset_input() is the request scoped half',
    'plato\http\resp'        => 'cookie settings; the same class also clears request state',
    'plato\lock'             => 'lock settings',
    'plato\queue\queue'      => 'queue connections',
    'plato\security\security' => 'security settings',
    'plato\storage\storage'  => 'storage disks',
    'plato\tpl'              => 'template settings; the same class also clears request state',
    'plato\server\dispatcher'    => 'dispatch settings',
    'plato\server\server'        => 'server settings',
    'plato\database\db'      => 'query log settings',
    'plato\event'            => 'listener registry, which is process scoped',
    'plato\debug\benchmark'  => 'marks, which plato::reset_request() clears by hand',
    'plato\http\pipeline'    => 'the middleware map',
    'plato\http\rewrite'     => 'the rewrite rules, and the flag saying they have been loaded',
    'plato\security\throttle' => 'throttle settings; the counters themselves live in the cache',
    'plato\console\schedule' => 'schedule settings',
];

/**
 * Ways of opening something that outlives the statement that opened it.
 *
 * @var array<int, string>
 */
const RESOURCE_NEWS = [
    'Redis',
    'RedisCluster',
    'Memcached',
    'PDO',
    'MongoDB\Driver\Manager',
    'RdKafka\Producer',
    'RdKafka\KafkaConsumer',
];

/**
 * @var array<int, string>
 */
const RESOURCE_CALLS = ['fopen', 'fsockopen', 'stream_socket_client', 'proc_open', 'curl_multi_init'];

/**
 * Files that open something and are right not to register it, and why.
 *
 * @var array<string, string>
 */
const RESOURCE_EXEMPT = [
    'src/http/client.php'  => 'a curl handle lives for one request and is closed in the same method, so a fork cannot inherit one',
    'src/cli.php'          => 'STDOUT / STDERR are the process\' own descriptors, not something this package opened',
    'src/console/make.php' => 'writes generated files with file_put_contents and keeps no handle',
    'src/storage/local.php' => 'opens a file per operation and closes it before returning',
];

/**
 * Names outside this package that a plato\ file may mention.
 *
 * Anything else written as `plato\...` has to be a class this package declares.
 *
 * @var array<int, string>
 */
const KNOWN_FOREIGN = ['plato\psr'];

$options  = array_slice($argv, 1);
$updating = in_array('--update-api', $options, true);

$errors = [];
$files  = [];

foreach ( plato_php_files($src) as $path )
{
    $rel          = ltrim(str_replace($root, '', $path), '/');
    $files[$rel]  = plato_parse_php($path, (string) file_get_contents($path));
}

/**
 * Every class this package declares, FQCN => [file, class].
 *
 * @var array<string, array{0: string, 1: array<string, mixed>}>
 */
$declared = [];

foreach ( $files as $rel => $file )
{
    foreach ( $file['classes'] as $class )
    {
        $fqcn            = ltrim($file['namespace'] . '\\' . $class['name'], '\\');
        $declared[$fqcn] = [$rel, $class];
    }
}

//-------------------------------------------------------------
// 1. Layout: namespace = directory, class = file name, one per file
//-------------------------------------------------------------

foreach ( $files as $rel => $file )
{
    $dir      = trim(str_replace('/', '\\', substr(dirname($rel), strlen('src'))), '\\');
    $expected = rtrim('plato\\' . $dir, '\\');

    if ( $file['namespace'] !== $expected )
    {
        $errors[] = "{$rel}: declares namespace {$file['namespace']}, the directory says {$expected}";
    }

    if ( count($file['classes']) !== 1 )
    {
        $errors[] = "{$rel}: declares " . count($file['classes']) . ' types; a file holds exactly one';

        continue;
    }

    $name = $file['classes'][0]['name'];
    $stem = basename($rel, '.php');

    if ( $name !== $stem )
    {
        $errors[] = "{$rel}: declares {$name}, the file name says {$stem}";
    }
}

//-------------------------------------------------------------
// 2. References: every plato\ name mentioned resolves
//-------------------------------------------------------------

foreach ( $files as $rel => $file )
{
    $mentions = [];

    foreach ( $file['uses'] as $fqcn )
    {
        $mentions[$fqcn] = 'use statement';
    }

    foreach ( $file['classes'] as $class )
    {
        foreach ( $class['parents'] as $parent )
        {
            $mentions[plato_resolve($parent, $file)] = "{$class['name']} extends or implements it";
        }
    }

    foreach ( $file['news'] as $new )
    {
        $mentions[plato_resolve($new['class'], $file)] = "new, line {$new['line']}";
    }

    foreach ( $file['calls'] as $call )
    {
        $mentions[plato_resolve($call['class'], $file)] = "{$call['class']}::{$call['method']}(), line {$call['line']}";
    }

    foreach ( $mentions as $fqcn => $where )
    {
        if ( !plato_is_plato($fqcn) || isset($declared[$fqcn]) )
        {
            continue;
        }

        $errors[] = "{$rel}: {$fqcn} does not exist ({$where})";
    }

    // A string FQCN -- `['plato\lock', 'unlock']`, `plato\queue\redis::class` written out by hand
    // -- is the reference a rename silently leaves behind, because nothing else in the toolchain
    // reads it
    foreach ( $file['strings'] as $string )
    {
        $value = trim($string['value'], '\\');

        if ( !plato_looks_like_fqcn($value) || !plato_is_plato($value) )
        {
            continue;
        }

        if ( !isset($declared[$value]) )
        {
            $errors[] = "{$rel}: the string '{$value}' on line {$string['line']} names no class";
        }
    }
}

// A string FQCN followed by a method name is a callable; the method has to be there too
foreach ( $files as $rel => $file )
{
    foreach ( plato_string_callables($file) as $callable )
    {
        [$fqcn, $method, $line] = $callable;

        if ( !isset($declared[$fqcn]) )
        {
            continue;
        }

        $names = array_column($declared[$fqcn][1]['methods'], 'name');

        if ( !in_array($method, $names, true) )
        {
            $errors[] = "{$rel}: the callable ['{$fqcn}', '{$method}'] on line {$line} names no method";
        }
    }
}

//-------------------------------------------------------------
// 3. Lazy initialisation: the three axes, and nothing else
//-------------------------------------------------------------

foreach ( $files as $rel => $file )
{
    $class = $file['classes'][0] ?? null;

    if ( $class === null )
    {
        continue;
    }

    $methods    = plato_by_name($class['methods']);
    $properties = plato_by_name($class['properties']);

    if ( isset($methods['_boot']) )
    {
        $errors[] = "{$rel}: still declares _boot(); axis A is a lazy config() accessor now";
    }

    if ( isset($properties['_booted']) && !isset(AXIS_B[$rel]) )
    {
        $errors[] = "{$rel}: has a \$_booted flag; the null sentinel on \$_config replaces it";
    }

    if ( isset($methods['boot']) && $methods['boot']['visibility'] === 'public' && !isset(AXIS_B[$rel]) )
    {
        $errors[] = "{$rel}: declares a public boot(); only " . implode(', ', array_keys(AXIS_B)) . ' may';
    }

    if ( !isset($methods['configure']) || !$methods['configure']['static'] )
    {
        continue;
    }

    if ( !isset($methods['config']) || !$methods['config']['static'] )
    {
        $errors[] = "{$rel}: has a static configure() but no static config() accessor to read through";
    }

    $sentinel = $properties['_config'] ?? null;

    if ( $sentinel === null || !$sentinel['static'] || $sentinel['visibility'] === 'public' )
    {
        $errors[] = "{$rel}: has configure() but no private static \$_config sentinel";
    }

    $body = $methods['configure']['body'];

    if ( !str_contains($body, '$config + ') && !str_contains($body, '$map + ') )
    {
        $errors[] = "{$rel}: configure() replaces the settings instead of merging on top of them";
    }

    $resets = false;

    foreach ( $class['methods'] as $method )
    {
        if ( preg_match('/\$_config\s*=\s*null\s*;/', $method['body']) )
        {
            $resets = true;
        }
    }

    if ( !$resets )
    {
        $errors[] = "{$rel}: has configure() but nothing puts \$_config back to null";
    }
}

// Driver contracts are configured objects, not static binding slots
foreach ( ['src/queue/driver.php', 'src/server/driver.php'] as $rel )
{
    $class = $files[$rel]['classes'][0] ?? null;

    if ( $class === null )
    {
        $errors[] = "{$rel}: is not there any more; the driver contract check has nothing to read";

        continue;
    }

    $methods = plato_by_name($class['methods']);

    if ( !isset($methods['configure']) || $methods['configure']['static'] )
    {
        $errors[] = "{$rel}: the driver contract should declare an instance configure(array \$config): void";
    }
}

//-------------------------------------------------------------
// 4. One owner per configuration section
//-------------------------------------------------------------

foreach ( $files as $rel => $file )
{
    foreach ( plato_config_reads($file) as $read )
    {
        [$module, $key, $line] = $read;

        if ( $module !== 'config' )
        {
            continue;
        }

        $section = explode('.', $key)[0];
        $owner   = CONFIG_OWNERS[$section] ?? null;

        if ( $owner === null || $owner === $rel )
        {
            continue;
        }

        $class    = basename($owner, '.php');
        $errors[] = "{$rel}: reads the {$section} section directly on line {$line}; go through {$class}::config()";
    }
}

//-------------------------------------------------------------
// 5. Anything holding a resource registers it with plato\runtime
//-------------------------------------------------------------

foreach ( $files as $rel => $file )
{
    if ( isset(RESOURCE_EXEMPT[$rel]) )
    {
        continue;
    }

    $opens = [];

    foreach ( $file['news'] as $new )
    {
        $name = trim(plato_resolve($new['class'], $file), '\\');

        if ( in_array($name, RESOURCE_NEWS, true) )
        {
            $opens[] = "new {$name} on line {$new['line']}";
        }
    }

    foreach ( plato_function_calls($file, RESOURCE_CALLS) as $call )
    {
        $opens[] = "{$call[0]}() on line {$call[1]}";
    }

    if ( $opens === [] )
    {
        continue;
    }

    $registers = false;

    foreach ( $file['calls'] as $call )
    {
        if ( plato_resolve($call['class'], $file) === 'plato\runtime' )
        {
            $registers = true;
        }
    }

    if ( !$registers )
    {
        $errors[] = "{$rel}: opens something that outlives the call ("
            . implode(', ', $opens)
            . ') without going through plato\\runtime; register it, or add it to RESOURCE_EXEMPT with a reason';
    }
}

//-------------------------------------------------------------
// 6. Every reset() is classified, and the request scoped ones are called
//-------------------------------------------------------------

$reset_request = $declared['plato\plato'][1] ?? null;
$reset_body    = '';

if ( $reset_request !== null )
{
    foreach ( $reset_request['methods'] as $method )
    {
        if ( $method['name'] === 'reset_request' )
        {
            $reset_body = $method['body'];
        }
    }
}

if ( $reset_body === '' )
{
    $errors[] = 'src/plato.php: no reset_request(); nothing clears request state between two requests of a worker';
}

foreach ( $declared as $fqcn => [$rel, $class] )
{
    foreach ( $class['methods'] as $method )
    {
        if ( !$method['static'] || $method['visibility'] !== 'public' )
        {
            continue;
        }

        if ( !in_array($method['name'], ['reset', 'reset_input'], true) )
        {
            continue;
        }

        $request = REQUEST_RESET[$fqcn] ?? null;
        $config  = CONFIG_RESET[$fqcn] ?? null;

        if ( $request === null && $config === null )
        {
            $errors[] = "{$rel}: {$fqcn}::{$method['name']}() is classified neither in REQUEST_RESET nor in"
                . ' CONFIG_RESET of ' . basename(__FILE__) . '; say which kind of state it clears';
        }
    }
}

foreach ( REQUEST_RESET as $fqcn => $method )
{
    if ( !isset($declared[$fqcn]) )
    {
        $errors[] = 'check_architecture.php: REQUEST_RESET names ' . $fqcn . ', which does not exist';

        continue;
    }

    $short = plato_basename($fqcn);

    if ( !str_contains($reset_body, $short . '::' . $method . '(') )
    {
        $errors[] = "src/plato.php: reset_request() does not call {$short}::{$method}(), so its request"
            . ' state survives into the next request of a resident worker';
    }
}

//-------------------------------------------------------------
// 7. The public surface matches the snapshot
//-------------------------------------------------------------

$snapshot_file = __DIR__ . '/api-snapshot.txt';
$snapshot      = plato_api_snapshot($declared);

if ( $updating )
{
    file_put_contents($snapshot_file, $snapshot);

    echo 'public API snapshot written: ' . count(explode("\n", trim($snapshot))) . " symbols\n";
}
elseif ( !is_file($snapshot_file) )
{
    $errors[] = 'tests/tools/api-snapshot.txt is missing; run `composer check:architecture -- --update-api`';
}
else
{
    $recorded = (string) file_get_contents($snapshot_file);

    if ( $recorded !== $snapshot )
    {
        foreach ( plato_diff($recorded, $snapshot) as $change )
        {
            $errors[] = 'public API: ' . $change;
        }

        $errors[] = 'public API: if every change above is meant, record it with'
            . ' `composer check:architecture -- --update-api` and say so in CHANGELOG.md';
    }
}

//-------------------------------------------------------------

if ( $errors !== [] )
{
    fwrite(STDERR, "architecture check failed:\n\n");

    foreach ( $errors as $error )
    {
        fwrite(STDERR, '  - ' . $error . "\n");
    }

    fwrite(STDERR, "\n" . count($errors) . " problem(s)\n");

    exit(1);
}

echo 'architecture check passed over ' . count($files) . ' files and ' . count($declared) . " types\n";

//-------------------------------------------------------------
// Helpers
//-------------------------------------------------------------

/**
 * Every .php file under a directory, sorted.
 *
 * @param string $dir
 *
 * @return array<int, string>
 */
function plato_php_files(string $dir): array
{
    $out = [];
    $it  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

    foreach ( $it as $file )
    {
        if ( $file->isFile() && $file->getExtension() === 'php' )
        {
            $out[] = $file->getPathname();
        }
    }

    sort($out);

    return $out;
}

/**
 * Whether a name belongs to this package.
 *
 * @param string $name
 *
 * @return bool
 */
function plato_is_plato(string $name): bool
{
    if ( strtolower($name) === 'plato' || !str_starts_with(strtolower($name), 'plato\\') )
    {
        return false;
    }

    foreach ( KNOWN_FOREIGN as $prefix )
    {
        if ( strtolower($name) === $prefix )
        {
            return false;
        }
    }

    return true;
}

/**
 * Whether a string could be a qualified class name at all.
 *
 * Keeps a sentence that happens to start with the package name -- "plato\http\client requires the
 * curl extension" -- out of the reference check.
 *
 * @param string $value
 *
 * @return bool
 */
function plato_looks_like_fqcn(string $value): bool
{
    return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/', $value);
}

/**
 * Members keyed by name, last declaration winning.
 *
 * @param array<int, array<string, mixed>> $members
 *
 * @return array<string, array<string, mixed>>
 */
function plato_by_name(array $members): array
{
    $out = [];

    foreach ( $members as $member )
    {
        $out[$member['name']] = $member;
    }

    return $out;
}

/**
 * `['plato\some\thing', 'method']` written out as two adjacent strings.
 *
 * @param array<string, mixed> $file Parsed file
 *
 * @return array<int, array{0: string, 1: string, 2: int}>
 */
function plato_string_callables(array $file): array
{
    $tokens = $file['tokens'];
    $count  = count($tokens);
    $out    = [];

    for ( $i = 0; $i < $count; $i++ )
    {
        [$id, $text, $line] = $tokens[$i];

        if ( $id !== T_CONSTANT_ENCAPSED_STRING )
        {
            continue;
        }

        $value = trim(plato_string_value($text), '\\');

        if ( !plato_looks_like_fqcn($value) || !plato_is_plato($value) )
        {
            continue;
        }

        // The comma, then the method
        $j = plato_skip_trivia($tokens, $i + 1);

        if ( ($tokens[$j][1] ?? '') !== ',' )
        {
            continue;
        }

        $k = plato_skip_trivia($tokens, $j + 1);

        if ( ($tokens[$k][0] ?? null) !== T_CONSTANT_ENCAPSED_STRING )
        {
            continue;
        }

        $out[] = [$value, plato_string_value($tokens[$k][1]), $line];
    }

    return $out;
}

/**
 * `config::instance('module')->get('key')` reads, wherever they appear.
 *
 * @param array<string, mixed> $file Parsed file
 *
 * @return array<int, array{0: string, 1: string, 2: int}>  module, key, line
 */
function plato_config_reads(array $file): array
{
    $tokens = $file['tokens'];
    $count  = count($tokens);
    $out    = [];

    for ( $i = 0; $i < $count; $i++ )
    {
        if ( ($tokens[$i][0] ?? null) !== T_DOUBLE_COLON )
        {
            continue;
        }

        if ( plato_resolve(plato_previous_name($tokens, $i - 1), $file) !== 'plato\config' )
        {
            continue;
        }

        $j = plato_skip_trivia($tokens, $i + 1);

        if ( ($tokens[$j][1] ?? '') !== 'instance' )
        {
            continue;
        }

        $j = plato_skip_trivia($tokens, $j + 1);

        if ( ($tokens[$j][1] ?? '') !== '(' )
        {
            continue;
        }

        $j = plato_skip_trivia($tokens, $j + 1);

        if ( ($tokens[$j][0] ?? null) !== T_CONSTANT_ENCAPSED_STRING )
        {
            continue;
        }

        $module = plato_string_value($tokens[$j][1]);
        $line   = $tokens[$j][2];

        // `)->get(` or `)->set(`, then the key
        $j = plato_skip_trivia($tokens, $j + 1);
        $j = ($tokens[$j][1] ?? '') === ')' ? plato_skip_trivia($tokens, $j + 1) : $j;

        if ( ($tokens[$j][0] ?? null) !== T_OBJECT_OPERATOR )
        {
            continue;
        }

        $j = plato_skip_trivia($tokens, $j + 1);
        $j = plato_skip_trivia($tokens, $j + 1);

        if ( ($tokens[$j][1] ?? '') !== '(' )
        {
            continue;
        }

        $j = plato_skip_trivia($tokens, $j + 1);

        if ( ($tokens[$j][0] ?? null) !== T_CONSTANT_ENCAPSED_STRING )
        {
            continue;
        }

        $out[] = [$module, plato_string_value($tokens[$j][1]), $line];
    }

    return $out;
}

/**
 * Calls to one of a set of plain functions.
 *
 * @param array<string, mixed> $file  Parsed file
 * @param array<int, string>   $names Function names
 *
 * @return array<int, array{0: string, 1: int}>
 */
function plato_function_calls(array $file, array $names): array
{
    $tokens = $file['tokens'];
    $count  = count($tokens);
    $out    = [];

    for ( $i = 0; $i < $count; $i++ )
    {
        [$id, $text, $line] = $tokens[$i];

        if ( $id !== T_STRING || !in_array($text, $names, true) )
        {
            continue;
        }

        // A call, not a method of the same name and not a declaration
        $before = plato_previous_significant($tokens, $i - 1);

        if ( $before === '->' || $before === '::' || $before === 'function' )
        {
            continue;
        }

        if ( plato_next_significant($tokens, $i + 1) !== '(' )
        {
            continue;
        }

        $out[] = [$text, $line];
    }

    return $out;
}

/**
 * The next index that is not whitespace or a comment.
 *
 * @param array<int, array{0: int|null, 1: string, 2: int}> $tokens
 * @param int                                               $from
 *
 * @return int
 */
function plato_skip_trivia(array $tokens, int $from): int
{
    $count = count($tokens);

    for ( $i = $from; $i < $count; $i++ )
    {
        $id = $tokens[$i][0];

        if ( $id !== null && in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) )
        {
            continue;
        }

        return $i;
    }

    return $count - 1;
}

/**
 * The public surface, one symbol per line, sorted.
 *
 * Protected members are in it too: a subclass in somebody's project is bound by them just as much
 * as a caller is bound by the public ones. Private members are not.
 *
 * @param array<string, array{0: string, 1: array<string, mixed>}> $declared
 *
 * @return string
 */
function plato_api_snapshot(array $declared): string
{
    $lines = [];

    foreach ( $declared as $fqcn => [$rel, $class] )
    {
        $head = ($class['abstract'] ? 'abstract ' : '') . $class['kind'];

        if ( $class['parents'] !== [] )
        {
            $head .= ' : ' . implode(', ', $class['parents']);
        }

        $lines[] = $fqcn . "\t" . $head;

        foreach ( $class['constants'] as $constant )
        {
            if ( $constant['visibility'] !== 'private' )
            {
                $lines[] = $fqcn . '::' . $constant['name'] . "\t" . $constant['visibility'] . ' const';
            }
        }

        foreach ( $class['properties'] as $property )
        {
            if ( $property['visibility'] !== 'private' )
            {
                $lines[] = $fqcn . '::$' . $property['name'] . "\t"
                    . $property['visibility'] . ($property['static'] ? ' static' : '') . ' property';
            }
        }

        foreach ( $class['methods'] as $method )
        {
            if ( $method['visibility'] === 'private' )
            {
                continue;
            }

            $lines[] = $fqcn . '::' . $method['name'] . $method['params'] . "\t"
                . $method['visibility'] . ($method['static'] ? ' static' : '') . ' method';
        }
    }

    sort($lines, SORT_STRING);

    return implode("\n", $lines) . "\n";
}

/**
 * What changed between two snapshots.
 *
 * @param string $before
 * @param string $after
 *
 * @return array<int, string>
 */
function plato_diff(string $before, string $after): array
{
    $old = array_filter(explode("\n", $before), 'strlen');
    $new = array_filter(explode("\n", $after), 'strlen');

    $out = [];

    foreach ( array_diff($old, $new) as $line )
    {
        $out[] = 'gone:  ' . str_replace("\t", '  --  ', trim($line));
    }

    foreach ( array_diff($new, $old) as $line )
    {
        $out[] = 'added: ' . str_replace("\t", '  --  ', trim($line));
    }

    sort($out);

    return $out;
}
