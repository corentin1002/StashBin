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
group('Storage — whichever engine the instance runs on');

// This suite is played against SQLite and against MariaDB, and the tests below
// are the ones that say which of the two answered: everything after them is
// written once and has to hold on both.
test('the configured engine is the one being talked to', function () {
    $configured = db_driver_file(db_settings(config()['db'])['driver']);
    assert_eq($configured, db()->getAttribute(PDO::ATTR_DRIVER_NAME), 'PDO driver in use');
});

test('the schema is created on first use', function () {
    // Portable on purpose: a query that fails on a missing table or a missing
    // column says more here than any engine's own catalogue would.
    foreach (['users', 'pastes', 'paste_reads'] as $table) {
        assert_true(
            (int) db()->query("SELECT COUNT(*) FROM $table")->fetchColumn() >= 0,
            "\"$table\" table present"
        );
    }
    assert_eq(9, db()->query('SELECT id, payload, burn, delete_hash, created, expires, owner_id,
                                     gone, gone_cause FROM pastes LIMIT 1')->columnCount(),
        'every column of a secret');
    assert_eq(6, db()->query('SELECT id, paste_id, at, outcome, ip, agent
                                FROM paste_reads LIMIT 1')->columnCount(),
        'every column of an access');
});

test('a secret comes back with the types the code compares without casting', function () use ($http, $token) {
    $id = $http->createSecret(['expire' => '1h'], $token)->json()['id'];
    $stmt = db()->prepare('SELECT created, expires, burn FROM pastes WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    assert_true(is_int($row['created']), 'created is an integer');
    assert_true(is_int($row['expires']), 'expires is an integer, which api.php compares to time()');
});

test('two identifiers differing only in case are two different secrets', function () {
    // A case-insensitive collation would serve one reader another's ciphertext:
    // identifiers are base64url, where "aB" and "Ab" have nothing to do with
    // each other.
    db()->prepare('INSERT INTO pastes (id, payload, burn, delete_hash, created, expires) VALUES (?,?,?,?,?,?)')
        ->execute(['CaseSensitive0', '{"ct":"UPPER"}', 0, 'h', time(), null]);
    $stmt = db()->prepare('SELECT payload FROM pastes WHERE id = ?');
    $stmt->execute(['casesensitive0']);
    assert_eq(false, $stmt->fetchColumn(), 'the lowercase identifier matches nothing');
    db()->prepare('DELETE FROM pastes WHERE id = ?')->execute(['CaseSensitive0']);
});

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

test('a chosen instant is stored as sent', function () use ($http, $token) {
    $at = time() + 12345;
    $id = $http->createSecret(['raw' => [
        'payload' => ['v' => 1, 'iv' => 'A', 'salt' => 'B', 'iter' => 310000, 'pwd' => 0, 'ct' => 'C'],
        'expire' => 'custom',
        'expires_at' => $at,
    ]], $token)->json()['id'];
    $stored = db()->query('SELECT expires FROM pastes WHERE id = ' . db()->quote($id))->fetchColumn();
    assert_eq($at, $stored, 'the instant asked for, to the second');
});

test('a chosen instant in the past is refused (400)', function () use ($http, $token) {
    $res = $http->createSecret(['raw' => [
        'payload' => ['v' => 1, 'iv' => 'A', 'salt' => 'B', 'iter' => 310000, 'pwd' => 0, 'ct' => 'C'],
        'expire' => 'custom',
        'expires_at' => time() - 60,
    ]], $token);
    assert_eq(400, $res->status, 'a date already past creates nothing');
    assert_eq('bad_expiry', $res->json()['code'], 'stable code');
});

test('"custom" without an instant is refused (400)', function () use ($http, $token) {
    // Silently falling back to the default lifetime would give the secret a
    // life its author never asked for.
    $res = $http->createSecret(['expire' => 'custom'], $token);
    assert_eq(400, $res->status, 'no instant, no secret');
    assert_eq('bad_expiry', $res->json()['code'], 'stable code');
});

test('a chosen instant beyond year 9999 is refused (400)', function () use ($http, $token) {
    $res = $http->createSecret(['raw' => [
        'payload' => ['v' => 1, 'iv' => 'A', 'salt' => 'B', 'iter' => 310000, 'pwd' => 0, 'ct' => 'C'],
        'expire' => 'custom',
        'expires_at' => 253402300800,
    ]], $token);
    assert_eq(400, $res->status, 'a mistyped year is refused');
});

test('a secret with a chosen expiry dies at that instant, like any other', function () use ($http, $token) {
    $id = $http->createSecret(['raw' => [
        'payload' => ['v' => 1, 'iv' => 'A', 'salt' => 'B', 'iter' => 310000, 'pwd' => 0, 'ct' => 'C'],
        'expire' => 'custom',
        'expires_at' => time() + 3600,
    ]], $token)->json()['id'];
    assert_eq(200, $http->get('/api.php?id=' . $id)->status, 'readable before its instant');

    db()->prepare('UPDATE pastes SET expires = ? WHERE id = ?')->execute([time() - 1, $id]);
    assert_eq(404, $http->get('/api.php?id=' . $id)->status, 'gone once past');
    $cause = db()->query('SELECT gone_cause FROM pastes WHERE id = ' . db()->quote($id))->fetchColumn();
    assert_eq('expired', $cause, 'and reported as expired, not as something else');
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

test('a single-use secret is served to exactly one of twenty simultaneous readers', function () use ($http, $token) {
    // Reading the row, serving it, then destroying it leaves a window a whole
    // request wide, and every reader arriving inside it used to receive a copy
    // of a secret promised to one: twenty readers, seventeen copies.
    //
    // The bodies are what is counted, not the statuses. A crash used to come
    // back as 200 with a stack trace inside, which is how this went unnoticed.
    $marker = 'Q0lQSEVSVU5JUVVF';
    $id = $http->createSecret(['burn' => true, 'payload' => [
        'v' => 1, 'iv' => 'A', 'salt' => 'B', 'iter' => 310000, 'pwd' => 0, 'ct' => $marker,
    ]], $token)->json()['id'];

    $responses = $http->parallelGet('/api.php?id=' . $id, 20);
    $served = array_filter($responses, static fn (array $r): bool => str_contains($r['body'], $marker));
    assert_eq(1, count($served), 'exactly one reader receives the secret');

    foreach ($responses as $r) {
        assert_true(!str_contains($r['body'], 'Fatal error'), 'no crash hiding behind a status');
        assert_true(!str_contains($r['body'], 'internal_error'), 'and none reported as a server error');
        assert_true(in_array($r['status'], [200, 404], true), "unexpected status {$r['status']}");
    }
});

test('the access log stops growing once a secret has been read its fill', function () use ($http, $token) {
    // Anyone holding a link can cause a row to be written, carrying 200
    // characters of their choosing — on a spent secret too, since a replayed
    // link is worth recording. Unbounded, that is a stranger filling the disk.
    $id = $http->createSecret([], $token)->json()['id'];
    $max = config()['access_log_max'];
    $seed = db()->prepare('INSERT INTO paste_reads (paste_id, at, outcome, ip, agent) VALUES (?, ?, ?, ?, ?)');
    for ($i = 0; $i < $max - 1; $i++) {
        $seed->execute([$id, time(), 'served', '10.0.0.1', 'seed']);
    }
    $count = static fn (): int => (int) db()->query(
        'SELECT COUNT(*) FROM paste_reads WHERE paste_id = ' . db()->quote($id)
    )->fetchColumn();

    $http->get('/api.php?id=' . $id);
    assert_eq($max, $count(), 'the one that fills the cap is still recorded');
    $http->get('/api.php?id=' . $id);
    $http->get('/api.php?id=' . $id);
    assert_eq($max, $count(), 'and nothing past it');
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
group('Ownership and access log');

test('a secret belongs to whoever created it', function () use ($http, $token, $user) {
    $id = $http->createSecret([], $token)->json()['id'];
    $owner = db()->query('SELECT username FROM users u JOIN pastes p ON p.owner_id = u.id
                          WHERE p.id = ' . db()->quote($id))->fetchColumn();
    assert_eq($user, $owner, 'creator recorded');
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
