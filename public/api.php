<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

security_headers();
vary_language();

$method = $_SERVER['REQUEST_METHOD'];

// --- Lecture (publique) -----------------------------------------------------
if ($method === 'GET') {
    $id = $_GET['id'] ?? '';
    if (!preg_match('/^[A-Za-z0-9_-]{8,32}$/', $id)) {
        json_error(400, 'invalid_id');
    }

    $stmt = db()->prepare('SELECT * FROM pastes WHERE id = ?');
    $stmt->execute([$id]);
    $paste = $stmt->fetch();

    if (!$paste || ($paste['expires'] !== null && $paste['expires'] < time())) {
        if ($paste) {
            db()->prepare('DELETE FROM pastes WHERE id = ?')->execute([$id]);
        }
        json_error(404, 'not_found');
    }

    // Suppression via le jeton du créateur — réservée aux utilisateurs
    // authentifiés : un lien de suppression qui fuite est inutilisable seul.
    // Sans authentification, le jeton redevient le seul justificatif.
    if (isset($_GET['delete'])) {
        if (!is_authorized()) {
            header('Location: login.php' . lang_param());
            exit;
        }
        if (!hash_equals($paste['delete_hash'], hash('sha256', (string) $_GET['delete']))) {
            json_error(403, 'bad_delete_token');
        }
        db()->prepare('DELETE FROM pastes WHERE id = ?')->execute([$id]);
        json_out(200, ['deleted' => true]);
    }

    // Sonde de métadonnées : permet d'afficher une confirmation avant de
    // consommer un paste à lecture unique.
    if (isset($_GET['meta'])) {
        json_out(200, ['burn' => (bool) $paste['burn']]);
    }

    if ($paste['burn']) {
        db()->prepare('DELETE FROM pastes WHERE id = ?')->execute([$id]);
    }
    json_out(200, [
        'payload' => json_decode($paste['payload'], true),
        'burn' => (bool) $paste['burn'],
    ]);
}

// --- Création (authentifiée, sauf configuration contraire) ------------------
if ($method === 'POST') {
    if (!is_authorized()) {
        json_error(401, 'unauthorized');
    }
    // Le contrôle CSRF ne dépend pas de l'authentification : il empêche un
    // autre site de faire créer des secrets par le navigateur d'un visiteur.
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

    // Le payload est opaque pour le serveur (chiffré côté client) ; on vérifie
    // seulement qu'il a la forme attendue.
    $payload = $body['payload'];
    foreach (['v', 'iv', 'salt', 'iter', 'pwd', 'ct'] as $field) {
        if (!array_key_exists($field, $payload)) {
            json_error(400, 'incomplete_payload');
        }
    }

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
        'INSERT INTO pastes (id, payload, burn, delete_hash, created, expires) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $id,
        json_encode($payload, JSON_UNESCAPED_SLASHES),
        empty($body['burn']) ? 0 : 1,
        hash('sha256', $deleteToken),
        time(),
        $expires,
    ]);

    json_out(201, ['id' => $id, 'delete_token' => $deleteToken]);
}

json_error(405, 'method_not_allowed');
