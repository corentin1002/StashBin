<?php
// Business rules of the API, tested over HTTP against the real application.
// The tests run inside the container: they can therefore compare the HTTP
// response with what is actually written to the database.
declare(strict_types=1);

require __DIR__ . '/lib.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$base = getenv('STASHBIN_URL') ?: 'http://127.0.0.1';
$user = getenv('STASHBIN_USER') ?: 'tester';
$pass = getenv('STASHBIN_PASS') ?: 'testpassword';

$http = new Http($base);
$token = $http->login($user, $pass);

// ---------------------------------------------------------------------------
group('Creation — authentication and CSRF');

test('an anonymous visitor cannot create a secret (401)', function () use ($base) {
    $anon = new Http($base);
    $res = $anon->post('/api.php', json_encode(['payload' => []]), ['Content-Type' => 'application/json']);
    assert_eq(401, $res->status, 'creation refused without a session');
    assert_eq('unauthorized', $res->json()['code'], 'stable code, independent of the language');
});

test('a session without a CSRF token is refused (403)', function () use ($http) {
    $res = $http->post('/api.php', json_encode(['payload' => []]), ['Content-Type' => 'application/json']);
    assert_eq(403, $res->status, 'CSRF required even when authenticated');
});

test('a wrong CSRF token is refused (403)', function () use ($http) {
    $res = $http->createSecret([], str_repeat('0', 64));
    assert_eq(403, $res->status, 'invalid CSRF token rejected');
});

test('a valid creation returns 201, an identifier and a deletion token', function () use ($http, $token) {
    $res = $http->createSecret([], $token);
    assert_eq(201, $res->status, 'creation accepted');
    $d = $res->json();
    assert_matches('/^[A-Za-z0-9_-]{16}$/', $d['id'], '16-character base64url identifier');
    assert_matches('/^[A-Za-z0-9_-]{24}$/', $d['delete_token'], '24-character deletion token');
});

test('two successive creations produce distinct identifiers', function () use ($http, $token) {
    $a = $http->createSecret([], $token)->json()['id'];
    $b = $http->createSecret([], $token)->json()['id'];
    assert_true($a !== $b, 'identifiers not repeated');
});

// ---------------------------------------------------------------------------
group('Creation — payload validation');

foreach (['v', 'iv', 'salt', 'iter', 'pwd', 'ct'] as $field) {
    test("a payload without \"$field\" is refused (400)", function () use ($http, $token, $field) {
        $payload = ['v' => 1, 'iv' => 'A', 'salt' => 'B', 'iter' => 310000, 'pwd' => 0, 'ct' => 'C'];
        unset($payload[$field]);
        $res = $http->createSecret(['payload' => $payload], $token);
        assert_eq(400, $res->status, "the \"$field\" field is mandatory");
        assert_eq('incomplete_payload', $res->json()['code'], 'stable code, independent of the language');
    });
}

test('a body that is not JSON is refused (400)', function () use ($http, $token) {
    $res = $http->post('/api.php', 'this is not json', [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $token,
    ]);
    assert_eq(400, $res->status, 'invalid JSON rejected');
});

test('a JSON body without a "payload" key is refused (400)', function () use ($http, $token) {
    $res = $http->createSecret(['raw' => ['expire' => '1h']], $token);
    assert_eq(400, $res->status, 'payload is mandatory');
});

test('a "payload" that is not an object is refused (400)', function () use ($http, $token) {
    $res = $http->createSecret(['payload' => 'a string'], $token);
    assert_eq(400, $res->status, 'scalar payload rejected');
});

test('content larger than max_size is refused (413)', function () use ($http, $token) {
    $max = config()['max_size'];
    $res = $http->createSecret([
        'payload' => ['v' => 1, 'iv' => 'A', 'salt' => 'B', 'iter' => 310000, 'pwd' => 0,
                      'ct' => str_repeat('Q', $max + 5000)],
    ], $token);
    assert_eq(413, $res->status, 'size limit enforced');
});

test('large content that stays under the limit is accepted', function () use ($http, $token) {
    $res = $http->createSecret([
        'payload' => ['v' => 1, 'iv' => 'A', 'salt' => 'B', 'iter' => 310000, 'pwd' => 0,
                      'ct' => str_repeat('Q', 512 * 1024)],
    ], $token);
    assert_eq(201, $res->status, '512 KiB accepted');
});

// ---------------------------------------------------------------------------
group('Creation — lifetimes');

