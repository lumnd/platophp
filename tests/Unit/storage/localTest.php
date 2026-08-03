<?php
/**
 * plato\storage\local, and the facade in front of it.
 *
 * The root is under the suite's temporary runtime directory, which Pest removes when the process
 * exits -- nothing here writes into the repository tree.
 */

use plato\exception\storage_exception;
use plato\plato;
use plato\storage\disk;
use plato\storage\local;
use plato\storage\storage;

plato::registry(plato_test_config());

/**
 * Root of the disk under test.
 */
function storage_test_root(): string
{
    return plato_test_data() . DIRECTORY_SEPARATOR . 'storage-test';
}

beforeEach(function () {
    storage::reset();
    storage::configure([
        'default' => 'local',
        'disks'   => [
            'local' => [
                'driver' => 'local',
                'root'   => storage_test_root(),
                'url'    => 'https://cdn.example/files',
            ],
        ],
    ]);
});

afterEach(function () {
    plato_test_rmdir(storage_test_root());
    storage::reset();
});

it('writes and reads a file', function () {
    expect(storage::put('a/b/hello.txt', 'hello'))->toBeTrue()
        ->and(storage::get('a/b/hello.txt'))->toBe('hello');
});

it('answers null for a file that is not there', function () {
    expect(storage::get('missing.txt'))->toBeNull()
        ->and(storage::exists('missing.txt'))->toBeFalse()
        ->and(storage::size('missing.txt'))->toBeNull()
        ->and(storage::modified('missing.txt'))->toBeNull();
});

it('creates the directories a path needs', function () {
    storage::put('deep/er/still/file.txt', 'x');

    expect(is_dir(storage_test_root() . '/deep/er/still'))->toBeTrue();
});

it('writes from a stream without holding the whole body', function () {
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, 'streamed');
    rewind($stream);

    expect(storage::put('from-stream.txt', $stream))->toBeTrue()
        ->and(storage::get('from-stream.txt'))->toBe('streamed');

    fclose($stream);
});

it('reports size and modification time', function () {
    storage::put('sized.txt', '12345');

    expect(storage::size('sized.txt'))->toBe(5)
        ->and(storage::modified('sized.txt'))->toBeGreaterThan(time() - 60);
});

it('treats deleting what is not there as success', function () {
    expect(storage::delete('never-existed.txt'))->toBeTrue();
});

it('deletes a file', function () {
    storage::put('gone.txt', 'x');

    expect(storage::delete('gone.txt'))->toBeTrue()
        ->and(storage::exists('gone.txt'))->toBeFalse();
});

it('copies and moves', function () {
    storage::put('one.txt', 'body');

    expect(storage::copy('one.txt', 'copies/two.txt'))->toBeTrue()
        ->and(storage::get('copies/two.txt'))->toBe('body')
        ->and(storage::exists('one.txt'))->toBeTrue();

    expect(storage::move('one.txt', 'moved/three.txt'))->toBeTrue()
        ->and(storage::get('moved/three.txt'))->toBe('body')
        ->and(storage::exists('one.txt'))->toBeFalse();
});

it('refuses to copy a file that is not there', function () {
    expect(storage::copy('missing.txt', 'anywhere.txt'))->toBeFalse();
});

it('lists the files under a prefix, and below it when asked', function () {
    storage::put('list/a.txt', 'a');
    storage::put('list/b.txt', 'b');
    storage::put('list/deep/c.txt', 'c');

    expect(storage::files('list'))->toBe(['list/a.txt', 'list/b.txt'])
        ->and(storage::files('list', true))->toBe(['list/a.txt', 'list/b.txt', 'list/deep/c.txt']);
});

it('lists nothing for a prefix that does not exist', function () {
    expect(storage::files('nowhere'))->toBe([]);
});

it('builds a url from the configured base', function () {
    expect(storage::url('a/b.png'))->toBe('https://cdn.example/files/a/b.png');
});

it('has no temporary url on a local disk', function () {
    // Expiring links for local files are a routing decision, not a storage one
    expect(storage::temporary_url('a/b.png'))->toBeNull();
});

it('refuses a path that would leave the root', function () {
    storage::put('../escaped.txt', 'x');
})->throws(storage_exception::class);

it('refuses to read through a traversal', function () {
    storage::get('a/../../../../etc/passwd');
})->throws(storage_exception::class);

it('resolves a disk by name and hands back the same instance', function () {
    expect(storage::disk('local'))->toBeInstanceOf(local::class)
        ->and(storage::disk('local'))->toBe(storage::disk());
});

it('complains about a disk that is not configured', function () {
    storage::disk('nowhere');
})->throws(storage_exception::class);

it('forgets disks removed by reconfiguration', function () {
    storage::configure([
        'default' => 'old',
        'disks'   => [
            'old' => ['driver' => 'local', 'root' => storage_test_root()],
        ],
    ]);

    storage::disk('old');

    storage::configure([
        'default' => 'local',
        'disks'   => storage_test_disks(),
    ]);

    storage::disk('old');
})->throws(storage_exception::class);

it('does not ship cloud storage drivers', function () {
    storage::configure([
        'default' => 'remote',
        'disks'   => [
            'remote' => ['driver' => 's3'],
        ],
    ]);

    storage::disk();
})->throws(storage_exception::class);

it('takes a driver an application registers', function () {
    storage::extend('fake', fake_disk::class);
    storage::configure([
        'disks' => ['fake' => ['driver' => 'fake']] + (array) storage_test_disks(),
    ]);

    expect(storage::disk('fake'))->toBeInstanceOf(fake_disk::class);
});

it('refuses to register something that is not a disk', function () {
    storage::extend('nope', stdClass::class);
})->throws(storage_exception::class);

/**
 * The disks the other cases configured, so extend() can add to them rather than replace them.
 *
 * @return array<string, mixed>
 */
function storage_test_disks(): array
{
    return [
        'local' => ['driver' => 'local', 'root' => storage_test_root()],
    ];
}

/**
 * A disk that does nothing, for the registration cases.
 */
class fake_disk implements disk
{
    public function configure(array $config): void
    {
    }

    public function get(string $path)
    {
        return null;
    }

    public function put(string $path, $contents, array $options = []): bool
    {
        return true;
    }

    public function exists(string $path): bool
    {
        return false;
    }

    public function delete(string $path): bool
    {
        return true;
    }

    public function copy(string $from, string $to): bool
    {
        return true;
    }

    public function move(string $from, string $to): bool
    {
        return true;
    }

    public function size(string $path)
    {
        return null;
    }

    public function modified(string $path)
    {
        return null;
    }

    public function files(string $prefix = '', bool $recursive = false): array
    {
        return [];
    }

    public function url(string $path)
    {
        return null;
    }

    public function temporary_url(string $path, int $seconds = 3600)
    {
        return null;
    }
}
