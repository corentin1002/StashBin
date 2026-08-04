<?php
// Security regressions: what must never become possible again.
// Every test corresponds to a guarantee the README makes.
declare(strict_types=1);

require __DIR__ . '/lib.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$base = getenv('STASHBIN_URL') ?: 'http://127.0.0.1';
$user = getenv('STASHBIN_USER') ?: 'tester';
$pass = getenv('STASHBIN_PASS') ?: 'testpassword';

$http = new Http($base);
$token = $http->login($user, $pass);

// ---------------------------------------------------------------------------
group('Nothing outside public/ is served');

test('config.php is not reachable', function () use ($http) {
    foreach (['/../config.php', '/config.php', '/..%2fconfig.php'] as $path) {
        $res = $http->request('GET', $path, rawPath: true);
        assert_not_contains('STASHBIN_DB', $res->body, "\"$path\" does not leak the configuration");
    }
});

test('the code in src/ is not reachable', function () use ($http) {
    $res = $http->request('GET', '/../src/bootstrap.php', rawPath: true);
    assert_not_contains('function db(', $res->body, 'bootstrap.php not served');
});

test('the SQLite database cannot be downloaded', function () use ($http) {
    foreach (['/../data/stashbin.sqlite', '/data/stashbin.sqlite'] as $path) {
        $res = $http->request('GET', $path, rawPath: true);
        assert_true($res->status >= 400, "\"$path\" unreachable (HTTP {$res->status})");
    }
});

test('bin/user.php is not exposed', function () use ($http) {
    $res = $http->request('GET', '/../bin/user.php', rawPath: true);
    assert_not_contains('password_hash', $res->body, 'the account management CLI stays offline');
});

// ---------------------------------------------------------------------------
group('Security headers');

test('the content security policy is strict', function () use ($http) {
    $csp = (string) $http->get('/login.php')->header('Content-Security-Policy');
    assert_contains("default-src 'none'", $csp, 'everything refused by default');
    assert_contains("script-src 'self'", $csp, 'scripts limited to the origin');
    assert_contains("frame-ancestors 'none'", $csp, 'framing forbidden');
    assert_contains("base-uri 'none'", $csp, 'base tag neutralised');
    assert_contains("form-action 'self'", $csp, 'form submission limited to the origin');
    assert_not_contains("'unsafe-inline'", $csp, 'no inline script or style allowed');
    assert_not_contains("'unsafe-eval'", $csp, 'eval forbidden');
});

test('the other hardening headers are present', function () use ($http) {
    $res = $http->get('/login.php');
    assert_eq('nosniff', $res->header('X-Content-Type-Options'), 'no type sniffing');
    assert_eq('no-referrer', $res->header('Referrer-Policy'), 'no referrer sent');
});

test('the headers are set on the API too', function () use ($http) {
    $csp = (string) $http->get('/api.php?id=!!')->header('Content-Security-Policy');
    assert_contains("default-src 'none'", $csp, 'API protected the same way');
});

test('nothing PHP serves may be kept in a cache', function () use ($http) {
    // The API's body is the ciphertext itself: cached, it outlives a burnt
    // secret in the reader's own disk. The inventory carries the addresses of
    // everyone who opened a link. Neither is worth keeping anywhere.
    $token = $http->csrfToken();
    $id = $http->createSecret([], $token)->json()['id'];
    foreach (['/api.php?id=' . $id, '/secrets.php', '/index.php', '/login.php'] as $path) {
        $res = $http->get($path);
        assert_contains('no-store', (string) $res->header('Cache-Control'), "$path may not be stored");
    }
});

// ---------------------------------------------------------------------------
// The French literals asserted below are the interface strings themselves: the
// HTTP client of lib.php requests Accept-Language: fr, so that the labels these
// tests expect do not depend on the server's fallback.
group('Session and authentication');

test('the shipped configuration requires authentication', function () {
    // Opening up is an explicit choice made by the operator: the repository
    // must never ship with creation available to everyone.
    assert_eq(true, config()['auth'], '"auth" key set to true in config.php');
});

test('the session cookie is HttpOnly and SameSite', function () use ($base) {
    $anon = new Http($base);
    $cookies = $anon->get('/login.php')->headerAll('Set-Cookie');
    assert_true($cookies !== [], 'a session cookie is issued');
    $c = implode(' ', $cookies);
    assert_contains('HttpOnly', $c, 'unreachable from JavaScript');
    assert_contains('SameSite=Lax', $c, 'protected against cross-site requests');
    assert_contains('path=/', strtolower($c), 'explicit scope');
});