test('an unknown lifetime is refused (400)', function () use ($http, $token) {
    $res = $http->createSecret(['expire' => '42centuries'], $token);
    assert_eq(400, $res->status, 'nonexistent lifetime key rejected');
    assert_eq('unknown_expiration', $res->json()['code'], 'stable code, independent of the language');
});

foreach (['1h' => 3600, '1d' => 86400, '1w' => 604800, '1m' => 2592000] as $key => $seconds) {
    test("\"$key\" sets an expiry at +$seconds seconds", function () use ($http, $token, $key, $seconds) {
        $before = time();
        $id = $http->createSecret(['expire' => $key], $token)->json()['id'];
        $stored = db()->query('SELECT expires FROM pastes WHERE id = ' . db()->quote($id))->fetchColumn();
        assert_true(is_int($stored), 'expiry stored as an integer');
        // One second of tolerance: the request may straddle a clock tick.
        assert_true(
            abs($stored - ($before + $seconds)) <= 1,
            "expiry expected around " . ($before + $seconds) . ", got $stored"
        );
    });
}

test('"never" records no expiry at all', function () use ($http, $token) {
    $id = $http->createSecret(['expire' => 'never'], $token)->json()['id'];
    $stored = db()->query('SELECT expires FROM pastes WHERE id = ' . db()->quote($id))->fetchColumn();
    assert_eq(null, $stored, 'expires column is null');
});

test('with nothing specified, the configured default lifetime applies', function () use ($http, $token) {
    $before = time();
    $id = $http->createSecret([], $token)->json()['id'];
    $stored = db()->query('SELECT expires FROM pastes WHERE id = ' . db()->quote($id))->fetchColumn();
    $expected = config()['expirations'][config()['default_expiration']];
    assert_true(abs($stored - ($before + $expected)) <= 1, 'default lifetime applied');
});

// ---------------------------------------------------------------------------
group('Reading');

test('a malformed identifier is refused (400)', function () use ($http) {
    // The filter is /^[A-Za-z0-9_-]{8,32}$/: too short, too long, or containing
    // a character outside the base64url alphabet.
    foreach (['!!', 'short', str_repeat('a', 33), '../../etc/passwd', 'ab cd efg', ''] as $bad) {
        $res = $http->get('/api.php?id=' . urlencode($bad));
        assert_eq(400, $res->status, "identifier \"$bad\" rejected");
    }
});

test('a well-formed but unknown identifier returns 404, not 400', function () use ($http) {
    // The distinction matters: a 400 on a valid string would reveal that the
    // format filter and existence in the database have been conflated.
    $res = $http->get('/api.php?id=' . str_repeat('a', 16));
    assert_eq(404, $res->status, 'correct format, nonexistent secret');
});

test('a nonexistent identifier returns 404', function () use ($http) {
    $res = $http->get('/api.php?id=' . str_repeat('z', 16));
    assert_eq(404, $res->status, 'secret not found');
    assert_eq('not_found', $res->json()['code'], 'stable code, independent of the language');
});

test('reading back returns the payload unchanged', function () use ($http, $token) {
    $payload = ['v' => 1, 'iv' => 'SVYxMjM', 'salt' => 'U0VMMTIz', 'iter' => 310000, 'pwd' => 0, 'ct' => 'Q0lQSEVSVEVYVA'];
    $id = $http->createSecret(['payload' => $payload], $token)->json()['id'];
    $got = $http->get('/api.php?id=' . $id)->json();
    assert_eq($payload, $got['payload'], 'payload identical to what was sent');
    assert_eq(false, $got['burn'], 'burn flag false');
});

test('reading requires no session', function () use ($http, $token, $base) {
    $id = $http->createSecret([], $token)->json()['id'];
    $anon = new Http($base);
    assert_eq(200, $anon->get('/api.php?id=' . $id)->status, 'public reading');
});

test('an expired secret returns 404 and its ciphertext is wiped on the spot', function () use ($http, $token) {
    // The row itself stays: it belongs to an account, whose list has to be able
    // to say the secret expired rather than let it vanish without a word.
    $id = $http->createSecret(['expire' => '1h'], $token)->json()['id'];
    db()->prepare('UPDATE pastes SET expires = ? WHERE id = ?')->execute([time() - 1, $id]);
    assert_eq(404, $http->get('/api.php?id=' . $id)->status, 'expired secret unreachable');
    $row = db()->query('SELECT payload, gone_cause FROM pastes WHERE id = ' . db()->quote($id))->fetch();
    assert_eq('', $row['payload'], 'ciphertext wiped without waiting for the next purge');
    assert_eq('expired', $row['gone_cause'], 'cause recorded');
});

