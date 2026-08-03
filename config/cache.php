<?php
// Cache settings, read by plato\cache\cache and the four stores behind it.
return [
    'enable'     => true,
    'prefix'     => $_ENV['CACHE_PREFIX'] ?? 'platophp',
    // Store: redis | file | memcached | memory -- the four plato\cache classes implementing store.
    // `memory` is an array in the current process: no server, nothing shared between workers and
    // nothing surviving the request, which is what a test suite or a one-shot script wants.
    'cache_type' => $_ENV['CACHE_STORE'] ?? 'redis',
    'cache_time' => 7200,
    // The single file the `file` store keeps every entry in, created under data/cache/
    'cache_name' => $_ENV['CACHE_NAME'] ?? 'platophp_data',
    // ext/memcached only; the older ext/memcache is not supported
    'memcached'  => [
        'servers' => [
            [
                'host'   => $_ENV['MEMCACHE_HOST'] ?? '127.0.0.1',
                'port'   => $_ENV['MEMCACHE_PORT'] ?? 11211,
                'weight' => 1,
            ],
        ],
        // Milliseconds, which is what ext/memcached takes -- every other timeout here is seconds
        'connect_timeout' => 1000,
        'compression'     => true,
    ],
    // One server, short connections: persistent ones are collected at random on PHP 7+. A
    // `cluster` key is read as well, see plato\cache\redis::_connect()
    'redis' => [
        'server' => [
            'host'       => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
            'port'       => $_ENV['REDIS_PORT'] ?? 6379,
            'pass'       => $_ENV['REDIS_PASSWORD'] ?? '',
            'keep-alive' => false,
            'timeout'    => 5,
            'dbindex'    => 1
        ]
    ],
    // Nothing in the framework reads this yet; kept until a consumer decides its final shape
    'mqtt' => [
        'server' => [
            'host'       => $_ENV['MQTT_HOST'] ?? '',
            'port'       => $_ENV['MQTT_PORT'] ?? 1883,
            'user'       => $_ENV['MQTT_USER'] ?? '',
            'pass'       => $_ENV['MQTT_PASS'] ?? '',
            'keep-alive' => 60,
            'tls-crt' => [
                //'ca_file'   => '/path/to/ca.crt',
                //'cert_file' => '/path/to/client.crt',
                //'key_file'  => '/path/to/client.key',
                //'password'  => '/path/to/client.key',
            ],
            'tls-psk' => [
                //'psk' => '',
                //'identity' => '',
                //'ciphers' => null
            ]
        ]
    ],
];
