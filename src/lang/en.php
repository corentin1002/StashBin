<?php
// English. The keys are those of fr.php, which is the reference: a key missing
// from here is served in French rather than shown as an identifier.
return [
    // --- Creation page ------------------------------------------------------
    'create.page_title'          => 'StashBin — New secret',
    'create.logged_in'           => 'Signed in as {user} — {logout}',
    'create.logout'              => 'sign out',
    'create.no_auth_notice'      => 'Authentication is disabled: anyone who reaches this page can create a secret.',
    'create.secret_label'        => 'Secret to share',
    'create.secret_placeholder'  => 'Paste the text to encrypt here…',
    'create.expire_label'        => 'Expiry',
    'create.burn_label'          => 'Destroy after first read',
    'create.password_label'      => 'Password (optional)',
    'create.password_placeholder' => 'Extra protection',
    'create.submit'              => 'Encrypt and create link',
    'create.done_title'          => 'Secret created ✔',
    'create.done_note'           => 'The content was encrypted in your browser: the server cannot read it.',
    'create.share_link'          => 'Sharing link',
    'create.delete_link'         => 'Deletion link',
    'create.delete_link_auth'    => 'Deletion link (requires being signed in)',
    'create.copy'                => 'Copy',
    'create.another'             => 'Create another secret',

    // --- Lifetimes ----------------------------------------------------------
    'expire.1h'    => '1 hour',
    'expire.1d'    => '1 day',
    'expire.1w'    => '1 week',
    'expire.1m'    => '1 month',
    'expire.never' => 'Never',

    // --- Reading page -------------------------------------------------------
    'view.page_title'        => 'StashBin — Shared secret',
    'view.loading'           => 'Loading…',
    'view.burn_warning'      => '⚠️ This secret will be {emphasis}. Only continue if you are ready to read it now.',
    'view.burn_emphasis'     => 'destroyed as soon as it is read',
    'view.reveal'            => 'Show the secret',
    'view.password_required' => 'This secret is protected by a password.',
    'view.password_label'    => 'Password',
    'view.decrypt'           => 'Decrypt',
    'view.burned_notice'     => '⚠️ This secret has just been destroyed: this page is the only copy. It will not be available again.',
    'view.copy_content'      => 'Copy the content',

    // --- Sign-in page -------------------------------------------------------
    'login.page_title'      => 'StashBin — Sign in',
    'login.intro'           => 'Sign in to create a secret.',
    'login.username'        => 'Username',
    'login.password'        => 'Password',
    'login.submit'          => 'Sign in',
    'login.expired'         => 'Session expired, try again.',
    'login.bad_credentials' => 'Incorrect credentials.',

    // --- JavaScript strings -------------------------------------------------
    'js.encrypting'           => 'Encrypting…',
    'js.decrypting'           => 'Decrypting…',
    'js.copied'               => 'Copied ✔',
    'js.create_failed'        => 'Creation failed: {error}',
    'js.bad_password'         => 'Incorrect password.',
    'js.burn_already_fetched' => '⚠️ This read-once secret has already been fetched: this page is your only chance to decrypt it.',
    'js.missing_key'          => 'Invalid link: the identifier or the decryption key (after the #) is missing.',
    'js.not_found'            => 'This secret does not exist any more (expired, deleted, or already read).',
    'js.error'                => 'Error: {error}',
    'js.server_error'         => 'server error',

    // --- API messages -------------------------------------------------------
    'error.invalid_id'         => 'invalid identifier',
    'error.not_found'          => 'not found',
    'error.bad_delete_token'   => 'invalid deletion token',
    'error.unauthorized'       => 'authentication required',
    'error.bad_csrf'           => 'invalid CSRF token',
    'error.too_large'          => 'content too large',
    'error.bad_request'        => 'invalid request',
    'error.incomplete_payload' => 'incomplete payload',
    'error.unknown_expiration' => 'unknown lifetime',
    'error.method_not_allowed' => 'method not allowed',
];
