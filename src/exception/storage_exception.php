<?php

/**
 * Raised by the storage facade and its disks
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\exception;

/**
 * Storage failure.
 *
 * Two kinds of thing come through here, and neither is an ordinary outcome a caller should be
 * branching on:
 *
 *   - **a path a disk must not act on**: absolute, holding a `..` segment, naming a protocol
 *     wrapper. Refused rather than normalised, because a normalised traversal is a successful read
 *     of the wrong file with nothing in the log to say so;
 *   - **a disk that cannot work at all**: no such disk in the configuration, a root that does not
 *     exist and cannot be created, an S3 disk with no bucket or no credentials.
 *
 * A missing file is not an exception -- `get()` answers null and `exists()` answers false -- and
 * neither is a failed write, which answers false. Those are things a caller can do something about
 * at the call site.
 */
class storage_exception extends plato_exception
{
}
