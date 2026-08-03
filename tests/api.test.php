<?php
// Règles métier de l'API, éprouvées par HTTP contre l'application réelle.
// Les tests s'exécutent dans le conteneur : ils peuvent donc confronter la
// réponse HTTP à ce qui est réellement écrit en base.
declare(strict_types=1);

require __DIR__ . '/lib.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$base = getenv('STASHBIN_URL') ?: 'http://127.0.0.1';
$user = getenv('STASHBIN_USER') ?: 'testeur';
$pass = getenv('STASHBIN_PASS') ?: 'motdepassetest';

$http = new Http($base);
$token = $http->login($user, $pass);

// ---------------------------------------------------------------------------
group('Création — authentification et CSRF');

test('un visiteur anonyme ne peut pas créer de secret (401)', function () use ($base) {
    $anon = new Http($base);
    $res = $anon->post('/api.php', json_encode(['payload' => []]), ['Content-Type' => 'application/json']);
    assert_eq(401, $res->status, 'création refusée sans session');
    assert_eq('authentification requise', $res->json()['error'], 'message explicite');
});

test('une session sans jeton CSRF est refusée (403)', function () use ($http) {
    $res = $http->post('/api.php', json_encode(['payload' => []]), ['Content-Type' => 'application/json']);
    assert_eq(403, $res->status, 'CSRF exigé même authentifié');
});

test('un jeton CSRF erroné est refusé (403)', function () use ($http) {
    $res = $http->createSecret([], str_repeat('0', 64));
    assert_eq(403, $res->status, 'jeton CSRF invalide rejeté');
});

test('une création valide renvoie 201, un identifiant et un jeton de suppression', function () use ($http, $token) {
    $res = $http->createSecret([], $token);
    assert_eq(201, $res->status, 'création acceptée');
    $d = $res->json();
    assert_matches('/^[A-Za-z0-9_-]{16}$/', $d['id'], 'identifiant en base64url de 16 caractères');
    assert_matches('/^[A-Za-z0-9_-]{24}$/', $d['delete_token'], 'jeton de suppression de 24 caractères');
});

test('deux créations successives produisent des identifiants distincts', function () use ($http, $token) {
    $a = $http->createSecret([], $token)->json()['id'];
    $b = $http->createSecret([], $token)->json()['id'];
    assert_true($a !== $b, 'identifiants non répétés');
});

// ---------------------------------------------------------------------------
group('Création — validation du payload');

foreach (['v', 'iv', 'salt', 'iter', 'pwd', 'ct'] as $field) {
    test("un payload sans « $field » est refusé (400)", function () use ($http, $token, $field) {
        $payload = ['v' => 1, 'iv' => 'A', 'salt' => 'B', 'iter' => 310000, 'pwd' => 0, 'ct' => 'C'];
        unset($payload[$field]);
        $res = $http->createSecret(['payload' => $payload], $token);
        assert_eq(400, $res->status, "champ « $field » obligatoire");
        assert_eq('payload incomplet', $res->json()['error'], 'message explicite');
    });
}

test('un corps qui n\'est pas du JSON est refusé (400)', function () use ($http, $token) {
    $res = $http->post('/api.php', 'ceci nest pas du json', [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $token,
    ]);
    assert_eq(400, $res->status, 'JSON invalide rejeté');
});

test('un corps JSON sans clé « payload » est refusé (400)', function () use ($http, $token) {
    $res = $http->createSecret(['raw' => ['expire' => '1h']], $token);
    assert_eq(400, $res->status, 'payload obligatoire');
});

test('un « payload » qui n\'est pas un objet est refusé (400)', function () use ($http, $token) {
    $res = $http->createSecret(['payload' => 'chaine'], $token);
    assert_eq(400, $res->status, 'payload scalaire rejeté');
});

test('un contenu dépassant max_size est refusé (413)', function () use ($http, $token) {
    $max = config()['max_size'];
    $res = $http->createSecret([
        'payload' => ['v' => 1, 'iv' => 'A', 'salt' => 'B', 'iter' => 310000, 'pwd' => 0,
                      'ct' => str_repeat('Q', $max + 5000)],
    ], $token);
    assert_eq(413, $res->status, 'limite de taille appliquée');
});

test('un contenu volumineux mais sous la limite est accepté', function () use ($http, $token) {
    $res = $http->createSecret([
        'payload' => ['v' => 1, 'iv' => 'A', 'salt' => 'B', 'iter' => 310000, 'pwd' => 0,
                      'ct' => str_repeat('Q', 512 * 1024)],
    ], $token);
    assert_eq(201, $res->status, '512 Kio acceptés');
});

