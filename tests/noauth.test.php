<?php
// Comportement de l'instance ouverte, c'est-à-dire configurée avec « auth »
// à false. Cette suite exige un serveur démarré avec STASHBIN_AUTH=0 : le
// réglage est lu au démarrage, il n'y a pas de bascule à chaud.
//
// Ce qui est vérifié ici tient en une phrase : l'authentification tombe, et
// rien d'autre. Le chiffrement, la CSRF, le hachage du jeton de suppression
// et la lecture publique restent exactement ce qu'ils sont.
declare(strict_types=1);

require __DIR__ . '/lib.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$base = getenv('STASHBIN_URL') ?: 'http://127.0.0.1';

if (auth_enabled()) {
    fwrite(STDERR, "Cette suite exige une instance sans authentification (STASHBIN_AUTH=0).\n");
    exit(2);
}

// Aucun appel à login() : il n'y a plus de formulaire de connexion. Le client
// n'obtient qu'un cookie de session, porteur du seul jeton CSRF.
$http = new Http($base);
$token = $http->csrfToken();

// ---------------------------------------------------------------------------
group('Création sans compte');

test('la page de création est servie sans session', function () use ($base) {
    $anon = new Http($base);
    assert_eq(200, $anon->get('/index.php')->status, 'aucune redirection vers la connexion');
});

test('la page de création annonce que l\'authentification est désactivée', function () use ($base) {
    $page = (new Http($base))->get('/index.php')->body;
    assert_contains('Authentification désactivée', $page, 'avertissement affiché');
    assert_not_contains('Connecté en tant que', $page, 'aucun utilisateur nommé');
    assert_not_contains('logout.php', $page, 'aucun lien de déconnexion');
});

test('un visiteur anonyme peut créer un secret (201)', function () use ($http, $token) {
    $res = $http->createSecret([], $token);
    assert_eq(201, $res->status, 'création acceptée sans compte');
    assert_matches('/^[A-Za-z0-9_-]{16}$/', $res->json()['id'], 'identifiant renvoyé');
});

test('le jeton CSRF reste exigé (403)', function () use ($http) {
    // La CSRF ne protège pas l'authentification mais le navigateur du visiteur :
    // elle n'a aucune raison de tomber avec les comptes.
    $res = $http->post('/api.php', json_encode(['payload' => []]), ['Content-Type' => 'application/json']);
    assert_eq(403, $res->status, 'création refusée sans jeton');
});

test('un jeton CSRF erroné reste refusé (403)', function () use ($http) {
    assert_eq(403, $http->createSecret([], str_repeat('0', 64))->status, 'jeton invalide rejeté');
});

test('la validation du payload est inchangée (400)', function () use ($http, $token) {
    $res = $http->createSecret(['payload' => ['v' => 1, 'iv' => 'A']], $token);
    assert_eq(400, $res->status, 'payload incomplet toujours rejeté');
    assert_eq('payload incomplet', $res->json()['error'], 'même message qu\'avec authentification');
});

// ---------------------------------------------------------------------------
group('Pages de connexion neutralisées');

test('login.php renvoie vers la page de création', function () use ($base) {
    $res = (new Http($base))->get('/login.php');
    assert_eq(302, $res->status, 'redirection');
    assert_contains('index.php', (string) $res->header('Location'), 'vers la création');
    assert_not_contains('Se connecter', $res->body, 'aucun formulaire servi');
});

test('login.php n\'ouvre même pas de session', function () use ($base) {
    // Rien à protéger, rien à mémoriser : inutile de poser un cookie.
    $res = (new Http($base))->get('/login.php');
    assert_eq([], $res->headerAll('Set-Cookie'), 'aucun cookie émis');
});

test('logout.php renvoie vers la page de création', function () use ($base) {
    $res = (new Http($base))->get('/logout.php');
    assert_eq(302, $res->status, 'redirection');
    assert_contains('index.php', (string) $res->header('Location'), 'pas vers login.php');
});

// ---------------------------------------------------------------------------
group('Suppression par le seul jeton');