test('creating a secret purges the remaining expired ones', function () use ($http, $token) {
    db()->prepare('INSERT INTO pastes (id, payload, burn, delete_hash, created, expires) VALUES (?,?,?,?,?,?)')
        ->execute(['purgeparapi00000', '{}', 0, 'h', time() - 100, time() - 50]);
    $http->createSecret([], $token);
    $left = db()->query("SELECT COUNT(*) FROM pastes WHERE id = 'purgeparapi00000'")->fetchColumn();
    assert_eq(0, (int) $left, 'purge triggered on creation');
});

// ---------------------------------------------------------------------------
group('Burn after reading');

test('a read-once secret disappears after its first read', function () use ($http, $token) {
    $id = $http->createSecret(['burn' => true], $token)->json()['id'];
    $first = $http->get('/api.php?id=' . $id);
    assert_eq(200, $first->status, 'first read served');
    assert_eq(true, $first->json()['burn'], 'burn flag passed on');
    assert_eq(404, $http->get('/api.php?id=' . $id)->status, 'second read impossible');
});

test('the "meta" probe does not consume the secret', function () use ($http, $token) {
    $id = $http->createSecret(['burn' => true], $token)->json()['id'];
    $meta = $http->get('/api.php?id=' . $id . '&meta=1');
    assert_eq(200, $meta->status, 'probe served');
    assert_eq(true, $meta->json()['burn'], 'burn announced');
    assert_true(!isset($meta->json()['payload']), 'the probe does not leak the payload');
    assert_eq(200, $http->get('/api.php?id=' . $id)->status, 'secret still readable after the probe');
});

test('an ordinary secret survives several reads', function () use ($http, $token) {
    $id = $http->createSecret(['burn' => false], $token)->json()['id'];
    for ($i = 0; $i < 3; $i++) {
        assert_eq(200, $http->get('/api.php?id=' . $id)->status, 'read ' . ($i + 1));
    }
});

// ---------------------------------------------------------------------------
group('Deletion link');

test('deletes the secret with a valid token and an open session', function () use ($http, $token) {
    $d = $http->createSecret([], $token)->json();
    $res = $http->get('/api.php?id=' . $d['id'] . '&delete=' . $d['delete_token']);
    assert_eq(200, $res->status, 'deletion accepted');
    assert_eq(true, $res->json()['deleted'], 'confirmation returned');
    assert_eq(404, $http->get('/api.php?id=' . $d['id'])->status, 'secret really deleted');
});

test('a wrong token is refused (403) and the secret survives', function () use ($http, $token) {
    $d = $http->createSecret([], $token)->json();
    $res = $http->get('/api.php?id=' . $d['id'] . '&delete=' . str_repeat('x', 24));
    assert_eq(403, $res->status, 'invalid deletion token');
    assert_eq(200, $http->get('/api.php?id=' . $d['id'])->status, 'secret intact');
});

test('an anonymous visitor holding the right token is sent to the sign-in page', function () use ($http, $token, $base) {
    $d = $http->createSecret([], $token)->json();
    $anon = new Http($base);
    $res = $anon->get('/api.php?id=' . $d['id'] . '&delete=' . $d['delete_token']);
    assert_eq(302, $res->status, 'redirect to login.php');
    assert_contains('login.php', (string) $res->header('Location'), 'redirect target');
    assert_eq(200, $http->get('/api.php?id=' . $d['id'])->status, 'secret not deleted by the anonymous visitor');
});

// ---------------------------------------------------------------------------
group('Title, ownership and access log');