test('a wrong password opens no session', function () use ($base, $user) {
    $anon = new Http($base);
    $page = $anon->get('/login.php')->body;
    preg_match('/name="csrf" value="([^"]+)"/', $page, $m);
    $res = $anon->post('/login.php', ['csrf' => $m[1], 'username' => $user, 'password' => 'wrong']);
    assert_eq(200, $res->status, 'back to the form, no redirect');
    assert_contains('Identifiants incorrects', $res->body, 'failure message shown');
    assert_eq(302, $anon->get('/index.php')->status, 'access to creation still refused');
});

test('a nonexistent account is rejected exactly like a wrong password', function () use ($base) {
    $anon = new Http($base);
    $page = $anon->get('/login.php')->body;
    preg_match('/name="csrf" value="([^"]+)"/', $page, $m);
    $res = $anon->post('/login.php', ['csrf' => $m[1], 'username' => 'nobody', 'password' => 'x']);
    assert_contains('Identifiants incorrects', $res->body, 'same message: no account enumeration');
});

test('signing in without a CSRF token is refused', function () use ($base, $user, $pass) {
    $anon = new Http($base);
    $anon->get('/login.php');
    $res = $anon->post('/login.php', ['username' => $user, 'password' => $pass]);
    assert_contains('Session expirée', $res->body, 'form rejected without a token');
    assert_eq(302, $anon->get('/index.php')->status, 'no session opened');
});

test('the session identifier changes on sign-in (anti-fixation)', function () use ($base, $user, $pass) {
    $anon = new Http($base);
    $before = $anon->get('/login.php')->header('Set-Cookie');
    preg_match('/stashbin=([^;]+)/', (string) $before, $m1);
    preg_match('/name="csrf" value="([^"]+)"/', $anon->get('/login.php')->body, $mc);
    $res = $anon->post('/login.php', ['csrf' => $mc[1], 'username' => $user, 'password' => $pass]);
    preg_match('/stashbin=([^;]+)/', (string) $res->header('Set-Cookie'), $m2);
    assert_true(isset($m1[1], $m2[1]), 'two session identifiers observed');
    assert_true($m1[1] !== $m2[1], 'identifier regenerated after authentication');
});

test('signing out closes the session', function () use ($base, $user, $pass) {
    $c = new Http($base);
    $c->login($user, $pass);
    assert_eq(200, $c->get('/index.php')->status, 'session open');
    $c->get('/logout.php');
    assert_eq(302, $c->get('/index.php')->status, 'session closed');
});

test('the creation page is unreachable without a session', function () use ($base) {
    $anon = new Http($base);
    $res = $anon->get('/index.php');
    assert_eq(302, $res->status, 'redirect');
    assert_contains('login.php', (string) $res->header('Location'), 'to the sign-in page');
});

// ---------------------------------------------------------------------------
group('The server cannot read the secrets');

test('the payload is stored as-is, with no decryption possible', function () use ($http, $token) {
    $marker = 'PLAINTEXTTHATMUSTNEVERAPPEAR';
    $payload = ['v' => 1, 'iv' => 'AAAA', 'salt' => 'BBBB', 'iter' => 310000, 'pwd' => 0,
                'ct' => base64_encode($marker)];
    $id = $http->createSecret(['payload' => $payload], $token)->json()['id'];

    $stored = db()->query('SELECT payload FROM pastes WHERE id = ' . db()->quote($id))->fetchColumn();
    // The server keeps the JSON container unchanged: it decodes nothing,
    // derives no key, and has no way of obtaining the plaintext.
    assert_eq($payload, json_decode((string) $stored, true), 'payload kept without transformation');
    assert_not_contains($marker, (string) $stored, 'no server-side decryption');
});

test('no column keeps a key or a password', function () {
    $cols = array_column(db()->query('PRAGMA table_info(pastes)')->fetchAll(), 'name');
    foreach (['key', 'urlkey', 'password', 'passphrase', 'plaintext'] as $forbidden) {
        assert_true(!in_array($forbidden, $cols, true), "\"$forbidden\" column absent from the schema");
    }
});

