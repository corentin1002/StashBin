<?php
// Behaviour of the open instance, that is, one configured with "auth" set to
// false. This suite requires a server started with STASHBIN_AUTH=0: the
// setting is read at startup, there is no hot switch.
//
// What is checked here fits in one sentence: authentication falls away, and
// nothing else. Encryption, CSRF, the hashing of the deletion token and public
// reading all stay exactly what they are.
declare(strict_types=1);

require __DIR__ . '/lib.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$base = getenv('STASHBIN_URL') ?: 'http://127.0.0.1';

if (auth_enabled()) {
    fwrite(STDERR, "This suite requires an instance without authentication (STASHBIN_AUTH=0).\n");
    exit(2);
}

// No call to login(): there is no sign-in form any more. The client only gets
// a session cookie, carrying nothing but the CSRF token.
$http = new Http($base);
$token = $http->csrfToken();

// ---------------------------------------------------------------------------
group('Creation without an account');

test('the creation page is served without a session', function () use ($base) {
    $anon = new Http($base);
    assert_eq(200, $anon->get('/index.php')->status, 'no redirect to sign-in');
});

test('the creation page announces that authentication is disabled', function () use ($base) {
    $page = (new Http($base))->get('/index.php')->body;
    assert_contains('Authentification désactivée', $page, 'warning shown');
    assert_not_contains('Connecté en tant que', $page, 'no user named');
    assert_not_contains('logout.php', $page, 'no sign-out link');
});

test('an anonymous visitor can create a secret (201)', function () use ($http, $token) {
    $res = $http->createSecret([], $token);
    assert_eq(201, $res->status, 'creation accepted without an account');
    assert_matches('/^[A-Za-z0-9_-]{16}$/', $res->json()['id'], 'identifier returned');
});

test('the CSRF token is still required (403)', function () use ($http) {
    // CSRF does not protect authentication but the visitor's browser: it has
    // no reason to fall away with the accounts.
    $res = $http->post('/api.php', json_encode(['payload' => []]), ['Content-Type' => 'application/json']);
    assert_eq(403, $res->status, 'creation refused without a token');
});

test('a wrong CSRF token is still refused (403)', function () use ($http) {
    assert_eq(403, $http->createSecret([], str_repeat('0', 64))->status, 'invalid token rejected');
});

test('payload validation is unchanged (400)', function () use ($http, $token) {
    $res = $http->createSecret(['payload' => ['v' => 1, 'iv' => 'A']], $token);
    assert_eq(400, $res->status, 'incomplete payload still rejected');
    assert_eq('incomplete_payload', $res->json()['code'], 'same code as with authentication');
});

// ---------------------------------------------------------------------------
group('Sign-in pages neutralised');

test('login.php redirects to the creation page', function () use ($base) {
    $res = (new Http($base))->get('/login.php');
    assert_eq(302, $res->status, 'redirect');
    assert_contains('index.php', (string) $res->header('Location'), 'towards creation');
    assert_not_contains('Se connecter', $res->body, 'no form served');
});

test('login.php does not even open a session', function () use ($base) {
    // Nothing to protect, nothing to remember: no point setting a cookie.
    $res = (new Http($base))->get('/login.php');
    assert_eq([], $res->headerAll('Set-Cookie'), 'no cookie issued');
});

test('logout.php redirects to the creation page', function () use ($base) {
    $res = (new Http($base))->get('/logout.php');
    assert_eq(302, $res->status, 'redirect');
    assert_contains('index.php', (string) $res->header('Location'), 'not towards login.php');
});

// ---------------------------------------------------------------------------
group('Deletion by the token alone');

test('the deletion token is enough, with no session', function () use ($http, $token, $base) {
    $d = $http->createSecret([], $token)->json();
    $anon = new Http($base);
    $res = $anon->get('/api.php?id=' . $d['id'] . '&delete=' . $d['delete_token']);
    assert_eq(200, $res->status, 'deletion accepted');
    assert_eq(true, $res->json()['deleted'], 'confirmation returned');
    assert_eq(404, $http->get('/api.php?id=' . $d['id'])->status, 'secret really deleted');
});

test('a wrong token is still refused (403) and the secret survives', function () use ($http, $token) {
    $d = $http->createSecret([], $token)->json();
    $res = $http->get('/api.php?id=' . $d['id'] . '&delete=' . str_repeat('x', 24));
    assert_eq(403, $res->status, 'invalid deletion token');
    assert_eq(200, $http->get('/api.php?id=' . $d['id'])->status, 'secret intact');
});

// ---------------------------------------------------------------------------
group('No inventory without accounts');

test('secrets.php redirects to the creation page', function () use ($base) {
    // No account, no owner, no list: the page has nothing to show and says so
    // by not existing, rather than by displaying an empty one.
    $res = (new Http($base))->get('/secrets.php');
    assert_eq(302, $res->status, 'redirect');
    assert_contains('index.php', (string) $res->header('Location'), 'sent to creation');
});

