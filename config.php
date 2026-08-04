<?php
// StashBin configuration. Every setting is a literal value: to change anything,
// write the value you want here, and nothing else.
//
// Three settings also accept an environment variable, which is indispensable in
// a container where this file is mounted read-only:
//
//   STASHBIN_DB      path to the SQLite database
//   STASHBIN_AUTH    "0", "false", "off" or "no" disable authentication;
//                    any other value requires it
//   STASHBIN_LOCALE  fallback language
//
// The variable wins over the file when it is set and non-empty. Absent, it
// changes nothing: this file decides.
return [
    // Path to the SQLite database (the directory must be writable by PHP).
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

    // Maximum length of the title a creator may give a secret (in characters).
    // That title is the only part of a secret the server can read: it is there
    // to tell entries apart in one's own list, not to hold anything.
    'title_max' => 120,

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
