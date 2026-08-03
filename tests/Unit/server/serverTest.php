<?php

/**
 * server\server: resolving the configured driver, and saying which package is missing when there is none.
 */

use plato\exception\server_exception;
use plato\server\connection;
use plato\server\dispatcher;
use plato\server\driver;
use plato\server\server;

/**
 * Minimal driver, enough for the facade to pass calls to.
 */
class ws_fake_listener implements driver
{
    /** @var array<string, mixed> */
    public $config = [];

    /** @var bool */
    public $running = false;

    /** @var array<int, array<int, mixed>> */
    public $sent = [];

    /** @var array<int, array<int, mixed>> */
    public $closed = [];

    public function configure(array $config): void
    {
        $this->config = $config;
    }

    public function start(): void
    {
        $this->running = true;
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function send(string $id, string $payload): bool
    {
        $this->sent[] = [$id, $payload];

        return true;
    }

    public function close(string $id, int $code = 1000, string $reason = ''): bool
    {
        $this->closed[] = [$id, $code, $reason];

        return true;
    }

    public function connection(string $id)
    {
        return new connection($id, $this);
    }

    public function connections(): array
    {
        return ['c1', 'c2'];
    }
}

/**
 * Settings the facade is handed, so no test reads config/server.php.
 *
 * @param array<string, mixed> $server Overrides for the single declared server
 * @return array<string, mixed>
 */
function server_test_config(array $server = []): array
{
    return [
        'default' => 'default',
        'servers' => [
            'default' => array_merge([
                'driver' => ws_fake_listener::class,
                'listen' => 'websocket://127.0.0.1:0',
            ], $server),
        ],
    ];
}

it('resolves a driver named by its class and hands it the whole server config', function () {
    server::configure(server_test_config(['processes' => 2]));

    $driver = server::driver();

    expect($driver)->toBeInstanceOf(ws_fake_listener::class);
    expect($driver->config['processes'])->toBe(2);
    expect($driver->config['listen'])->toBe('websocket://127.0.0.1:0');
});

it('resolves a driver registered under a short name', function () {
    server::register_driver('fake', ws_fake_listener::class);
    server::configure(server_test_config(['driver' => 'fake']));

    expect(server::driver())->toBeInstanceOf(ws_fake_listener::class);
});

it('keeps two listeners using the same adapter independent', function () {
    server::configure([
        'default' => 'first',
        'servers' => [
            'first'  => ['driver' => ws_fake_listener::class, 'listen' => 'ws://first'],
            'second' => ['driver' => ws_fake_listener::class, 'listen' => 'ws://second'],
        ],
    ]);

    $first  = server::driver('first');
    $second = server::driver('second');

    expect($first)->not->toBe($second)
        ->and($first->config['listen'])->toBe('ws://first')
        ->and($second->config['listen'])->toBe('ws://second')
        ->and(server::driver())->toBe($first);
});

it('refuses to register something that is not a driver', function () {
    server::register_driver('broken', stdClass::class);
})->throws(server_exception::class);

it('names the adapter package to install when no driver is there', function () {
    server::configure(server_test_config(['driver' => 'workerman']));

    try
    {
        server::driver();
        $message = '';
    }
    catch ( server_exception $e )
    {
        $message = $e->getMessage();
    }

    expect($message)->toContain('lumnd/plato-workerman');
    expect($message)->toContain('ships no driver');
});

it('reports a server that is not configured', function () {
    server::configure(server_test_config());
    server::driver('nosuch');
})->throws(server_exception::class);

it('hands the dispatch settings to the dispatcher when a server is selected', function () {
    server::configure(server_test_config(['dispatch' => ['ct_key' => 'module', 'max_payload' => 16]]));
    server::start('default');

    expect(dispatcher::config('ct_key'))->toBe('module');
    expect(dispatcher::config('max_payload'))->toBe(16);
    // Keys the server did not mention come from the defaults
    expect(dispatcher::config('ac_key'))->toBe('ac');
});

it('reads the settings without requiring the driver to exist', function () {
    server::configure(server_test_config(['driver' => 'workerman']));

    expect(server::settings()['listen'])->toBe('websocket://127.0.0.1:0');
});

it('passes the loop and the per connection calls to the driver', function () {
    server::configure(server_test_config());

    server::start();
    $driver = server::driver();
    expect($driver->running)->toBeTrue();

    server::stop();
    expect($driver->running)->toBeFalse();

    expect(server::send('c1', ['code' => 0]))->toBeTrue();
    expect($driver->sent[0])->toBe(['c1', '{"code":0}']);

    expect(server::close('c1', 1011, 'internal'))->toBeTrue();
    expect($driver->closed[0])->toBe(['c1', 1011, 'internal']);

    expect(server::connections())->toBe(['c1', 'c2']);
    expect(server::connection('c7')->id())->toBe('c7');
});
