<?php
// Régressions de sécurité : ce qui ne doit jamais redevenir possible.
// Chaque test correspond à une garantie annoncée par le README.
declare(strict_types=1);

require __DIR__ . '/lib.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$base = getenv('STASHBIN_URL') ?: 'http://127.0.0.1';
$user = getenv('STASHBIN_USER') ?: 'testeur';
$pass = getenv('STASHBIN_PASS') ?: 'motdepassetest';

$http = new Http($base);
$token = $http->login($user, $pass);

// ---------------------------------------------------------------------------
group('Rien hors de public/ n\'est servi');

test('config.php n\'est pas accessible', function () use ($http) {
    foreach (['/../config.php', '/config.php', '/..%2fconfig.php'] as $path) {
        $res = $http->request('GET', $path, rawPath: true);
        assert_not_contains('STASHBIN_DB', $res->body, "« $path » ne divulgue pas la configuration");
    }
});

test('le code de src/ n\'est pas accessible', function () use ($http) {
    $res = $http->request('GET', '/../src/bootstrap.php', rawPath: true);
    assert_not_contains('function db(', $res->body, 'bootstrap.php non servi');
});

test('la base SQLite n\'est pas téléchargeable', function () use ($http) {
    foreach (['/../data/stashbin.sqlite', '/data/stashbin.sqlite'] as $path) {
        $res = $http->request('GET', $path, rawPath: true);
        assert_true($res->status >= 400, "« $path » inaccessible (HTTP {$res->status})");
    }
});

test('bin/user.php n\'est pas exposé', function () use ($http) {
    $res = $http->request('GET', '/../bin/user.php', rawPath: true);
    assert_not_contains('password_hash', $res->body, 'la CLI de gestion des comptes reste hors ligne');
});

// ---------------------------------------------------------------------------
group('En-têtes de sécurité');

test('la politique de sécurité du contenu est stricte', function () use ($http) {
    $csp = (string) $http->get('/login.php')->header('Content-Security-Policy');
    assert_contains("default-src 'none'", $csp, 'tout est refusé par défaut');
    assert_contains("script-src 'self'", $csp, 'scripts limités à l\'origine');
    assert_contains("frame-ancestors 'none'", $csp, 'inclusion en iframe interdite');
    assert_contains("base-uri 'none'", $csp, 'balise base neutralisée');
    assert_contains("form-action 'self'", $csp, 'soumission de formulaire limitée à l\'origine');
    assert_not_contains("'unsafe-inline'", $csp, 'aucun script ni style en ligne autorisé');
    assert_not_contains("'unsafe-eval'", $csp, 'eval interdit');
});

test('les autres en-têtes de durcissement sont présents', function () use ($http) {
    $res = $http->get('/login.php');
    assert_eq('nosniff', $res->header('X-Content-Type-Options'), 'pas de reniflage de type');
    assert_eq('no-referrer', $res->header('Referrer-Policy'), 'aucun référent transmis');
});

test('les en-têtes sont aussi posés sur l\'API', function () use ($http) {
    $csp = (string) $http->get('/api.php?id=!!')->header('Content-Security-Policy');
    assert_contains("default-src 'none'", $csp, 'API protégée de la même façon');
});

// ---------------------------------------------------------------------------
group('Session et authentification');

test('la configuration livrée exige l\'authentification', function () {
    // L'ouverture est un choix explicite de l'exploitant : le dépôt ne doit
    // jamais partir avec la création accessible à tous.
    assert_eq(true, config()['auth'], 'clé « auth » à true dans config.php');
});

test('le cookie de session est HttpOnly et SameSite', function () use ($base) {
    $anon = new Http($base);
    $cookies = $anon->get('/login.php')->headerAll('Set-Cookie');
    assert_true($cookies !== [], 'un cookie de session est émis');
    $c = implode(' ', $cookies);
    assert_contains('HttpOnly', $c, 'inaccessible au JavaScript');
    assert_contains('SameSite=Lax', $c, 'protégé contre les requêtes intersites');
    assert_contains('path=/', strtolower($c), 'portée explicite');
});

test('un mot de passe erroné n\'ouvre pas de session', function () use ($base, $user) {
    $anon = new Http($base);
    $page = $anon->get('/login.php')->body;
    preg_match('/name="csrf" value="([^"]+)"/', $page, $m);
    $res = $anon->post('/login.php', ['csrf' => $m[1], 'username' => $user, 'password' => 'mauvais']);
    assert_eq(200, $res->status, 'retour au formulaire, pas de redirection');
    assert_contains('Identifiants incorrects', $res->body, 'message d\'échec affiché');
    assert_eq(302, $anon->get('/index.php')->status, 'accès à la création toujours refusé');
});

test('un compte inexistant est rejeté comme un mot de passe erroné', function () use ($base) {
    $anon = new Http($base);
    $page = $anon->get('/login.php')->body;
    preg_match('/name="csrf" value="([^"]+)"/', $page, $m);
    $res = $anon->post('/login.php', ['csrf' => $m[1], 'username' => 'inexistant', 'password' => 'x']);
    assert_contains('Identifiants incorrects', $res->body, 'même message : pas d\'énumération de comptes');
});

