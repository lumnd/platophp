<?php

/**
 * PHPStan bootstrap.
 *
 * The fixture application's .env is the only env file the repository has, and some constants are
 * resolved from $_ENV at analysis time. Paths, the debug switch and the environment name all come
 * from plato::registry() -- no global constants are defined here.
 */

$testing_env = @parse_ini_file(__DIR__ . '/tests/Fixtures/app/.env.testing', false, INI_SCANNER_RAW);
if ( is_array($testing_env) )
{
    $_ENV = array_merge($_ENV, $testing_env);
}
