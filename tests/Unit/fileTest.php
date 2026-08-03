<?php
/**
 * file: filesystem helpers.
 */

use plato\plato;
use plato\file;

it('reads the extension off a filename', function () {
    expect(file::file_ext('archive.log'))->toBe('log');
    // Only the last extension counts
    expect(file::file_ext('archive.log.ei'))->toBe('ei');
});

it('creates a missing directory and returns its path', function () {
    $path = plato::data_path('file_test');
    @rmdir($path);

    expect(file::path_exists($path))->toBe($path);
    expect(is_dir($path))->toBeTrue();
});

it('returns the path of a directory that already exists', function () {
    $path = plato::data_path('file_test');
    file::path_exists($path);

    expect(file::path_exists($path))->toBe($path);
});