test('a secret belongs to whoever created it', function () use ($http, $token, $user) {
    $id = $http->createSecret([], $token)->json()['id'];
    $owner = db()->query('SELECT username FROM users u JOIN pastes p ON p.owner_id = u.id
                          WHERE p.id = ' . db()->quote($id))->fetchColumn();
    assert_eq($user, $owner, 'creator recorded');
});

test('the title is stored in the clear, as sent', function () use ($http, $token) {
    // It is the one part of a secret the server can read, and deliberately so:
    // without it, a list of identifiers would be useless to its owner.
    $id = $http->createSecret(['title' => 'Accès Postgres — prod'], $token)->json()['id'];
    $stored = db()->query('SELECT title FROM pastes WHERE id = ' . db()->quote($id))->fetchColumn();
    assert_eq('Accès Postgres — prod', $stored, 'title stored verbatim');
});

test('a secret with no title stores nothing rather than an empty string', function () use ($http, $token) {
    $id = $http->createSecret([], $token)->json()['id'];
    $stored = db()->query('SELECT title FROM pastes WHERE id = ' . db()->quote($id))->fetchColumn();
    assert_eq(null, $stored, 'no title, no value');
});

test('an oversized title is refused (400) and nothing is created', function () use ($http, $token) {
    $before = (int) db()->query('SELECT COUNT(*) FROM pastes')->fetchColumn();
    $res = $http->createSecret(['title' => str_repeat('é', config()['title_max'] + 1)], $token);
    assert_eq(400, $res->status, 'title over the limit rejected');
    assert_eq('title_too_long', $res->json()['code'], 'stable code');
    $after = (int) db()->query('SELECT COUNT(*) FROM pastes')->fetchColumn();
    assert_eq($before, $after, 'refusal creates nothing');
});

test('a title of exactly the maximum length is accepted', function () use ($http, $token) {
    // The limit counts characters, not bytes: an accented title of the right
    // length must not be refused for weighing more.
    $title = str_repeat('é', config()['title_max']);
    $res = $http->createSecret(['title' => $title], $token);
    assert_eq(201, $res->status, 'the bound itself is allowed');
    $stored = db()->query('SELECT title FROM pastes WHERE id = ' . db()->quote($res->json()['id']))->fetchColumn();
    assert_eq($title, $stored, 'stored whole');
});

test('reading a secret records the date, the address and the browser', function () use ($http, $token) {
    $id = $http->createSecret([], $token)->json()['id'];
    $http->request('GET', '/api.php?id=' . $id, null, ['User-Agent' => 'SuiteDeTests/1.0']);

    $log = db()->query('SELECT * FROM paste_reads WHERE paste_id = ' . db()->quote($id))->fetchAll();
    assert_eq(1, count($log), 'one access recorded');
    assert_eq('served', $log[0]['outcome'], 'payload served');
    assert_eq('SuiteDeTests/1.0', $log[0]['agent'], 'browser recorded');
    assert_true($log[0]['ip'] !== null && $log[0]['ip'] !== '', 'address recorded');
    assert_true(abs($log[0]['at'] - time()) < 60, 'date recorded');
});

test('the "meta" probe on a live secret is not an access and is not recorded', function () use ($http, $token) {
    // It precedes the burn-after-reading confirmation: counting it would report
    // a read to the creator for a secret nobody has seen.
    $id = $http->createSecret(['burn' => true], $token)->json()['id'];
    $http->get('/api.php?id=' . $id . '&meta=1');
    $count = db()->query('SELECT COUNT(*) FROM paste_reads WHERE paste_id = ' . db()->quote($id))->fetchColumn();
    assert_eq(0, (int) $count, 'probe left no trace');
});

test('the same probe on a spent secret is recorded, being all the reader gets to send', function () use ($http, $token) {
    // view.php probes before anything else: a reader arriving after the secret
    // is gone never reaches the payload request. Ignoring the probe here would
    // make late arrivals invisible — which is the very thing the log is for.
    $id = $http->createSecret(['burn' => true], $token)->json()['id'];
    $http->get('/api.php?id=' . $id);
    $http->request('GET', '/api.php?id=' . $id . '&meta=1', null, ['User-Agent' => 'ApercuDeLien/1.0']);

    $log = db()->query('SELECT outcome, agent FROM paste_reads WHERE paste_id = ' . db()->quote($id)
                       . ' ORDER BY at, id')->fetchAll();
    assert_eq(2, count($log), 'the read and the late probe');
    assert_eq('gone', $log[1]['outcome'], 'recorded as a link found empty');
    assert_eq('ApercuDeLien/1.0', $log[1]['agent'], 'and attributed');
});

test('replaying the link of a spent secret is recorded as such', function () use ($http, $token) {
    // This is how a creator finds out that a chat application preloaded their
    // link, or that somebody came back to it too late.
    $id = $http->createSecret(['burn' => true], $token)->json()['id'];
    $http->get('/api.php?id=' . $id);
    $http->request('GET', '/api.php?id=' . $id, null, ['User-Agent' => 'TropTard/1.0']);

    $log = db()->query('SELECT outcome, agent FROM paste_reads WHERE paste_id = ' . db()->quote($id)
                       . ' ORDER BY at, id')->fetchAll();
    assert_eq(2, count($log), 'both attempts recorded');
    assert_eq('served', $log[0]['outcome'], 'the first was served');
    assert_eq('gone', $log[1]['outcome'], 'the second found nothing');
    assert_eq('TropTard/1.0', $log[1]['agent'], 'and we know what asked for it');
});

test('a burned secret leaves a headstone, not a hole', function () use ($http, $token) {
    $id = $http->createSecret(['burn' => true], $token)->json()['id'];
    $http->get('/api.php?id=' . $id);
    $row = db()->query('SELECT payload, gone, gone_cause FROM pastes WHERE id = ' . db()->quote($id))->fetch();
    assert_true($row !== false, 'the row survives its secret');
    assert_eq('', $row['payload'], 'ciphertext wiped');
    assert_eq('burned', $row['gone_cause'], 'destroyed by reading');
    assert_true(is_int($row['gone']), 'date of disappearance recorded');
});

test('deleting through the link leaves a headstone saying so', function () use ($http, $token) {
    $d = $http->createSecret([], $token)->json();
    $http->get('/api.php?id=' . $d['id'] . '&delete=' . $d['delete_token']);
    $row = db()->query('SELECT payload, gone_cause FROM pastes WHERE id = ' . db()->quote($d['id']))->fetch();
    assert_eq('', $row['payload'], 'ciphertext wiped');
    assert_eq('deleted', $row['gone_cause'], 'deleted, as opposed to read or expired');
});

test('a secret nobody owns is deleted outright, log and all', function () use ($http) {
    // Nothing would ever show it: keeping a headstone for an ownerless secret
    // would grow the table for no reader.
    db()->prepare('INSERT INTO pastes (id, payload, burn, delete_hash, created, expires) VALUES (?,?,?,?,?,?)')
        ->execute(['sansproprietaire0', '{"ct":"X"}', 1, 'h', time(), null]);
    assert_eq(200, $http->get('/api.php?id=sansproprietaire0')->status, 'read once');
    $left = db()->query("SELECT COUNT(*) FROM pastes WHERE id='sansproprietaire0'")->fetchColumn();
    assert_eq(0, (int) $left, 'row gone for good');
    $logged = db()->query("SELECT COUNT(*) FROM paste_reads WHERE paste_id='sansproprietaire0'")->fetchColumn();
    assert_eq(0, (int) $logged, 'nothing logged for a secret nobody can look at');
});

test('an identifier that never existed leaves no trace', function () use ($http) {
    $http->get('/api.php?id=' . str_repeat('q', 20));
    $logged = db()->query("SELECT COUNT(*) FROM paste_reads WHERE paste_id='" . str_repeat('q', 20) . "'")->fetchColumn();
    assert_eq(0, (int) $logged, 'no log line for a secret that never was');
});

// ---------------------------------------------------------------------------
group('HTTP methods');

foreach (['PUT', 'DELETE', 'PATCH'] as $method) {
    test("the $method method is refused (405)", function () use ($http, $method) {
        $res = $http->request($method, '/api.php');
        assert_eq(405, $res->status, "$method not allowed");
    });
}

// ---------------------------------------------------------------------------
group('Language of the response');

test('an error carries a stable code and a translated message', function () use ($http) {
    $res = $http->request('GET', '/api.php?id=' . str_repeat('z', 16), null, ['Accept-Language' => 'en']);
    assert_eq(404, $res->status, 'nonexistent secret');
    assert_eq('not_found', $res->json()['code'], 'the code does not change with the language');
    assert_eq('not found', $res->json()['error'], 'the message, by contrast, is translated');
});

test('without a language header, the API answers in the fallback language', function () use ($base) {
    // A client silent about language: default_locale decides, and it is "en".
    $silent = new Http($base, language: null);
    $res = $silent->get('/api.php?id=' . str_repeat('z', 16));
    assert_eq('not found', $res->json()['error'], 'falls back to English');
});

test('a language we cannot serve yields English', function () use ($http) {
    $res = $http->request('GET', '/api.php?id=' . str_repeat('z', 16), null, ['Accept-Language' => 'de, es;q=0.8']);
    assert_eq('not found', $res->json()['error'], 'neither German nor Spanish: English');
});

test('the requested language alters neither the status nor the code', function () use ($http) {
    foreach (['fr', 'en', 'de', ''] as $lang) {
        $res = $http->request('GET', '/api.php?id=!!!', null, $lang === '' ? [] : ['Accept-Language' => $lang]);
        assert_eq(400, $res->status, "invalid identifier, whatever the language (\"$lang\")");
        assert_eq('invalid_id', $res->json()['code'], "code unchanged (\"$lang\")");
    }
});

test('every response announces that it depends on the requested language', function () use ($http) {
    $res = $http->get('/api.php?id=' . str_repeat('z', 16));
    assert_contains('Accept-Language', (string) $res->header('Vary'), 'Vary header set');
});

exit(summary('API business rules'));
