<?php
/**
 * Static compatibility sweep over src/.
 *
 * Lints every framework source file with the running PHP binary and greps for APIs the target
 * range (8.0 to 8.5) removed or deprecated. It shells out once per file, which is why it lives
 * in the Feature suite rather than among the unit tests.
 */

it('loads every framework source file without PHP compatibility warnings', function () {
    $source_path = dirname(__DIR__, 2) . '/src';
    $files   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source_path));
    $issues  = [];

    foreach ($files as $file)
    {
        if ( !$file->isFile() || $file->getExtension() !== 'php' )
        {
            continue;
        }

        $command = implode(' ', [
            escapeshellarg(PHP_BINARY),
            '-d',
            'error_reporting=' . E_ALL,
            '-d',
            'display_errors=1',
            '-l',
            escapeshellarg($file->getPathname()),
            '2>&1',
        ]);
        exec($command, $output, $status);
        $message = implode("\n", $output);

        if ( $status !== 0 || preg_match('/Deprecated|Warning|Fatal|Parse error/i', $message) )
        {
            $issues[$file->getPathname()] = $message;
        }

        $output = [];
    }

    expect($issues)->toBe([]);
});

it('does not use APIs removed or deprecated by PHP 8.5', function () {
    $source_path = dirname(__DIR__, 2) . '/src';
    $files   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source_path));
    $patterns = [
        '/\beach\s*\(/' => 'each() was removed in PHP 8.0',
        '/->setAccessible\s*\(/' => 'Reflection setAccessible() is deprecated in PHP 8.5',
        '/\butf8_(?:en|de)code\s*\(/' => 'utf8_encode()/utf8_decode() are deprecated',
        '/\bmysqli_ping\s*\(/' => 'mysqli_ping() is deprecated',
    ];
    $issues  = [];

    foreach ($files as $file)
    {
        if ( !$file->isFile() || $file->getExtension() !== 'php' )
        {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        foreach ($patterns as $pattern => $message)
        {
            if ( preg_match($pattern, $source) )
            {
                $issues[$file->getPathname()][] = $message;
            }
        }
    }

    expect($issues)->toBe([]);
});