test('the deletion token is stored hashed, never in the clear', function () use ($http, $token) {
    $d = $http->createSecret([], $token)->json();
    $stored = db()->query('SELECT delete_hash FROM pastes WHERE id = ' . db()->quote($d['id']))->fetchColumn();
    assert_true($stored !== $d['delete_token'], 'the raw token is not in the database');
    assert_eq(hash('sha256', $d['delete_token']), $stored, 'SHA-256 digest recorded');
});

test('account passwords are hashed with a recognised algorithm', function () {
    $hash = (string) db()->query('SELECT pass_hash FROM users LIMIT 1')->fetchColumn();
    assert_matches('/^\$(2y|argon2i?d?)\$/', $hash, 'bcrypt or argon2, never plaintext or MD5');
    $info = password_get_info($hash);
    assert_true($info['algo'] !== null && $info['algo'] !== '0', 'algorithm recognised by PHP');
});

// ---------------------------------------------------------------------------
group('The inventory belongs to its owner alone');

// A second account, to make sure that the list is partitioned rather than
// merely filtered on screen.
$intruderName = 'intrus-inventaire';
$intruderPass = 'motdepasseintrus';
db()->prepare('DELETE FROM users WHERE username = ?')->execute([$intruderName]);
db()->prepare('INSERT INTO users (username, pass_hash, created) VALUES (?, ?, ?)')
    ->execute([$intruderName, password_hash($intruderPass, PASSWORD_DEFAULT), time()]);

$intruder = new Http($base);
$intruder->login($intruderName, $intruderPass);

test('the inventory demands a session', function () use ($base) {
    $anon = new Http($base);
    $res = $anon->get('/secrets.php');
    assert_eq(302, $res->status, 'anonymous visitor turned away');
    assert_contains('login.php', (string) $res->header('Location'), 'sent to sign in');
});

test('a signed-in user sees their own secrets and nobody else\'s', function () use ($http, $token, $intruder) {
    // The identifier is all an entry carries, so it is what the partitioning
    // has to keep out of a stranger's page.
    $mine = $http->createSecret([], $token)->json()['id'];

    assert_contains($mine, $http->get('/secrets.php')->body, 'the owner finds their secret');
    assert_not_contains($mine, $intruder->get('/secrets.php')->body, 'somebody else does not');
});

test('a signed-in user cannot delete a secret that is not theirs', function () use ($http, $token, $intruder) {
    // Deleting from the list is the owner's right; the deletion token is the
    // token holder's. Holding neither grants nothing.
    $id = $http->createSecret([], $token)->json()['id'];

    $page = $intruder->get('/secrets.php')->body;
    preg_match('/name="csrf" value="([^"]+)"/', $page, $m);
    $intruder->post('/secrets.php', ['csrf' => $m[1] ?? '', 'id' => $id, 'action' => 'delete']);

    assert_eq(200, $http->get('/api.php?id=' . $id)->status, 'the secret is still readable');
    $gone = db()->query('SELECT gone FROM pastes WHERE id = ' . db()->quote($id))->fetchColumn();
    assert_eq(null, $gone, 'and still considered live');
});

test('a signed-in user cannot erase somebody else\'s history', function () use ($http, $token, $intruder) {
    $id = $http->createSecret([], $token)->json()['id'];
    db()->prepare("UPDATE pastes SET gone = ?, gone_cause = 'deleted' WHERE id = ?")->execute([time(), $id]);

    $page = $intruder->get('/secrets.php')->body;
    preg_match('/name="csrf" value="([^"]+)"/', $page, $m);
    $intruder->post('/secrets.php', ['csrf' => $m[1] ?? '', 'id' => $id, 'action' => 'forget']);

    $left = db()->query('SELECT COUNT(*) FROM pastes WHERE id = ' . db()->quote($id))->fetchColumn();
    assert_eq(1, (int) $left, 'the entry stays in its owner\'s history');
});

