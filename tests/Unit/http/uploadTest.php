<?php
/**
 * plato\http\upload: the $_FILES half of the request.
 */

use plato\http\upload;

beforeEach(function () {
    upload::reset();
    upload::$filter_filename = '/\.(php|pl|sh|js)$/i';
});

afterEach(function () {
    upload::reset();
});

/**
 * One entry shaped the way PHP shapes a single file field.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function upload_entry(array $overrides = []): array
{
    return $overrides + [
        'name'     => 'holiday.JPG',
        'type'     => 'image/jpeg',
        'tmp_name' => '/tmp/phpAbc123',
        'error'    => UPLOAD_ERR_OK,
        'size'     => 2048,
    ];
}

it('takes the uploads over from $_FILES and empties it', function () {
    $_FILES = ['avatar' => upload_entry()];

    upload::capture($_FILES);

    expect(upload::all())->toHaveKey('avatar')
        ->and(isset($_FILES))->toBeFalse();
});

it('reports an upload PHP accepted', function () {
    $files = ['avatar' => upload_entry()];
    upload::capture($files);

    expect(upload::exists('avatar'))->toBeTrue()
        ->and(upload::exists('nothing'))->toBeFalse();
});

it('refuses an upload that arrived with an error code', function () {
    // Over upload_max_filesize is present in $_FILES with a code, not absent -- which is why
    // exists() tests the code and not the key
    $files = ['avatar' => upload_entry(['error' => UPLOAD_ERR_INI_SIZE])];
    upload::capture($files);

    expect(upload::exists('avatar'))->toBeFalse()
        ->and(upload::info('avatar'))->toBeArray();
});

it('answers the tmp name and the whole entry', function () {
    $files = ['avatar' => upload_entry()];
    upload::capture($files);

    expect(upload::tmp_name('avatar'))->toBe('/tmp/phpAbc123')
        ->and(upload::tmp_name('nothing', 'fallback'))->toBe('fallback')
        ->and(upload::info('avatar')['size'])->toBe(2048)
        ->and(upload::info('nothing'))->toBeFalse();
});

it('takes the extension from the content type when it names an image', function () {
    $files = ['avatar' => upload_entry(['type' => 'image/png', 'name' => 'whatever.txt'])];
    upload::capture($files);

    expect(upload::extension('avatar'))->toBe('png');
});

it('falls back to the file name, lower cased', function () {
    $files = ['doc' => upload_entry(['type' => 'application/octet-stream', 'name' => 'Report.CSV'])];
    upload::capture($files);

    expect(upload::extension('doc'))->toBe('csv');
});

it('answers an empty extension when neither the type nor the name says anything', function () {
    $files = ['blob' => upload_entry(['type' => 'application/octet-stream', 'name' => 'noextension'])];
    upload::capture($files);

    expect(upload::extension('blob'))->toBe('');
});

it('checks an extension against a list', function () {
    $files = ['doc' => upload_entry(['type' => 'text/csv', 'name' => 'rows.csv'])];
    upload::capture($files);

    expect(upload::extension_is('doc'))->toBeTrue()
        ->and(upload::extension_is('doc', ['xlsx', 'csv']))->toBeTrue()
        ->and(upload::extension_is('doc', ['xlsx']))->toBeFalse();
});

it('addresses one file of an array field by index', function () {
    $files = ['photos' => [
        'name'     => ['a.png', 'b.gif'],
        'type'     => ['image/png', 'image/gif'],
        'tmp_name' => ['/tmp/phpA', '/tmp/phpB'],
        'error'    => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE],
        'size'     => [10, 0],
    ]];
    upload::capture($files);

    expect(upload::exists('photos', 0))->toBeTrue()
        ->and(upload::exists('photos', 1))->toBeFalse()
        ->and(upload::extension('photos', 0))->toBe('png')
        ->and(upload::extension('photos', 1))->toBe('gif')
        ->and(upload::tmp_name('photos', '', 1))->toBe('/tmp/phpB');
});

it('refuses to move an upload to a name on the filter list', function () {
    $files = ['avatar' => upload_entry()];
    upload::capture($files);

    // Refused before the filesystem is touched, so no real upload is needed to prove it
    expect(upload::move('avatar', '/tmp/evil.php'))->toBeFalse()
        ->and(upload::move('avatar', '/tmp/evil.PHP'))->toBeFalse()
        ->and(upload::move('avatar', '/tmp/hook.sh'))->toBeFalse();
});

it('refuses to move when there is no upload under that name', function () {
    expect(upload::move('avatar', '/tmp/fine.jpg'))->toBeFalse();
});

it('takes a value put in directly, for the base64 upload fields', function () {
    upload::set('scan', ['data:image/png;base64,AAAA']);

    expect(upload::all())->toHaveKey('scan')
        ->and(upload::all()['scan'])->toBe(['data:image/png;base64,AAAA']);
});

it('forgets the uploads on reset, which is the request boundary', function () {
    $files = ['avatar' => upload_entry()];
    upload::capture($files);

    upload::reset();

    expect(upload::all())->toBe([])
        ->and(upload::exists('avatar'))->toBeFalse();
});
