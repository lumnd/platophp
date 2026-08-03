<?php
// Application config of the test app: only the values that differ from the framework defaults
return [
    // Most tests are CLI and do not exercise browser CSRF. The dedicated security tests install
    // an isolated key and binding callback explicitly.
    'request' => [
        'csrf_token_on' => false,
    ],

    // Named headers rather than the framework default of echoing back whatever was asked for, so
    // the preflight tests can observe both a request that stays inside the list and one that does
    // not
    'security' => [
        'cors' => [
            'allow_headers' => ['Content-Type', 'X-Test-Identity'],
        ],
    ],

    'route' => [
        // Route that accepts nothing but an encrypted envelope, to exercise the enforcement in
        // route::crypto_required()
        'crypto_required' => ['secure:any'],
    ],

    // Middleware, to exercise plato\http\pipeline through a real request. `marker` wraps every
    // route of ctl_middleware; `refuse` answers on its own, so the action behind it is never run
    'middleware' => [
        'middleware:*'       => ['middleware\marker'],
        'middleware:refused' => ['middleware\refuse'],
    ],

    // Fixture templates use the alternate delimiters to verify host-level Smarty configuration.
    'template' => [
        'left_delimiter'  => '<{',
        'right_delimiter' => '}>',
    ],

    // Encrypted request envelope. web / ios / android each get their own key -- a client side
    // key is always extractable, so splitting by platform limits the blast radius rather than
    // keeping anything secret.
    'crypto' => [
        'clients' => [
            'web' => $_ENV['CRYPT_KEY'] ?? '',
        ],
        // Replay protection needs a working nonce store (the cache). It is off here so the cases
        // that do not need Redis can run; ts / nonce validation itself is covered on its own in
        // tests/Unit/http/envelopeTest.php
        'replay_window' => 0,
    ],
];
