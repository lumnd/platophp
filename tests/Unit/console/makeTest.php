<?php

/**
 * plato\console\make controller stubs at the global namespace boundary.
 */

use plato\console\make;
use plato\plato;

it('omits the namespace declaration when controllers live in the global namespace', function () {
    $previous = plato::$config['controller_namespace'] ?? null;
    $method   = (new ReflectionClass(make::class))->getMethod('_controller_stub');

    try
    {
        plato::$config['controller_namespace'] = '';
        $stub = $method->invoke(null, 'health');

        expect($stub)->toStartWith("<?php\n\nuse plato\\http\\resp;")
            ->not->toContain('namespace ;');
    }
    finally
    {
        plato::$config['controller_namespace'] = $previous;
    }
});
