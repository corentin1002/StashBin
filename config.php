<?php
// StashBin configuration. Every setting is a literal value: to change anything,
// write the value you want here, and nothing else.
//
// Some settings also accept an environment variable, which is indispensable in
// a container where this file is mounted read-only:
//
//   STASHBIN_DB      path to the SQLite database
//   STASHBIN_AUTH    "0", "false", "off" or "no" disable authentication;
//                    any other value requires it
//   STASHBIN_LOCALE  fallback language
//   STASHBIN_TRUST_PROXY   same spelling as STASHBIN_AUTH
//
//   STASHBIN_DB_DRIVER, _HOST, _PORT, _NAME, _USER, _PASS, _SOCKET, _CHARSET
//                    the "db" setting below, one connection detail per
//                    variable; naming any of them settles its shape
//
// The variable wins over the file when it is set and non-empty. Absent, it
// changes nothing: this file decides.
return [
    // Where the secrets are stored. A path means SQLite and that file (the
    // directory must be writable by PHP): no server to run, and nothing else
    // to decide.
    //
    // An array means a database engine of your own choosing. "driver" is a
    // file name in src/db/ — "sqlite", or "mysql", which "mariadb" is another
    // name for. StashBin creates its tables on first use, but never the
    // database itself: create it, and an account allowed to write to it,
    // before starting.
    //
    //   'db' => [
    //       'driver' => 'mariadb',
    //       'host'   => '127.0.0.1',   // or 'socket' => '/run/mysqld/mysqld.sock'
    //       'port'   => 3306,
    //       'name'   => 'stashbin',
    //       'user'   => 'stashbin',
    //       'pass'   => '',
    //   ],
    //
    // Nothing else changes: what is stored stays a payload the server cannot
    // read, and the key still never leaves the browser.
    'db' => __DIR__ . '/data/stashbin.sqlite',

    // Authentication required to create and delete a secret. This is how
    // StashBin normally works.
    //
    // false opens creation to any visitor: only do this on an instance whose
    // access is already restricted some other way (internal network,
    // authenticating proxy). Everything else is unchanged — encryption still
    // happens in the browser and the server still reads nothing.
    'auth' => true,

    // Maximum size of the encrypted payload accepted (in bytes).
    'max_size' => 2 * 1024 * 1024,

    // Whether a proxy standing in front of StashBin may be believed when it
    // says, through X-Forwarded-For and X-Forwarded-Proto, who the reader is
    // and whether they came over HTTPS.
    //
    // Leave this false unless a proxy you control is the only way in: those
    // headers are written by whoever sends the request, so a reader could
    // otherwise choose what the access log says about them. Set it to true
    // behind a TLS-terminating proxy — without it the session cookie is not
    // marked Secure, and every access is logged as coming from the proxy.
    'trust_proxy' => false,

    // How many accesses are recorded per secret. Anyone holding a link can
    // cause a row to be written, so the log is bounded: past this many, the
    // earliest are kept and later ones are dropped.
    'access_log_max' => 100,

    // Lifetimes on offer (key => seconds, null = unlimited).
    'expirations' => [
        '1h'    => 3600,
        '1d'    => 86400,
        '1w'    => 604800,
        '1m'    => 2592000,
        'never' => null,
    ],
    'default_expiration' => '1w',

    // Language served when the browser requests none that is available —
    // English, for want of better, being more widely read than French. The
    // languages on offer are the files in src/lang/: adding one means dropping
    // a file there, without touching the code.
    //
    // Not to be confused with src/lang/fr.php, which remains the reference
    // file: it is the one an incomplete translation borrows its missing
    // strings from, whatever value is set here.
    'default_locale' => 'en',

    // Name of the session cookie.
    'session_name' => 'stashbin',
];