test('clearing the history spares live secrets', function () use ($http, $token) {
    // The sweep takes away entries, never ciphertext: a secret still readable
    // has no business disappearing from the list that watches it.
    $alive = $http->createSecret(['expire' => 'never'], $token)->json()['id'];
    $spent = $http->createSecret(['burn' => true], $token)->json()['id'];
    $http->get('/api.php?id=' . $spent);

    $page = $http->get('/secrets.php')->body;
    preg_match('/name="csrf" value="([^"]+)"/', $page, $m);
    $http->post('/secrets.php', ['csrf' => $m[1] ?? '', 'action' => 'clear']);

    assert_eq(200, $http->get('/api.php?id=' . $alive)->status, 'the live secret is untouched');
    $left = db()->query('SELECT COUNT(*) FROM pastes WHERE id = ' . db()->quote($alive))->fetchColumn();
    assert_eq(1, (int) $left, 'and still listed');
    $swept = db()->query('SELECT COUNT(*) FROM pastes WHERE id = ' . db()->quote($spent))->fetchColumn();
    assert_eq(0, (int) $swept, 'the finished one is gone');
});

test('clearing one history leaves everybody else\'s alone', function () use ($http, $token, $intruder) {
    $mine = $http->createSecret([], $token)->json()['id'];
    db()->prepare("UPDATE pastes SET gone = ?, gone_cause = 'expired' WHERE id = ?")->execute([time(), $mine]);

    $page = $intruder->get('/secrets.php')->body;
    preg_match('/name="csrf" value="([^"]+)"/', $page, $m);
    $intruder->post('/secrets.php', ['csrf' => $m[1] ?? '', 'action' => 'clear']);

    $left = db()->query('SELECT COUNT(*) FROM pastes WHERE id = ' . db()->quote($mine))->fetchColumn();
    assert_eq(1, (int) $left, 'a stranger sweeping their own history clears none of mine');
});

test('the inventory never puts a ciphertext on the page', function () use ($http, $token) {
    // The list needs dates, states and one flag; the payload it has no use for.
    // Selecting it "just in case" would read hundreds of ciphertexts into
    // memory and put them one HTML mistake away from being displayed.
    $marker = 'Q0lQSEVSVEVYVEVOQ0xBSVI';
    $http->createSecret(['payload' => ['v' => 1, 'iv' => 'AAAA', 'salt' => 'BBBB', 'iter' => 310000,
                                       'pwd' => 1, 'ct' => $marker]], $token);
    $page = $http->get('/secrets.php')->body;
    assert_not_contains($marker, $page, 'no ciphertext reaches the page');
    assert_contains('Mot de passe', $page, 'though the flag it carries is read');
});

test('the inventory refuses a write without a CSRF token', function () use ($http, $token) {
    // The session cookie is SameSite=Lax, so another site can drive a POST at
    // top level: the token is what stops it.
    $id = $http->createSecret([], $token)->json()['id'];
    $http->post('/secrets.php', ['id' => $id, 'action' => 'delete']);
    $gone = db()->query('SELECT gone FROM pastes WHERE id = ' . db()->quote($id))->fetchColumn();
    assert_eq(null, $gone, 'nothing deleted without the token');
});

test('the access log records the address the server saw, not the one claimed', function () use ($http, $token) {
    // X-Forwarded-For is written by whoever sends the request: trusting it
    // would let a reader dictate what the log says about them.
    $id = $http->createSecret([], $token)->json()['id'];
    $http->request('GET', '/api.php?id=' . $id, null, ['X-Forwarded-For' => '203.0.113.66']);
    $ip = db()->query('SELECT ip FROM paste_reads WHERE paste_id = ' . db()->quote($id))->fetchColumn();
    assert_true($ip !== '203.0.113.66', 'the claimed address is not what gets recorded');
});

// ---------------------------------------------------------------------------
group('Injection');

test('an identifier containing SQL does not disturb the query', function () use ($http) {
    // The format filter rejects before the prepared statement is even reached:
    // two barriers.
    $res = $http->get('/api.php?id=' . urlencode("' OR 1=1 --"));
    assert_eq(400, $res->status, 'injection attempt rejected');
    assert_true((int) db()->query('SELECT COUNT(*) FROM pastes')->fetchColumn() >= 0, 'table intact');
});

