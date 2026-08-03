<?php
// Config module used by configTest only; the framework ships no file of this name
return [
    'name'   => 'base',
    'debug'  => false,
    'zero'   => 0,
    'blank'  => '',
    'nested' => [
        'a' => 1,
        'b' => 2,
    ],
    'path'  => '@root@/tmp',
    'paths' => ['@root@/a', '@root@/b'],
];