test('le jeton de suppression suffit, sans session', function () use ($http, $token, $base) {
    $d = $http->createSecret([], $token)->json();
    $anon = new Http($base);
    $res = $anon->get('/api.php?id=' . $d['id'] . '&delete=' . $d['delete_token']);
    assert_eq(200, $res->status, 'suppression acceptée');
    assert_eq(true, $res->json()['deleted'], 'confirmation renvoyée');
    assert_eq(404, $http->get('/api.php?id=' . $d['id'])->status, 'secret réellement supprimé');
});

test('un jeton erroné reste refusé (403) et le secret survit', function () use ($http, $token) {
    $d = $http->createSecret([], $token)->json();
    $res = $http->get('/api.php?id=' . $d['id'] . '&delete=' . str_repeat('x', 24));
    assert_eq(403, $res->status, 'jeton de suppression invalide');
    assert_eq(200, $http->get('/api.php?id=' . $d['id'])->status, 'secret intact');
});

// ---------------------------------------------------------------------------
group('Ce que l\'ouverture ne change pas');

test('la configuration signale l\'authentification désactivée', function () {
    assert_eq(false, config()['auth'], 'clé « auth » à false');
    assert_true(!auth_enabled(), 'auth_enabled() suit la configuration');
});

test('la lecture reste ouverte à quiconque possède le lien', function () use ($http, $token, $base) {
    $id = $http->createSecret([], $token)->json()['id'];
    assert_eq(200, (new Http($base))->get('/api.php?id=' . $id)->status, 'lecture publique');
});

test('le serveur ne déchiffre toujours rien', function () use ($http, $token) {
    $marker = 'TEXTEENCLAIRQUINEDOITJAMAISAPPARAITRE';
    $payload = ['v' => 1, 'iv' => 'AAAA', 'salt' => 'BBBB', 'iter' => 310000, 'pwd' => 0,
                'ct' => base64_encode($marker)];
    $id = $http->createSecret(['payload' => $payload], $token)->json()['id'];
    $stored = db()->query('SELECT payload FROM pastes WHERE id = ' . db()->quote($id))->fetchColumn();
    assert_eq($payload, json_decode((string) $stored, true), 'payload conservé sans transformation');
    assert_not_contains($marker, (string) $stored, 'aucun déchiffrement côté serveur');
});

test('le jeton de suppression reste stocké haché', function () use ($http, $token) {
    $d = $http->createSecret([], $token)->json();
    $stored = db()->query('SELECT delete_hash FROM pastes WHERE id = ' . db()->quote($d['id']))->fetchColumn();
    assert_true($stored !== $d['delete_token'], 'le jeton brut n\'est pas en base');
    assert_eq(hash('sha256', $d['delete_token']), $stored, 'empreinte SHA-256 enregistrée');
});

test('les secrets à lecture unique disparaissent toujours après lecture', function () use ($http, $token) {
    $id = $http->createSecret(['burn' => true], $token)->json()['id'];
    assert_eq(200, $http->get('/api.php?id=' . $id)->status, 'première lecture servie');
    assert_eq(404, $http->get('/api.php?id=' . $id)->status, 'seconde lecture impossible');
});

test('les en-têtes de durcissement sont toujours posés', function () use ($base) {
    $res = (new Http($base))->get('/index.php');
    assert_contains("default-src 'none'", (string) $res->header('Content-Security-Policy'), 'CSP stricte');
    assert_eq('nosniff', $res->header('X-Content-Type-Options'), 'pas de reniflage de type');
    assert_eq('no-referrer', $res->header('Referrer-Policy'), 'aucun référent transmis');
});

test('le cookie de session reste HttpOnly et SameSite', function () use ($base) {
    // Il ne porte plus d'identité, mais il porte le jeton CSRF.
    $c = implode(' ', (new Http($base))->get('/index.php')->headerAll('Set-Cookie'));
    assert_contains('HttpOnly', $c, 'inaccessible au JavaScript');
    assert_contains('SameSite=Lax', $c, 'protégé contre les requêtes intersites');
});

test('rien hors de public/ n\'est servi', function () use ($base) {
    $res = (new Http($base))->request('GET', '/../config.php', rawPath: true);
    assert_not_contains('STASHBIN_AUTH', $res->body, 'la configuration reste hors ligne');
});

exit(summary('Instance ouverte (sans authentification)'));