test('the creation page offers no title field', function () use ($base) {
    $page = (new Http($base))->get('/index.php')->body;
    assert_not_contains('id="title"', $page, 'no field for a title nobody could read back');
});

test('a title sent anyway is not stored', function () use ($http, $token) {
    // The field is gone from the page, but the API is reachable directly: what
    // would only end up as an unreadable string in the clear is dropped.
    $id = $http->createSecret(['title' => 'ETIQUETTESANSCOMPTE'], $token)->json()['id'];
    $stored = db()->query('SELECT title FROM pastes WHERE id = ' . db()->quote($id))->fetchColumn();
    assert_eq(null, $stored, 'nothing kept in the clear for want of a list');
});

test('secrets are created without an owner', function () use ($http, $token) {
    $id = $http->createSecret([], $token)->json()['id'];
    $owner = db()->query('SELECT owner_id FROM pastes WHERE id = ' . db()->quote($id))->fetchColumn();
    assert_eq(null, $owner, 'no owner invented');
});

test('nothing is logged about the readers', function () use ($http, $token) {
    // The access log exists for the creator's list. Without a list, recording
    // reader addresses would be collecting for nobody.
    $id = $http->createSecret([], $token)->json()['id'];
    $http->get('/api.php?id=' . $id);
    $logged = db()->query('SELECT COUNT(*) FROM paste_reads WHERE paste_id = ' . db()->quote($id))->fetchColumn();
    assert_eq(0, (int) $logged, 'no access recorded');
});

// ---------------------------------------------------------------------------
group('What opening up does not change');

test('the configuration reports authentication as disabled', function () {
    assert_eq(false, config()['auth'], '"auth" key set to false');
    assert_true(!auth_enabled(), 'auth_enabled() follows the configuration');
});

test('reading stays open to anyone holding the link', function () use ($http, $token, $base) {
    $id = $http->createSecret([], $token)->json()['id'];
    assert_eq(200, (new Http($base))->get('/api.php?id=' . $id)->status, 'public reading');
});

test('the server still decrypts nothing', function () use ($http, $token) {
    $marker = 'PLAINTEXTTHATMUSTNEVERAPPEAR';
    $payload = ['v' => 1, 'iv' => 'AAAA', 'salt' => 'BBBB', 'iter' => 310000, 'pwd' => 0,
                'ct' => base64_encode($marker)];
    $id = $http->createSecret(['payload' => $payload], $token)->json()['id'];
    $stored = db()->query('SELECT payload FROM pastes WHERE id = ' . db()->quote($id))->fetchColumn();
    assert_eq($payload, json_decode((string) $stored, true), 'payload kept without transformation');
    assert_not_contains($marker, (string) $stored, 'no server-side decryption');
});

test('the deletion token is still stored hashed', function () use ($http, $token) {
    $d = $http->createSecret([], $token)->json();
    $stored = db()->query('SELECT delete_hash FROM pastes WHERE id = ' . db()->quote($d['id']))->fetchColumn();
    assert_true($stored !== $d['delete_token'], 'the raw token is not in the database');
    assert_eq(hash('sha256', $d['delete_token']), $stored, 'SHA-256 digest recorded');
});

test('read-once secrets still disappear after reading', function () use ($http, $token) {
    $id = $http->createSecret(['burn' => true], $token)->json()['id'];
    assert_eq(200, $http->get('/api.php?id=' . $id)->status, 'first read served');
    assert_eq(404, $http->get('/api.php?id=' . $id)->status, 'second read impossible');
    // And they leave nothing behind: a headstone is only of use to an owner.
    $left = db()->query('SELECT COUNT(*) FROM pastes WHERE id = ' . db()->quote($id))->fetchColumn();
    assert_eq(0, (int) $left, 'row gone for good');
});

test('the hardening headers are still set', function () use ($base) {
    $res = (new Http($base))->get('/index.php');
    assert_contains("default-src 'none'", (string) $res->header('Content-Security-Policy'), 'strict CSP');
    assert_eq('nosniff', $res->header('X-Content-Type-Options'), 'no type sniffing');
    assert_eq('no-referrer', $res->header('Referrer-Policy'), 'no referrer sent');
});

test('the session cookie stays HttpOnly and SameSite', function () use ($base) {
    // It no longer carries an identity, but it carries the CSRF token.
    $c = implode(' ', (new Http($base))->get('/index.php')->headerAll('Set-Cookie'));
    assert_contains('HttpOnly', $c, 'unreachable from JavaScript');
    assert_contains('SameSite=Lax', $c, 'protected against cross-site requests');
});

test('nothing outside public/ is served', function () use ($base) {
    $res = (new Http($base))->request('GET', '/../config.php', rawPath: true);
    assert_not_contains('STASHBIN_AUTH', $res->body, 'the configuration stays offline');
});

exit(summary('Open instance (without authentication)'));