test('a username containing SQL does not disturb sign-in', function () use ($base) {
    $anon = new Http($base);
    preg_match('/name="csrf" value="([^"]+)"/', $anon->get('/login.php')->body, $m);
    $res = $anon->post('/login.php', [
        'csrf' => $m[1], 'username' => "' OR '1'='1", 'password' => 'x',
    ]);
    assert_contains('Identifiants incorrects', $res->body, 'no session opened by injection');
    assert_true((int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0, 'users table intact');
});

test('the username is escaped when displayed', function () use ($base) {
    // An account with a booby-trapped name must not inject script into index.php.
    $name = '<img src=x onerror=alert(1)>';
    $hash = password_hash('testpassword', PASSWORD_DEFAULT);
    db()->prepare('INSERT OR REPLACE INTO users (username, pass_hash, created) VALUES (?,?,?)')
        ->execute([$name, $hash, time()]);

    $c = new Http($base);
    $c->login($name, 'testpassword');
    $page = $c->get('/index.php')->body;
    assert_not_contains('<img src=x', $page, 'tag not interpreted');
    assert_contains('&lt;img src=x', $page, 'displayed escaped');

    db()->prepare('DELETE FROM users WHERE username = ?')->execute([$name]);
});

// ---------------------------------------------------------------------------
group('Choosing the language opens nothing');

test('a "lang" parameter that is a path loads no file', function () use ($http) {
    foreach (['../config', '/etc/passwd', 'fr/../../config', '../../../../etc/passwd'] as $lang) {
        $res = $http->get('/index.php?lang=' . rawurlencode($lang));
        assert_eq(200, $res->status, "\"$lang\" does not break the page");
        assert_not_contains('STASHBIN_DB', $res->body, "\"$lang\" does not leak the configuration");
        assert_contains('lang="fr"', $res->body, "\"$lang\" does not override the requested language");
    }
});

test('a language we cannot serve yields English, never an empty dictionary', function () use ($base) {
    // The fallback must be a real language: served empty, the interface would
    // show its key identifiers on screen.
    // login.php rather than index.php: the client has no session, and a
    // redirect would return no body to inspect.
    $silent = new Http($base, language: null);
    foreach ([null, 'de, es', 'xx-yy'] as $header) {
        $res = $silent->request('GET', '/login.php', null, $header === null ? [] : ['Accept-Language' => $header]);
        assert_contains('lang="en"', $res->body, 'English served for want of better');
        assert_contains('Sign in', $res->body, 'labels really translated');
        assert_not_contains('login.submit', $res->body, 'no raw key displayed');
        assert_not_contains('login.intro', $res->body, 'no raw key displayed');
    }
});

test('a booby-trapped "lang" parameter is not echoed raw into the page', function () use ($http) {
    // The value comes back in the "lang" attribute and in the links the page
    // builds: it must arrive there escaped, or not at all.
    $res = $http->get('/index.php?lang=' . rawurlencode('"><script>alert(1)</script>'));
    assert_not_contains('<script>alert(1)', $res->body, 'no script injected');
});

test('a hostile Accept-Language header does not disturb the page', function () use ($http) {
    foreach (['<script>alert(1)</script>', '../../etc/passwd', str_repeat('fr,', 500) . 'fr'] as $header) {
        $res = $http->request('GET', '/index.php', null, ['Accept-Language' => $header]);
        assert_eq(200, $res->status, 'page served despite an aberrant header');
        assert_not_contains('<script>alert(1)', $res->body, 'no script injected');
    }
});

test('the pages announce that their content depends on the language', function () use ($http) {
    // Without "Vary", an intermediate cache would serve everyone the first
    // visitor's language — including on the sign-in page.
    foreach (['/index.php', '/view.php?id=nonexistent', '/api.php?id=' . str_repeat('z', 16)] as $path) {
        $res = $http->get($path);
        assert_contains('Accept-Language', (string) $res->header('Vary'), "\"$path\" sets the Vary header");
    }
});

test('the language changes neither the encryption nor what the server keeps', function () use ($http, $token) {
    // Translation is presentation: it must touch nothing the product promises.
    $payload = ['v' => 1, 'iv' => 'SVYxMjM', 'salt' => 'U0VMMTIz', 'iter' => 310000, 'pwd' => 0, 'ct' => 'Q0lQSEVS'];
    $id = $http->createSecret(['payload' => $payload], $token)->json()['id'];
    $got = $http->request('GET', '/api.php?id=' . $id . '&lang=en', null, ['Accept-Language' => 'en'])->json();
    assert_eq($payload, $got['payload'], 'payload returned unchanged in English');

    $stored = db()->query('SELECT payload FROM pastes WHERE id = ' . db()->quote($id))->fetchColumn();
    assert_eq($payload, json_decode((string) $stored, true), 'database unchanged');
});

exit(summary('Security regressions'));
