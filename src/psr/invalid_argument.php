<?php

/**
 * PSR-16 InvalidArgumentException, thrown by the cache adapter for an unusable key
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\psr;

use InvalidArgumentException as base;
use Psr\SimpleCache\InvalidArgumentException as contract;

/**
 * What plato\psr\cache throws for a key PSR-16 does not allow.
 *
 * It lives here rather than under src/exception/ with the rest of the framework's exceptions, and
 * deliberately so: it has to implement an interface from psr/simple-cache, which is a suggested
 * package and not a required one. In src/exception/ it would be a class that cannot be loaded
 * unless an optional dependency happens to be installed, sitting in the middle of the tree every
 * other exception extends.
 *
 * It also does not extend plato_exception, whose constructor takes template arguments and a code out
 * of config/exception.php. A caller catching this one is a library that knows PSR-16 and nothing
 * about PlatoPHP, so it gets a plain message and the standard SPL parent.
 */
class invalid_argument extends base implements contract
{
}