test('la connexion sans jeton CSRF est refusée', function () use ($base, $user, $pass) {
    $anon = new Http($base);
    $anon->get('/login.php');
    $res = $anon->post('/login.php', ['username' => $user, 'password' => $pass]);
    assert_contains('Session expirée', $res->body, 'formulaire rejeté sans jeton');
    assert_eq(302, $anon->get('/index.php')->status, 'aucune session ouverte');
});

test('l\'identifiant de session change à la connexion (anti-fixation)', function () use ($base, $user, $pass) {
    $anon = new Http($base);
    $before = $anon->get('/login.php')->header('Set-Cookie');
    preg_match('/stashbin=([^;]+)/', (string) $before, $m1);
    preg_match('/name="csrf" value="([^"]+)"/', $anon->get('/login.php')->body, $mc);
    $res = $anon->post('/login.php', ['csrf' => $mc[1], 'username' => $user, 'password' => $pass]);
    preg_match('/stashbin=([^;]+)/', (string) $res->header('Set-Cookie'), $m2);
    assert_true(isset($m1[1], $m2[1]), 'deux identifiants de session observés');
    assert_true($m1[1] !== $m2[1], 'identifiant régénéré après authentification');
});

test('la déconnexion referme la session', function () use ($base, $user, $pass) {
    $c = new Http($base);
    $c->login($user, $pass);
    assert_eq(200, $c->get('/index.php')->status, 'session ouverte');
    $c->get('/logout.php');
    assert_eq(302, $c->get('/index.php')->status, 'session fermée');
});

test('la page de création est inaccessible sans session', function () use ($base) {
    $anon = new Http($base);
    $res = $anon->get('/index.php');
    assert_eq(302, $res->status, 'redirection');
    assert_contains('login.php', (string) $res->header('Location'), 'vers la page de connexion');
});

// ---------------------------------------------------------------------------
group('Le serveur ne peut pas lire les secrets');

test('le payload est stocké tel quel, sans déchiffrement possible', function () use ($http, $token) {
    $marker = 'TEXTEENCLAIRQUINEDOITJAMAISAPPARAITRE';
    $payload = ['v' => 1, 'iv' => 'AAAA', 'salt' => 'BBBB', 'iter' => 310000, 'pwd' => 0,
                'ct' => base64_encode($marker)];
    $id = $http->createSecret(['payload' => $payload], $token)->json()['id'];

    $stored = db()->query('SELECT payload FROM pastes WHERE id = ' . db()->quote($id))->fetchColumn();
    // Le serveur conserve le conteneur JSON à l'identique : il ne décode rien,
    // ne dérive aucune clé, et n'a aucun moyen d'obtenir le texte en clair.
    assert_eq($payload, json_decode((string) $stored, true), 'payload conservé sans transformation');
    assert_not_contains($marker, (string) $stored, 'aucun déchiffrement côté serveur');
});

test('aucune colonne ne conserve de clé ni de mot de passe', function () {
    $cols = array_column(db()->query('PRAGMA table_info(pastes)')->fetchAll(), 'name');
    foreach (['key', 'urlkey', 'password', 'passphrase', 'plaintext'] as $forbidden) {
        assert_true(!in_array($forbidden, $cols, true), "colonne « $forbidden » absente du schéma");
    }
});

test('le jeton de suppression est stocké haché, jamais en clair', function () use ($http, $token) {
    $d = $http->createSecret([], $token)->json();
    $stored = db()->query('SELECT delete_hash FROM pastes WHERE id = ' . db()->quote($d['id']))->fetchColumn();
    assert_true($stored !== $d['delete_token'], 'le jeton brut n\'est pas en base');
    assert_eq(hash('sha256', $d['delete_token']), $stored, 'empreinte SHA-256 enregistrée');
});

test('le mot de passe des comptes est haché avec un algorithme reconnu', function () {
    $hash = (string) db()->query('SELECT pass_hash FROM users LIMIT 1')->fetchColumn();
    assert_matches('/^\$(2y|argon2i?d?)\$/', $hash, 'bcrypt ou argon2, jamais du clair ni du MD5');
    $info = password_get_info($hash);
    assert_true($info['algo'] !== null && $info['algo'] !== '0', 'algorithme reconnu par PHP');
});

// ---------------------------------------------------------------------------
group('Injection');

test('un identifiant contenant du SQL ne perturbe pas la requête', function () use ($http) {
    // Le filtre de format rejette avant même la requête préparée : deux barrières.
    $res = $http->get('/api.php?id=' . urlencode("' OR 1=1 --"));
    assert_eq(400, $res->status, 'tentative d\'injection rejetée');
    assert_true((int) db()->query('SELECT COUNT(*) FROM pastes')->fetchColumn() >= 0, 'table intacte');
});