// ---------------------------------------------------------------------------
group('Création — durées de vie');

test('une durée de vie inconnue est refusée (400)', function () use ($http, $token) {
    $res = $http->createSecret(['expire' => '42siecles'], $token);
    assert_eq(400, $res->status, 'clé de durée inexistante rejetée');
    assert_eq('durée de vie inconnue', $res->json()['error'], 'message explicite');
});

foreach (['1h' => 3600, '1d' => 86400, '1w' => 604800, '1m' => 2592000] as $key => $seconds) {
    test("« $key » fixe une expiration à +$seconds secondes", function () use ($http, $token, $key, $seconds) {
        $before = time();
        $id = $http->createSecret(['expire' => $key], $token)->json()['id'];
        $stored = db()->query('SELECT expires FROM pastes WHERE id = ' . db()->quote($id))->fetchColumn();
        assert_true(is_int($stored), 'expiration enregistrée comme entier');
        // Une seconde de tolérance : la requête peut chevaucher un changement d'horloge.
        assert_true(
            abs($stored - ($before + $seconds)) <= 1,
            "expiration attendue vers " . ($before + $seconds) . ", obtenue $stored"
        );
    });
}

test('« never » n\'enregistre aucune expiration', function () use ($http, $token) {
    $id = $http->createSecret(['expire' => 'never'], $token)->json()['id'];
    $stored = db()->query('SELECT expires FROM pastes WHERE id = ' . db()->quote($id))->fetchColumn();
    assert_eq(null, $stored, 'colonne expires nulle');
});

test('sans précision, la durée par défaut de la configuration s\'applique', function () use ($http, $token) {
    $before = time();
    $id = $http->createSecret([], $token)->json()['id'];
    $stored = db()->query('SELECT expires FROM pastes WHERE id = ' . db()->quote($id))->fetchColumn();
    $expected = config()['expirations'][config()['default_expiration']];
    assert_true(abs($stored - ($before + $expected)) <= 1, 'durée par défaut appliquée');
});

// ---------------------------------------------------------------------------
group('Lecture');

test('un identifiant mal formé est refusé (400)', function () use ($http) {
    // Le filtre est /^[A-Za-z0-9_-]{8,32}$/ : trop court, trop long, ou
    // contenant un caractère hors de l'alphabet base64url.
    foreach (['!!', 'court', str_repeat('a', 33), '../../etc/passwd', 'ab cd efg', ''] as $bad) {
        $res = $http->get('/api.php?id=' . urlencode($bad));
        assert_eq(400, $res->status, "identifiant « $bad » rejeté");
    }
});

test('un identifiant bien formé mais inconnu renvoie 404, pas 400', function () use ($http) {
    // La distinction compte : un 400 sur une chaîne valide révélerait que le
    // filtre de format et l'existence en base sont confondus.
    $res = $http->get('/api.php?id=' . str_repeat('a', 16));
    assert_eq(404, $res->status, 'format correct, secret inexistant');
});

test('un identifiant inexistant renvoie 404', function () use ($http) {
    $res = $http->get('/api.php?id=' . str_repeat('z', 16));
    assert_eq(404, $res->status, 'secret introuvable');
    assert_eq('introuvable', $res->json()['error'], 'message explicite');
});

test('la relecture restitue le payload à l\'identique', function () use ($http, $token) {
    $payload = ['v' => 1, 'iv' => 'SVYxMjM', 'salt' => 'U0VMMTIz', 'iter' => 310000, 'pwd' => 0, 'ct' => 'Q0lQSEVSVEVYVA'];
    $id = $http->createSecret(['payload' => $payload], $token)->json()['id'];
    $got = $http->get('/api.php?id=' . $id)->json();
    assert_eq($payload, $got['payload'], 'payload identique à l\'envoi');
    assert_eq(false, $got['burn'], 'indicateur de destruction à faux');
});

test('la lecture ne requiert aucune session', function () use ($http, $token, $base) {
    $id = $http->createSecret([], $token)->json()['id'];
    $anon = new Http($base);
    assert_eq(200, $anon->get('/api.php?id=' . $id)->status, 'lecture publique');
});

