<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

security_headers();
vary_language();

$method = $_SERVER['REQUEST_METHOD'];

// --- Reading (public) -------------------------------------------------------
if ($method === 'GET') {
    $id = $_GET['id'] ?? '';
    if (!preg_match('/^[A-Za-z0-9_-]{8,32}$/', $id)) {
        json_error(400, 'invalid_id');
    }

    $stmt = db()->prepare('SELECT * FROM pastes WHERE id = ?');
    $stmt->execute([$id]);
    $paste = $stmt->fetch();

    // An identifier that never existed: nothing to record, and nothing to say
    // beyond the same 404 a spent secret gets.
    if (!$paste) {
        json_error(404, 'not_found');
    }

    // An expired secret is laid to rest on the spot rather than waiting for the
    // next creation to sweep the table.
    if ($paste['expires'] !== null && $paste['expires'] < time() && $paste['gone'] === null) {
        entomb($id, 'expired');
        $paste['gone'] = time();
    }

    if ($paste['gone'] !== null) {
        // A read attempt on a spent secret is worth recording: that is how a
        // creator learns their link was replayed after the fact. The metadata
        // probe counts here, and only here: view.php sends it before anything
        // else, so a reader arriving too late never gets as far as asking for
        // the payload. Calling the deletion link is not a read.
        if (!isset($_GET['delete'])) {
            record_read($id, 'gone');
        }
        json_error(404, 'not_found');
    }

    // Deletion through the creator's token — restricted to authenticated users:
    // a deletion link that leaks is useless on its own. Without authentication,
    // the token becomes the sole credential again.
    if (isset($_GET['delete'])) {
        if (!is_authorized()) {
            header('Location: login.php' . lang_param());
            exit;
        }
        if (!hash_equals($paste['delete_hash'], hash('sha256', (string) $_GET['delete']))) {
            json_error(403, 'bad_delete_token');
        }
        entomb($id, 'deleted');
        json_out(200, ['deleted' => true]);
    }

    // Metadata probe: lets a confirmation be shown before a read-once paste is
    // consumed.
    if (isset($_GET['meta'])) {
        json_out(200, ['burn' => (bool) $paste['burn']]);
    }

    record_read($id, 'served');
    if ($paste['burn']) {
        entomb($id, 'burned');
    }
    json_out(200, [
        'payload' => json_decode($paste['payload'], true),
        'burn' => (bool) $paste['burn'],
    ]);
}

// --- Creation (authenticated, unless configured otherwise) ------------------
if ($method === 'POST') {
    if (!is_authorized()) {
        json_error(401, 'unauthorized');
    }
    // The CSRF check does not depend on authentication: it stops another site
    // from having a visitor's browser create secrets.
    if (!check_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        json_error(403, 'bad_csrf');
    }

    $raw = file_get_contents('php://input', length: config()['max_size'] + 4096);
    if (strlen($raw) > config()['max_size']) {
        json_error(413, 'too_large');
    }
    $body = json_decode($raw, true);
    if (!is_array($body) || !isset($body['payload']) || !is_array($body['payload'])) {
        json_error(400, 'bad_request');
    }

    // The payload is opaque to the server (encrypted client-side); we only
    // check that it has the expected shape.
    $payload = $body['payload'];
    foreach (['v', 'iv', 'salt', 'iter', 'pwd', 'ct'] as $field) {
        if (!array_key_exists($field, $payload)) {
            json_error(400, 'incomplete_payload');
        }
    }

    // Recorded so that the creator's list can exist at all. It is the only
    // thing the server learns about a secret beyond its lifetime: nothing
    // describes the content, not even a label.
    $user = current_user();

    $expirations = config()['expirations'];
    $expireKey = $body['expire'] ?? config()['default_expiration'];
    if (!array_key_exists($expireKey, $expirations)) {
        json_error(400, 'unknown_expiration');
    }
    $expires = $expirations[$expireKey] === null ? null : time() + $expirations[$expireKey];

    purge_expired();

    $id = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
    $deleteToken = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');

    $stmt = db()->prepare(
        'INSERT INTO pastes (id, payload, burn, delete_hash, created, expires, owner_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $id,
        json_encode($payload, JSON_UNESCAPED_SLASHES),
        empty($body['burn']) ? 0 : 1,
        hash('sha256', $deleteToken),
        time(),
        $expires,
        $user['id'] ?? null,
    ]);

    json_out(201, ['id' => $id, 'delete_token' => $deleteToken]);
}

json_error(405, 'method_not_allowed');
