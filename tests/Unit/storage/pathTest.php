<?php
/**
 * plato\storage\path: what a disk is allowed to act on.
 *
 * The rule under test is **reject, do not normalise**. A `..` that gets stripped turns a traversal
 * attempt into a successful read of the wrong file with nothing in the log to say so, which is the
 * failure mode this class exists to prevent.
 */

use plato\exception\storage_exception;
use plato\storage\path;

it('passes an ordinary relative path through', function () {
    expect(path::clean('avatars/7/original.png'))->toBe('avatars/7/original.png')
        ->and(path::clean('file.txt'))->toBe('file.txt');
});

it('collapses repeated slashes and drops a trailing one', function () {
    // The one thing tidied rather than refused: these name the same file everywhere
    expect(path::clean('a//b///c'))->toBe('a/b/c')
        ->and(path::clean('a/b/'))->toBe('a/b');
});

it('keeps what is legal in a name', function () {
    expect(path::clean('reports/Q3 2026.pdf'))->toBe('reports/Q3 2026.pdf')
        ->and(path::clean('文档/说明.txt'))->toBe('文档/说明.txt')
        ->and(path::clean('archive.tar.gz'))->toBe('archive.tar.gz');
});

it('refuses an absolute path', function () {
    path::clean('/etc/passwd');
})->throws(storage_exception::class);

it('refuses a windows drive letter', function () {
    path::clean('C:/windows/system32');
})->throws(storage_exception::class);

it('refuses a path that walks up', function () {
    path::clean('../../etc/passwd');
})->throws(storage_exception::class);

it('refuses a path that walks up in the middle', function () {
    // The one a normaliser would quietly turn into 'etc/passwd'
    path::clean('avatars/../../etc/passwd');
})->throws(storage_exception::class);

it('refuses a single dot segment', function () {
    path::clean('a/./b');
})->throws(storage_exception::class);

it('refuses a backslash', function () {
    // A separator on Windows and a legal filename character elsewhere: the same string would name
    // two different files depending on where the code runs
    path::clean('a\\b');
})->throws(storage_exception::class);

it('refuses a null byte', function () {
    path::clean("a/b\0.png");
})->throws(storage_exception::class);

it('refuses a protocol wrapper', function () {
    expect(fn () => path::clean('php://filter/read=convert.base64-encode/resource=x'))
        ->toThrow(storage_exception::class);

    expect(fn () => path::clean('data://text/plain;base64,aGk='))->toThrow(storage_exception::class);
    expect(fn () => path::clean('phar://x.phar/y'))->toThrow(storage_exception::class);
});

it('refuses an empty path', function () {
    path::clean('');
})->throws(storage_exception::class);

it('refuses a path that is only separators', function () {
    path::clean('///');
})->throws(storage_exception::class);

it('lets an empty prefix mean the root', function () {
    expect(path::clean_prefix(''))->toBe('')
        ->and(path::clean_prefix('/'))->toBe('')
        ->and(path::clean_prefix('avatars'))->toBe('avatars');
});

it('holds a prefix to the same rules as a path', function () {
    path::clean_prefix('../secrets');
})->throws(storage_exception::class);
