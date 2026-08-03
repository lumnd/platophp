<?php
// Resident socket server configuration. Listeners are declared side by side under `servers`,
// `default` decides which one facade shortcuts use; server::driver() returns any named listener
// independently.
//
// This package ships no driver: an event loop, a process manager and a protocol codec come from an
// adapter package, and the framework only owns what happens between the message and the controller.
// Install one and name it here:
//
//   composer require lumnd/plato-workerman
//
// `driver` takes either a short name registered with server::register_driver() or a class name
// implementing plato\server\driver, so an adapter needs no bootstrap code of its own.
//
// Keys the framework reads are `driver` and `dispatch`. Everything else belongs to the adapter and
// is passed through to its configure() untouched -- which is why the process, tls and heartbeat
// settings below are described in terms of what they are for rather than what a given adapter calls
// them; check the adapter's readme for the ones it honours.
return [
    'default' => $_ENV['SERVER_DEFAULT'] ?? 'default',

    'servers' => [
        'default' => [
            'driver' => $_ENV['SERVER_DRIVER'] ?? 'workerman',

            // Where to listen, and in which protocol. The scheme is the adapter's vocabulary, not
            // the framework's: websocket:// is the default because it is what most listeners speak,
            // but a driver is free to serve tcp://, text://, or a protocol class of its own -- see
            // plato\server\driver. The one rule is that whatever is named here has to deliver whole
            // application messages, because that is what the dispatcher is handed; a raw byte stream
            // needs the driver to frame it first.
            //
            // Bind to 127.0.0.1 and terminate tls at the reverse proxy unless the server is meant to
            // face the internet on its own
            'listen' => $_ENV['SERVER_LISTEN'] ?? 'websocket://127.0.0.1:8282',

            // Name the process carries in `ps`, so a stray worker can be found
            'name' => $_ENV['SERVER_NAME'] ?? 'platophp-server',

            // Worker processes. One process serves one message at a time, so this is how many
            // messages the server handles at once. A blocking query in an action holds up every
            // connection of its own worker
            'processes' => (int) ($_ENV['SERVER_PROCESSES'] ?? 4),

            // Drop privileges after binding; '' keeps the user the master was started as
            'user'  => $_ENV['SERVER_USER'] ?? '',
            'group' => $_ENV['SERVER_GROUP'] ?? '',

            // Where the adapter keeps its master pid and its own log. Under data_path() by default
            // so a host application needs no extra writable directory
            'pid_file' => $_ENV['SERVER_PID_FILE'] ?? '',
            'log_file' => $_ENV['SERVER_LOG_FILE'] ?? '',

            // Let several master processes share one port, so a restart does not drop the listener.
            // Needs SO_REUSEPORT from the platform
            'reuse_port' => (bool) ($_ENV['SERVER_REUSE_PORT'] ?? false),

            // Terminating tls in the worker instead of at a proxy. Paths, never key material
            'ssl' => [
                'local_cert'        => $_ENV['SERVER_SSL_CERT'] ?? '',
                'local_pk'          => $_ENV['SERVER_SSL_KEY'] ?? '',
                'verify_peer'       => false,
                'allow_self_signed' => false,
            ],

            // Idle connections. `interval` is how often the adapter looks, `timeout` is how long a
            // connection may say nothing before it is closed. Set the timeout above whatever keeps
            // the path warm -- a proxy's own idle timeout, or the client's ping interval
            'heartbeat' => [
                'interval' => (int) ($_ENV['SERVER_HEARTBEAT_INTERVAL'] ?? 30),
                'timeout'  => (int) ($_ENV['SERVER_HEARTBEAT_TIMEOUT'] ?? 120),
            ],

            // What plato\server\dispatcher reads: the message boundary, not the socket
            'dispatch' => [
                // Keys the route and the correlation id are carried under. A client that has
                // several requests in flight on one socket sends `seq` and gets it back on an error
                // reply; an action reads it through dispatcher::seq() to put it on its own answer
                'ct_key'  => 'ct',
                'ac_key'  => 'ac',
                'seq_key' => 'seq',

                // Largest payload to accept, in bytes; 0 accepts anything. This is a message the
                // process has already read into memory, so it is a memory limit as much as a
                // protocol one -- the adapter has its own limit for what it reads off the socket
                'max_payload' => (int) ($_ENV['SERVER_MAX_PAYLOAD'] ?? 65536),

                // Send back what the action printed. Off makes every answer explicit: the action
                // pushes through dispatcher::current()->send() and nothing is inferred from output
                'reply_echo' => true,

                // Answer a failed message with an error reply, in the shape resp::response() uses.
                // Off leaves the client with no answer at all, which only suits a fire and forget
                // protocol
                'error_reply' => true,

                // Put the exception message in that error reply. Development only: the messages
                // carry class names, paths and sql
                'error_detail' => (bool) ($_ENV['SERVER_ERROR_DETAIL'] ?? false),
            ],
        ],
    ],
];