test('un nom d\'utilisateur contenant du SQL ne perturbe pas la connexion', function () use ($base) {
    $anon = new Http($base);
    preg_match('/name="csrf" value="([^"]+)"/', $anon->get('/login.php')->body, $m);
    $res = $anon->post('/login.php', [
        'csrf' => $m[1], 'username' => "' OR '1'='1", 'password' => 'x',
    ]);
    assert_contains('Identifiants incorrects', $res->body, 'aucune session ouverte par injection');
    assert_true((int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0, 'table users intacte');
});

test('le nom d\'utilisateur est échappé à l\'affichage', function () use ($base) {
    // Un compte au nom piégé ne doit pas injecter de script dans index.php.
    $name = '<img src=x onerror=alert(1)>';
    $hash = password_hash('motdepassetest', PASSWORD_DEFAULT);
    db()->prepare('INSERT OR REPLACE INTO users (username, pass_hash, created) VALUES (?,?,?)')
        ->execute([$name, $hash, time()]);

    $c = new Http($base);
    $c->login($name, 'motdepassetest');
    $page = $c->get('/index.php')->body;
    assert_not_contains('<img src=x', $page, 'balise non interprétée');
    assert_contains('&lt;img src=x', $page, 'affichée échappée');

    db()->prepare('DELETE FROM users WHERE username = ?')->execute([$name]);
});

// ---------------------------------------------------------------------------
group('Le choix de la langue n\'ouvre rien');

test('un paramètre « lang » qui est un chemin ne charge aucun fichier', function () use ($http) {
    foreach (['../config', '/etc/passwd', 'fr/../../config', '../../../../etc/passwd'] as $lang) {
        $res = $http->get('/index.php?lang=' . rawurlencode($lang));
        assert_eq(200, $res->status, "« $lang » ne fait pas échouer la page");
        assert_not_contains('STASHBIN_DB', $res->body, "« $lang » ne divulgue pas la configuration");
        assert_contains('lang="fr"', $res->body, "« $lang » n'écrase pas la langue demandée");
    }
});

test('une langue que l\'on ne sait pas servir donne l\'anglais, jamais un dictionnaire vide', function () use ($base) {
    // Le repli doit être une vraie langue : servi vide, l'interface afficherait
    // ses identifiants de clés à l'écran.
    // login.php plutôt qu'index.php : le client n'a pas de session, et une
    // redirection ne renverrait aucun corps à inspecter.
    $muet = new Http($base, language: null);
    foreach ([null, 'de, es', 'xx-yy'] as $header) {
        $res = $muet->request('GET', '/login.php', null, $header === null ? [] : ['Accept-Language' => $header]);
        assert_contains('lang="en"', $res->body, 'anglais servi faute de mieux');
        assert_contains('Sign in', $res->body, 'libellés effectivement traduits');
        assert_not_contains('login.submit', $res->body, 'aucune clé brute affichée');
        assert_not_contains('login.intro', $res->body, 'aucune clé brute affichée');
    }
});

test('un paramètre « lang » piégé n\'est pas réinjecté brut dans la page', function () use ($http) {
    // La valeur revient dans l'attribut « lang » et dans les liens que la page
    // fabrique : elle doit y arriver échappée, ou pas du tout.
    $res = $http->get('/index.php?lang=' . rawurlencode('"><script>alert(1)</script>'));
    assert_not_contains('<script>alert(1)', $res->body, 'aucun script injecté');
});

test('un en-tête Accept-Language hostile ne perturbe pas la page', function () use ($http) {
    foreach (['<script>alert(1)</script>', '../../etc/passwd', str_repeat('fr,', 500) . 'fr'] as $header) {
        $res = $http->request('GET', '/index.php', null, ['Accept-Language' => $header]);
        assert_eq(200, $res->status, 'page servie malgré un en-tête aberrant');
        assert_not_contains('<script>alert(1)', $res->body, 'aucun script injecté');
    }
});

test('les pages annoncent que leur contenu dépend de la langue', function () use ($http) {
    // Sans « Vary », un cache intermédiaire servirait à tous la langue du
    // premier visiteur — y compris sur la page de connexion.
    foreach (['/index.php', '/view.php?id=inexistant', '/api.php?id=' . str_repeat('z', 16)] as $path) {
        $res = $http->get($path);
        assert_contains('Accept-Language', (string) $res->header('Vary'), "« $path » pose l'en-tête Vary");
    }
});

test('la langue ne change ni le chiffrement ni ce que le serveur conserve', function () use ($http, $token) {
    // La traduction est de la présentation : elle ne doit toucher à rien de ce
    // que le produit promet.
    $payload = ['v' => 1, 'iv' => 'SVYxMjM', 'salt' => 'U0VMMTIz', 'iter' => 310000, 'pwd' => 0, 'ct' => 'Q0lQSEVS'];
    $id = $http->createSecret(['payload' => $payload], $token)->json()['id'];
    $got = $http->request('GET', '/api.php?id=' . $id . '&lang=en', null, ['Accept-Language' => 'en'])->json();
    assert_eq($payload, $got['payload'], 'payload restitué à l\'identique en anglais');

    $stored = db()->query('SELECT payload FROM pastes WHERE id = ' . db()->quote($id))->fetchColumn();
    assert_eq($payload, json_decode((string) $stored, true), 'base inchangée');
});

exit(summary('Régressions de sécurité'));
