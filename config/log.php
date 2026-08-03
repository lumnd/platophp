<?php
/**
 * Log levels are class constants on plato\log:
 *   log::NONE      0    logging disabled
 *   log::ALL       99   log everything
 *   log::TRACE     100  log4j alias of DEBUG
 *   log::DEBUG     100  detailed debug information
 *   log::INFO      200  relevant events, such as logins or SQL statements
 *   log::NOTICE    250  normal but significant events
 *   log::WARNING   300  exceptional occurrences that are not errors
 *   log::ERROR     400  runtime errors that do not require immediate action
 *   log::CRITICAL  500  critical conditions, such as an unavailable component
 *   log::FATAL     500  log4j alias of CRITICAL
 *   log::ALERT     550  action must be taken immediately
 *   log::EMERGENCY 600  system is unusable
 */

use plato\log;

return [
    // Where entries go.
    //   'file'    one file per level under the log path, the default
    //   'stderr'  every level to php://stderr, for a container that collects the stream
    //   'stdout'  every level to php://stdout
    // A stream target needs no writable log directory, so initialize() stops asking for one.
    'log_type'               => 'file',
    'log_file'               => '',
    // Checked by log::initialize(), but save() writes under plato::log_path() either way
    'log_path'               => '',
    'log_folders_permission' => '0777',
    'log_files_permission'   => '0666',
    // NONE, ALL, a single level (that level and everything more severe), or an explicit array
    //'log_threshold'          => [log::ERROR, log::WARNING, log::NOTICE, log::DEBUG, log::INFO],
    'log_threshold'          => log::ALL,
    'log_date_format'        => 'Y-m-d H:i:s',
    // Placeholders: %datetime% %level_name% %message% %context%. %context% is the json of whatever
    // context the message's {placeholders} did not consume, and carries its own leading space so a
    // line without any does not end in a separator.
    //
    // The literal string 'json' is not a template but a sentinel: one json object per line, with
    // the context as fields beside ts / level / msg. That is the form to ship to a collector.
    'log_output'             => "%datetime% [%level_name%] --> %message%%context%\n",
    // Request methods whose submitted data gets logged
    'log_request_methods'    => [
        //'*',
        //'GET', 'POST', 'PUT', 'DELETE',
        'POST',
    ],
    // Request URLs whose submitted data gets logged
    'log_request_uris'       => [
        //'ct=index&ac=index',
        '*',
    ],
    // MySQL slow query threshold, in milliseconds
    'slow_query'             => 1000,
];
