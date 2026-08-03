<?php
// Kept on purpose: the framework no longer reads <module>_<env>.php suffixed config files, and
// configTest asserts this one is never picked up. Environment differences go through .env.
return [
    'name'   => 'dev',
    'nested' => [
        'b' => 3,
    ],
];