test('un secret expiré renvoie 404 et disparaît de la base', function () use ($http, $token) {
    $id = $http->createSecret(['expire' => '1h'], $token)->json()['id'];
    db()->prepare('UPDATE pastes SET expires = ? WHERE id = ?')->execute([time() - 1, $id]);
    assert_eq(404, $http->get('/api.php?id=' . $id)->status, 'secret périmé inaccessible');
    $left = db()->query('SELECT COUNT(*) FROM pastes WHERE id = ' . db()->quote($id))->fetchColumn();
    assert_eq(0, (int) $left, 'ligne supprimée à la volée');
});

test('la création purge les secrets expirés restants', function () use ($http, $token) {
    db()->prepare('INSERT INTO pastes (id, payload, burn, delete_hash, created, expires) VALUES (?,?,?,?,?,?)')
        ->execute(['purgeparapi00000', '{}', 0, 'h', time() - 100, time() - 50]);
    $http->createSecret([], $token);
    $left = db()->query("SELECT COUNT(*) FROM pastes WHERE id = 'purgeparapi00000'")->fetchColumn();
    assert_eq(0, (int) $left, 'purge déclenchée à la création');
});

// ---------------------------------------------------------------------------
group('Destruction après lecture');

test('un secret à lecture unique disparaît après sa première lecture', function () use ($http, $token) {
    $id = $http->createSecret(['burn' => true], $token)->json()['id'];
    $first = $http->get('/api.php?id=' . $id);
    assert_eq(200, $first->status, 'première lecture servie');
    assert_eq(true, $first->json()['burn'], 'indicateur de destruction transmis');
    assert_eq(404, $http->get('/api.php?id=' . $id)->status, 'seconde lecture impossible');
});

test('la sonde « meta » ne consomme pas le secret', function () use ($http, $token) {
    $id = $http->createSecret(['burn' => true], $token)->json()['id'];
    $meta = $http->get('/api.php?id=' . $id . '&meta=1');
    assert_eq(200, $meta->status, 'sonde servie');
    assert_eq(true, $meta->json()['burn'], 'destruction annoncée');
    assert_true(!isset($meta->json()['payload']), 'la sonde ne divulgue pas le payload');
    assert_eq(200, $http->get('/api.php?id=' . $id)->status, 'secret toujours lisible après la sonde');
});

test('un secret ordinaire survit à plusieurs lectures', function () use ($http, $token) {
    $id = $http->createSecret(['burn' => false], $token)->json()['id'];
    for ($i = 0; $i < 3; $i++) {
        assert_eq(200, $http->get('/api.php?id=' . $id)->status, 'lecture ' . ($i + 1));
    }
});

// ---------------------------------------------------------------------------
group('Lien de suppression');

test('supprime le secret avec un jeton valide et une session ouverte', function () use ($http, $token) {
    $d = $http->createSecret([], $token)->json();
    $res = $http->get('/api.php?id=' . $d['id'] . '&delete=' . $d['delete_token']);
    assert_eq(200, $res->status, 'suppression acceptée');
    assert_eq(true, $res->json()['deleted'], 'confirmation renvoyée');
    assert_eq(404, $http->get('/api.php?id=' . $d['id'])->status, 'secret réellement supprimé');
});

test('un jeton erroné est refusé (403) et le secret survit', function () use ($http, $token) {
    $d = $http->createSecret([], $token)->json();
    $res = $http->get('/api.php?id=' . $d['id'] . '&delete=' . str_repeat('x', 24));
    assert_eq(403, $res->status, 'jeton de suppression invalide');
    assert_eq(200, $http->get('/api.php?id=' . $d['id'])->status, 'secret intact');
});

test('un visiteur anonyme muni du bon jeton est renvoyé vers la connexion', function () use ($http, $token, $base) {
    $d = $http->createSecret([], $token)->json();
    $anon = new Http($base);
    $res = $anon->get('/api.php?id=' . $d['id'] . '&delete=' . $d['delete_token']);
    assert_eq(302, $res->status, 'redirection vers login.php');
    assert_contains('login.php', (string) $res->header('Location'), 'cible de la redirection');
    assert_eq(200, $http->get('/api.php?id=' . $d['id'])->status, 'secret non supprimé par l\'anonyme');
});

// ---------------------------------------------------------------------------
group('Méthodes HTTP');

foreach (['PUT', 'DELETE', 'PATCH'] as $method) {
    test("la méthode $method est refusée (405)", function () use ($http, $method) {
        $res = $http->request($method, '/api.php');
        assert_eq(405, $res->status, "$method non autorisée");
    });
}

exit(summary('Règles métier de l\'API'));
