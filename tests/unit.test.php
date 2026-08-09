<?php
// Unit tests of the functions in src/bootstrap.php, without going through HTTP.
declare(strict_types=1);

require __DIR__ . '/lib.php';

// Isolated database: config() memoises on its first call, so the variable must
// be set before bootstrap.php is loaded.
//
// A file of its own, whatever the instance under test runs on: what follows
// reads the schema through PRAGMA, so the connection details an instance may
// have been started with are dropped here. The engine-independent half of that
// work is checked further down, and the suites that go through HTTP are the
// ones running against the configured engine.
$dbPath = sys_get_temp_dir() . '/stashbin-unit-' . getmypid() . '.sqlite';
putenv("STASHBIN_DB=$dbPath");
foreach (['DRIVER', 'HOST', 'PORT', 'NAME', 'USER', 'PASS', 'SOCKET', 'CHARSET'] as $detail) {
    putenv("STASHBIN_DB_$detail");
}
register_shutdown_function(static function () use ($dbPath) {
    foreach ([$dbPath, "$dbPath-wal", "$dbPath-shm"] as $f) {
        @unlink($f);
    }
});

require dirname(__DIR__) . '/src/bootstrap.php';

// ---------------------------------------------------------------------------
group('HTML escaping — e()');

test('escapes angle brackets and the ampersand', function () {
    assert_eq('&lt;script&gt;alert(1)&lt;/script&gt;', e('<script>alert(1)</script>'), 'tag neutralised');
    assert_eq('a &amp;&amp; b', e('a && b'), 'ampersand escaped');
});

test('escapes single and double quotes', function () {
    // ENT_QUOTES: without it, an attribute value would be injectable.
    assert_eq('&quot;', e('"'), 'double quote');
    assert_eq('&#039;', e("'"), 'apostrophe');
});

test('preserves valid UTF-8', function () {
    assert_eq('éàü — 🔐', e('éàü — 🔐'), 'accents and emoji intact');
});

test('returns an empty string on invalid UTF-8 (no ENT_SUBSTITUTE)', function () {
    // Current behaviour, documented: htmlspecialchars() is called without
    // ENT_SUBSTITUTE, so an invalid sequence empties the output instead of
    // being replaced by U+FFFD.
    assert_eq('', e("\xC3\x28"), 'invalid UTF-8 sequence');
});

test('lets text without special characters through', function () {
    assert_eq('alice', e('alice'), 'neutral text unchanged');
});

// ---------------------------------------------------------------------------
group('Configuration — config()');

test('exposes the expected keys', function () {
    $c = config();
    foreach (['db', 'auth', 'max_size', 'expirations', 'default_expiration', 'session_name'] as $k) {
        assert_true(array_key_exists($k, $c), "\"$k\" key present");
    }
});

test('honours the STASHBIN_DB environment variable', function () {
    assert_eq(getenv('STASHBIN_DB'), config()['db'], 'database path overridden');
});

test('offers the five lifetimes, "never" being unlimited', function () {
    $e = config()['expirations'];
    assert_eq(['1h', '1d', '1w', '1m', 'never'], array_keys($e), 'set of lifetimes');
    assert_eq(3600, $e['1h'], 'one hour');
    assert_eq(86400, $e['1d'], 'one day');
    assert_eq(604800, $e['1w'], 'one week');
    assert_eq(2592000, $e['1m'], 'one month');
    assert_eq(null, $e['never'], '"never" is null, not 0');
});

test('the default lifetime is one of the lifetimes on offer', function () {
    assert_true(
        array_key_exists(config()['default_expiration'], config()['expirations']),
        'default_expiration points at an existing lifetime'
    );
});

test('memoises: two calls return the same array', function () {
    assert_eq(config(), config(), 'configuration stable');
});

// ---------------------------------------------------------------------------
group('Optional authentication — auth_enabled()');

test('requires authentication as long as nothing disables it', function () {
    assert_eq(true, config()['auth'], '"auth" key true by default');
    assert_true(auth_enabled(), 'auth_enabled() follows the configuration');
});

test('is_authorized() requires an open session when authentication is on', function () {
    assert_true(!is_authorized(), 'no session, hence no right to create');
});

// ---------------------------------------------------------------------------
group('Environment overrides — env_overrides()');

// config.php holds nothing but literal values: env_overrides() is what decides
// whether the environment wins, and under what conditions.
$base = ['db' => '/chemin/du/fichier.sqlite', 'auth' => true];

test('STASHBIN_AUTH disables authentication on a negative value', function () use ($base) {
    try {
        foreach (['0', 'false', 'off', 'no', 'FALSE', 'Off'] as $value) {
            putenv("STASHBIN_AUTH=$value");
            assert_eq(false, env_overrides($base)['auth'], "\"$value\" disables authentication");
        }
    } finally {
        putenv('STASHBIN_AUTH');
    }
});

test('any other STASHBIN_AUTH value leaves authentication on', function () use ($base) {
    // The trap: "0" is falsy in PHP, so a "?:" on the file's value would drag
    // precisely the case meant to disable back to true.
    try {
        foreach (['1', 'true', 'on', 'aye', 'yes', 'maybe'] as $value) {
            putenv("STASHBIN_AUTH=$value");
            assert_eq(true, env_overrides($base)['auth'], "\"$value\" leaves authentication on");
        }
    } finally {
        putenv('STASHBIN_AUTH');
    }
});

test('an absent or empty variable overrides nothing', function () {
    $file = ['db' => '/valeur/du/fichier.sqlite', 'auth' => false];
    $saved = getenv('STASHBIN_DB');
    try {
        putenv('STASHBIN_DB');
        putenv('STASHBIN_AUTH');
        assert_eq($file, env_overrides($file), 'file values kept');
        putenv('STASHBIN_DB=');
        putenv('STASHBIN_AUTH=');
        assert_eq($file, env_overrides($file), 'an empty variable does not overwrite the file');
    } finally {
        putenv('STASHBIN_AUTH');
        putenv(is_string($saved) ? "STASHBIN_DB=$saved" : 'STASHBIN_DB');
    }
});

test('STASHBIN_TRUST_PROXY follows the same rules as STASHBIN_AUTH', function () use ($base) {
    try {
        foreach (['0', 'false', 'off', 'no', 'FALSE'] as $value) {
            putenv("STASHBIN_TRUST_PROXY=$value");
            assert_eq(false, env_overrides($base)['trust_proxy'] ?? null, "\"$value\" trusts nothing");
        }
        foreach (['1', 'true', 'on', 'yes'] as $value) {
            putenv("STASHBIN_TRUST_PROXY=$value");
            assert_eq(true, env_overrides($base)['trust_proxy'] ?? null, "\"$value\" trusts the proxy");
        }
        putenv('STASHBIN_TRUST_PROXY');
        assert_true(!array_key_exists('trust_proxy', env_overrides($base)), 'absent, it decides nothing');
    } finally {
        putenv('STASHBIN_TRUST_PROXY');
    }
});

test('STASHBIN_DB replaces the database path', function () use ($base) {
    $saved = getenv('STASHBIN_DB');
    try {
        putenv('STASHBIN_DB=/ailleurs/base.sqlite');
        assert_eq('/ailleurs/base.sqlite', env_overrides($base)['db'], 'path overridden');
    } finally {
        putenv(is_string($saved) ? "STASHBIN_DB=$saved" : 'STASHBIN_DB');
    }
});

// ---------------------------------------------------------------------------
group('Choosing an engine — db_settings() / load_db_driver()');

// The setting used to be a path and nothing else. It still may be: an instance
// happy with SQLite must never have to learn the array form.
test('a bare path means SQLite and that file', function () {
    $db = db_settings('/var/lib/stashbin/base.sqlite');
    assert_eq('sqlite', $db['driver'], 'SQLite unless told otherwise');
    assert_eq('/var/lib/stashbin/base.sqlite', $db['path'], 'the path is kept');
});

test('an array says which engine, and keeps the connection details', function () {
    $db = db_settings(['driver' => 'MariaDB', 'host' => 'db.internal', 'name' => 'stashbin']);
    assert_eq('mariadb', $db['driver'], 'the name is compared in lower case');
    assert_eq('db.internal', $db['host'], 'host kept');
    assert_eq('stashbin', $db['name'], 'database name kept');
});

test('an array without a driver is SQLite too', function () {
    assert_eq('sqlite', db_settings(['path' => '/tmp/x.sqlite'])['driver'], 'the default holds');
});

test('normalising twice changes nothing', function () {
    // db_settings() is applied wherever the setting is read, and env_overrides()
    // applies it again on top of what config.php holds.
    $once = db_settings('/tmp/x.sqlite');
    assert_eq($once, db_settings($once), 'stable');
});

test('every file in src/db/ is an engine on offer', function () {
    $drivers = available_db_drivers();
    assert_true(in_array('sqlite', $drivers, true), 'SQLite present');
    assert_true(in_array('mysql', $drivers, true), 'MySQL present');
});

test('"mariadb" and "mysql" lead to the same driver', function () {
    // One protocol, one PDO driver, one dialect: two files would drift apart.
    assert_eq('mysql', db_driver_file('mariadb'), 'MariaDB is served by the MySQL driver');
    assert_eq(
        load_db_driver('mysql')['sequence'],
        load_db_driver('mariadb')['sequence'],
        'the same file either way'
    );
});

test('an unknown engine stops everything rather than starting without storage', function () {
    assert_throws(fn () => load_db_driver('postgres'), 'an engine with no file is refused');
    // The name becomes a file path: a traversal must not find its way there.
    assert_throws(fn () => load_db_driver('../config'), 'a path is not an engine name');
});

test('each driver offers what the queries ask of it', function () {
    foreach (available_db_drivers() as $name) {
        $driver = load_db_driver($name);
        foreach (['connect', 'migrate', 'json_bool', 'integer', 'sequence'] as $key) {
            assert_true(array_key_exists($key, $driver), "$name: \"$key\"");
        }
        assert_true(is_callable($driver['json_bool']), "$name: json_bool is built from a column");
        assert_true($driver['sequence'] !== '', "$name: an insertion order to break a date tie");
    }
});

test('the SQL fragments name the column they are given', function () {
    // They are interpolated into a query, not bound to it: what they are handed
    // has to come out whole, and nothing else has any business reaching them.
    assert_contains('p.payload', sql('json_bool', 'p.payload', '$.pwd'), 'the column is used');
    assert_contains('$.pwd', sql('json_bool', 'p.payload', '$.pwd'), 'and the JSON path too');
    assert_contains('?', sql('integer', '?'), 'the placeholder survives the cast');
    assert_eq('rowid', sql('sequence'), 'SQLite orders by rowid');
});

test('the connection details travel through the environment', function () {
    // config.php is mounted read-only in a container: without these variables
    // there would be no way to point an instance at a database server.
    $file = ['db' => '/data/stashbin.sqlite'];
    try {
        putenv('STASHBIN_DB_DRIVER=mariadb');
        putenv('STASHBIN_DB_HOST=db.internal');
        putenv('STASHBIN_DB_PORT=3307');
        putenv('STASHBIN_DB_NAME=secrets');
        putenv('STASHBIN_DB_USER=stashbin');
        putenv('STASHBIN_DB_PASS=hunter2');
        $db = env_overrides($file)['db'];
        assert_eq('mariadb', $db['driver'], 'engine named by the environment');
        assert_eq('db.internal', $db['host'], 'host');
        assert_eq('3307', $db['port'], 'port');
        assert_eq('secrets', $db['name'], 'database name');
        assert_eq('stashbin', $db['user'], 'account');
        assert_eq('hunter2', $db['pass'], 'password');
        // A container image sets STASHBIN_DB whatever the engine: the path is
        // still taken, and simply means nothing to an engine that reads no
        // file. What it must never do is drag the instance back onto SQLite.
        assert_eq(getenv('STASHBIN_DB'), $db['path'], 'the path is kept, and left unused');
    } finally {
        foreach (['DRIVER', 'HOST', 'PORT', 'NAME', 'USER', 'PASS'] as $detail) {
            putenv("STASHBIN_DB_$detail");
        }
    }
});

// ---------------------------------------------------------------------------
group('Database — db()');

test('creates the expected schema', function () {
    $tables = db()->query(
        "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"
    )->fetchAll(PDO::FETCH_COLUMN);
    assert_true(in_array('users', $tables, true), 'users table created');
    assert_true(in_array('pastes', $tables, true), 'pastes table created');
});

test('the pastes table carries the expected columns', function () {
    $cols = array_column(db()->query('PRAGMA table_info(pastes)')->fetchAll(), 'name');
    $expected = ['id', 'payload', 'burn', 'delete_hash', 'created', 'expires', 'owner_id', 'gone', 'gone_cause'];
    foreach ($expected as $col) {
        assert_true(in_array($col, $cols, true), "\"$col\" column");
    }
});

test('the access log has its own table and index', function () {
    $cols = array_column(db()->query('PRAGMA table_info(paste_reads)')->fetchAll(), 'name');
    foreach (['paste_id', 'at', 'outcome', 'ip', 'agent'] as $col) {
        assert_true(in_array($col, $cols, true), "\"$col\" column");
    }
    $indexes = db()->query("SELECT name FROM sqlite_master WHERE type='index'")->fetchAll(PDO::FETCH_COLUMN);
    assert_true(in_array('paste_reads_by_paste', $indexes, true), 'log indexed by secret');
});

test('turns on the WAL journal and foreign keys', function () {
    assert_eq('wal', strtolower((string) db()->query('PRAGMA journal_mode')->fetchColumn()), 'WAL journal');
    assert_eq(1, (int) db()->query('PRAGMA foreign_keys')->fetchColumn(), 'foreign keys on');
});

test('enforces username uniqueness', function () {
    db()->prepare('INSERT INTO users (username, pass_hash, created) VALUES (?, ?, ?)')
        ->execute(['doublon', 'x', time()]);
    assert_throws(
        fn () => db()->prepare('INSERT INTO users (username, pass_hash, created) VALUES (?, ?, ?)')
                     ->execute(['doublon', 'y', time()]),
        'a second account with the same name is refused'
    );
});

test('returns native integers, not strings', function () {
    // Since PHP 8.1, PDO_SQLite returns native types: api.php compares
    // "expires" to time() without casting, and depends on it.
    db()->prepare('INSERT INTO pastes (id, payload, burn, delete_hash, created, expires) VALUES (?,?,?,?,?,?)')
        ->execute(['typecheck', '{}', 0, 'h', 1000, 2000]);
    $row = db()->query("SELECT created, expires FROM pastes WHERE id='typecheck'")->fetch();
    assert_true(is_int($row['created']), 'created is an integer');
    assert_true(is_int($row['expires']), 'expires is an integer');
});

// ---------------------------------------------------------------------------
group('Purging expired secrets — purge_expired()');

$seed = static function (string $id, ?int $expires): void {
    db()->prepare('INSERT INTO pastes (id, payload, burn, delete_hash, created, expires) VALUES (?,?,?,?,?,?)')
        ->execute([$id, '{}', 0, 'hash', time(), $expires]);
};
$exists = static fn (string $id): bool
    => (bool) db()->query("SELECT 1 FROM pastes WHERE id='" . $id . "'")->fetchColumn();

test('deletes a secret whose date has passed', function () use ($seed, $exists) {
    $seed('purge-passe', time() - 60);
    purge_expired();
    assert_true(!$exists('purge-passe'), 'expired secret removed');
});

test('keeps a secret that is still valid', function () use ($seed, $exists) {
    $seed('purge-futur', time() + 3600);
    purge_expired();
    assert_true($exists('purge-futur'), 'unexpired secret kept');
});

test('keeps a secret with no expiry', function () use ($seed, $exists) {
    $seed('purge-jamais', null);
    purge_expired();
    assert_true($exists('purge-jamais'), '"never" survives the purge');
});

test('keeps a secret expiring on the very second', function () use ($seed, $exists) {
    // The condition is "expires < time()": the exact instant must not purge.
    $seed('purge-limite', time());
    purge_expired();
    assert_true($exists('purge-limite'), 'the bound is exclusive');
});

test('an owned secret leaves a headstone instead of a hole', function () use ($exists) {
    // Its creator has a list to look at: the row stays so that it can say what
    // became of the secret, stripped of the ciphertext.
    db()->prepare('INSERT INTO users (username, pass_hash, created) VALUES (?, ?, ?)')
        ->execute(['purge-owner', 'x', time()]);
    $owner = (int) db()->lastInsertId();
    db()->prepare('INSERT INTO pastes (id, payload, burn, delete_hash, created, expires, owner_id) VALUES (?,?,?,?,?,?,?)')
        ->execute(['purge-possede', '{"ct":"SECRET"}', 0, 'hash', time() - 120, time() - 60, $owner]);

    purge_expired();

    assert_true($exists('purge-possede'), 'the row survives its expiry');
    $row = db()->query("SELECT payload, gone, gone_cause FROM pastes WHERE id='purge-possede'")->fetch();
    assert_eq('', $row['payload'], 'ciphertext wiped');
    assert_eq('expired', $row['gone_cause'], 'cause recorded');
    assert_true(is_int($row['gone']), 'date of disappearance recorded');
});

// ---------------------------------------------------------------------------
group('Schema migration — migrate_schema()');

// A database created before the inventory existed must gain what it lacks
// without losing what it holds: these run against a temporary file carrying the
// original schema, since the live database has already been migrated.
$legacy = static function (): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        pass_hash TEXT NOT NULL,
        created INTEGER NOT NULL
    )');
    $pdo->exec('CREATE TABLE pastes (
        id TEXT PRIMARY KEY,
        payload TEXT NOT NULL,
        burn INTEGER NOT NULL DEFAULT 0,
        delete_hash TEXT NOT NULL,
        created INTEGER NOT NULL,
        expires INTEGER
    )');
    return $pdo;
};

test('adds the missing columns to an older database', function () use ($legacy) {
    $pdo = $legacy();
    migrate_schema($pdo);
    $cols = array_column($pdo->query('PRAGMA table_info(pastes)')->fetchAll(), 'name');
    foreach (['owner_id', 'gone', 'gone_cause'] as $col) {
        assert_true(in_array($col, $cols, true), "\"$col\" added");
    }
});

test('creates the access log an older database never had', function () use ($legacy) {
    $pdo = $legacy();
    migrate_schema($pdo);
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    assert_true(in_array('paste_reads', $tables, true), 'paste_reads created');
});

test('the secrets already stored survive the migration', function () use ($legacy) {
    // The whole point: an instance in production migrates without losing the
    // links its users have already handed out.
    $pdo = $legacy();
    $pdo->prepare('INSERT INTO pastes (id, payload, burn, delete_hash, created, expires) VALUES (?,?,?,?,?,?)')
        ->execute(['vieux-secret', '{"ct":"INTACT"}', 1, 'empreinte', 1000, null]);

    migrate_schema($pdo);

    $row = $pdo->query("SELECT * FROM pastes WHERE id='vieux-secret'")->fetch();
    assert_eq('{"ct":"INTACT"}', $row['payload'], 'payload untouched');
    assert_eq(1, (int) $row['burn'], 'burn flag untouched');
    assert_eq(null, $row['owner_id'], 'no owner invented for it');
    assert_eq(null, $row['gone'], 'still considered live');
});

test('migrating a second time changes nothing', function () use ($legacy) {
    // db() calls it on every request: it has to be harmless once it is done.
    $pdo = $legacy();
    migrate_schema($pdo);
    $before = $pdo->query('PRAGMA table_info(pastes)')->fetchAll();
    migrate_schema($pdo);
    assert_eq($before, $pdo->query('PRAGMA table_info(pastes)')->fetchAll(), 'schema unchanged');
});

// ---------------------------------------------------------------------------
group('Chosen expiry — custom_expiry()');

$now = 1_700_000_000;

test('accepts an instant in the future', function () use ($now) {
    assert_eq($now + 3600, custom_expiry($now + 3600, $now), 'timestamp returned as given');
});

test('accepts a numeric string, since JSON is not always typed', function () use ($now) {
    assert_eq($now + 60, custom_expiry((string) ($now + 60), $now), 'digits accepted');
});

test('refuses an instant already past, and the present second', function () use ($now) {
    // Not corrected, refused: a secret outliving what its author asked for is
    // the one mistake here that cannot be undone.
    assert_eq(null, custom_expiry($now - 1, $now), 'a second ago');
    assert_eq(null, custom_expiry($now, $now), 'the very second');
});

test('refuses a mistyped year rather than storing it', function () use ($now) {
    // The bound is the end of year 9999, which is what a date input can hold:
    // a guard against a slip of the keyboard, not a policy — "never" is offered
    // in the list above the field.
    assert_eq(null, custom_expiry(253402300800, $now), 'past year 9999');
    assert_true(custom_expiry(253402300799, $now) !== null, 'the bound itself is fine');
});

test('refuses anything that is not a whole number of seconds', function () use ($now) {
    foreach ([null, true, 1.5, '12abc', '', [], '2026-08-05T18:30', $now . '.5'] as $bad) {
        assert_eq(null, custom_expiry($bad, $now), 'rejected: ' . var_export($bad, true));
    }
});

// ---------------------------------------------------------------------------
group('Claiming a single-use secret — claim_burned()');

test('the first caller wins and the others are told nothing is left', function () {
    db()->prepare('INSERT INTO pastes (id, payload, burn, delete_hash, created, expires, owner_id) VALUES (?,?,?,?,?,?,?)')
        ->execute(['claim-possede', '{"ct":"X"}', 1, 'h', time(), null, 1]);

    assert_true(claim_burned('claim-possede'), 'the first claim succeeds');
    assert_true(!claim_burned('claim-possede'), 'the second finds nothing to claim');
    assert_true(!claim_burned('claim-possede'), 'and so does every one after it');

    $row = db()->query("SELECT payload, gone_cause FROM pastes WHERE id='claim-possede'")->fetch();
    assert_eq('', $row['payload'], 'the ciphertext is gone with the first claim');
    assert_eq('burned', $row['gone_cause'], 'and the cause is recorded once');
});

test('a secret nobody owns is claimed by removing it', function () {
    db()->prepare('INSERT INTO pastes (id, payload, burn, delete_hash, created, expires) VALUES (?,?,?,?,?,?)')
        ->execute(['claim-orphelin', '{"ct":"X"}', 1, 'h', time(), null]);

    assert_true(claim_burned('claim-orphelin'), 'the first claim succeeds');
    assert_true(!claim_burned('claim-orphelin'), 'the row is gone, so nothing is left to claim');
    $left = db()->query("SELECT COUNT(*) FROM pastes WHERE id='claim-orphelin'")->fetchColumn();
    assert_eq(0, (int) $left, 'removed outright, having no list to appear in');
});

test('an identifier that does not exist is claimed by nobody', function () {
    assert_true(!claim_burned('claim-inexistant'), 'no row, no claim');
});

// ---------------------------------------------------------------------------
group('Behind a proxy — pick_client_ip() / pick_https()');

// Pure functions, and deliberately so: X-Forwarded-* is written by whoever
// sends the request, and every combination of trusted and forged has to be
// checked without standing up a proxy to do it.

test('an untrusted X-Forwarded-For is ignored, whatever it claims', function () {
    // Believing it would let a reader choose what the access log says about
    // them — the whole point of the log being to say who came.
    assert_eq('192.0.2.7', pick_client_ip('192.0.2.7', '203.0.113.9', false), 'the address the server saw');
    assert_eq('192.0.2.7', pick_client_ip('192.0.2.7', '203.0.113.9, 198.51.100.1', false), 'a chain changes nothing');
});

test('a trusted proxy names the reader, not itself', function () {
    // Without this, every access behind a TLS terminator is logged as coming
    // from the proxy, and the log says nothing at all.
    assert_eq('203.0.113.9', pick_client_ip('10.0.0.2', '203.0.113.9', true), 'the client, first in the chain');
    assert_eq('203.0.113.9', pick_client_ip('10.0.0.2', ' 203.0.113.9 , 10.0.0.1 ', true), 'spacing and proxies ignored');
});

test('a forged X-Forwarded-For falls back rather than being recorded', function () {
    foreach (['pas-une-adresse', '', '<script>', '999.999.999.999'] as $forged) {
        assert_eq('10.0.0.2', pick_client_ip('10.0.0.2', $forged, true), "rejected: \"$forged\"");
    }
});

test('no address at all is null, never an empty string', function () {
    assert_eq(null, pick_client_ip(null, null, false), 'nothing to record');
    assert_eq(null, pick_client_ip('', null, true), 'an empty REMOTE_ADDR is not an address');
});

test('HTTPS is recognised however the web server spells it', function () {
    assert_true(pick_https('on', null, false), '"on"');
    assert_true(pick_https('1', null, false), '"1"');
    assert_true(!pick_https('off', null, false), '"off" means plain HTTP');
    assert_true(!pick_https(null, null, false), 'absent means plain HTTP');
});

test('X-Forwarded-Proto counts only from a trusted proxy', function () {
    // This is what marks the session cookie Secure. Believed unconditionally,
    // any client could claim its own connection was encrypted.
    assert_true(!pick_https(null, 'https', false), 'ignored when no proxy is declared');
    assert_true(pick_https(null, 'https', true), 'honoured when one is');
    assert_true(pick_https(null, ' HTTPS ', true), 'case and spacing forgiven');
    assert_true(!pick_https(null, 'http', true), 'and it has to actually say https');
});

// ---------------------------------------------------------------------------
group('Dates — utc_stamp()');

test('renders a date in UTC, whatever the server thinks its timezone is', function () {
    // The server cannot know where the reader is, so what it writes is UTC and
    // says so: the browser is what turns it into local time afterwards.
    $previous = date_default_timezone_get();
    date_default_timezone_set('Pacific/Kiritimati');
    $rendered = utc_stamp(1_700_000_000);
    date_default_timezone_set($previous);
    assert_eq('2023-11-14 22:13 UTC', $rendered, 'UTC, minute precision, named');
});

test('time_tag() carries the instant for the script and a date for everyone else', function () {
    // Two renderings of one instant: the attribute the browser rewrites from,
    // and the text a reader sees when no script runs.
    $html = time_tag(1_700_000_000);
    assert_contains('datetime="2023-11-14T22:13:20+00:00"', $html, 'machine-readable instant');
    assert_contains('data-stamp="1700000000"', $html, 'seconds for the script');
    assert_contains('>2023-11-14 22:13 UTC<', $html, 'and a readable fallback');
});

test('time_tag() renders a missing date as a dash, not as an empty element', function () {
    assert_eq(e(t('secrets.unknown')), time_tag(null), 'nothing to convert, nothing to mark up');
});

test('renders a missing date as a dash rather than as 1970', function () {
    assert_eq(t('secrets.unknown'), utc_stamp(null), 'no date invented');
});

// ---------------------------------------------------------------------------
group('CSRF tokens — csrf_token() / check_csrf()');

test('produces a 256-bit token in hexadecimal', function () {
    $t = csrf_token();
    assert_matches('/^[0-9a-f]{64}$/', $t, '64-character hexadecimal token');
});

test('stays stable for the duration of the session', function () {
    assert_eq(csrf_token(), csrf_token(), 'token constant within one session');
});

test('accepts the current token', function () {
    assert_true(check_csrf(csrf_token()), 'valid token accepted');
});

test('refuses a wrong, empty or truncated token', function () {
    assert_true(!check_csrf('mauvais'), 'arbitrary token refused');
    assert_true(!check_csrf(''), 'empty token refused');
    assert_true(!check_csrf(substr(csrf_token(), 0, 63)), 'truncated token refused');
});

test('refuses a token differing by a single character', function () {
    $t = csrf_token();
    $altered = $t[0] === 'a' ? 'b' . substr($t, 1) : 'a' . substr($t, 1);
    assert_true(!check_csrf($altered), 'strict comparison');
});

// ---------------------------------------------------------------------------
group('Language — reading Accept-Language');

test('sorts tags by decreasing quality', function () {
    assert_eq(
        ['de', 'en', 'fr'],
        parse_accept_language('fr;q=0.3, en;q=0.7, de'),
        'quality outranks the order written'
    );
});

test('keeps the header order at equal quality', function () {
    assert_eq(['en', 'fr', 'de'], parse_accept_language('en, fr, de'), 'implicit preference respected');
});

test('accepts whitespace and any case', function () {
    assert_eq(['en-us', 'fr'], parse_accept_language('  EN-US , FR ; Q=0.5 '), 'normalised to lowercase');
});

test('discards a tag of zero quality', function () {
    assert_eq(['fr'], parse_accept_language('en;q=0, fr'), 'q=0 means "definitely not"');
});

test('ignores anything not shaped like a tag', function () {
    assert_eq(['fr'], parse_accept_language('*, <script>, 1;;;, fr'), 'unreadable entries discarded');
    assert_eq([], parse_accept_language(''), 'empty header');
});

// ---------------------------------------------------------------------------
group('Language — negotiation');

test('picks the first available language', function () {
    assert_eq('en', negotiate_locale('en;q=0.9, fr;q=0.2', null, ['en', 'fr'], 'fr'), 'English preferred');
    assert_eq('fr', negotiate_locale('fr, en', null, ['en', 'fr'], 'en'), 'French preferred');
});

test('reduces a regional tag to its language', function () {
    assert_eq('fr', negotiate_locale('fr-CA', null, ['en', 'fr'], 'en'), 'fr-CA served as fr');
    assert_eq('en', negotiate_locale('en-GB;q=0.9, de;q=0.8', null, ['en', 'fr'], 'fr'), 'en-GB served as en');
});

test('skips languages we cannot serve', function () {
    assert_eq('en', negotiate_locale('de, es, en', null, ['en', 'fr'], 'fr'), 'first known language kept');
});

test('falls back when nothing matches', function () {
    assert_eq('fr', negotiate_locale('de, es', null, ['en', 'fr'], 'fr'), 'falls back to the default language');
    assert_eq('fr', negotiate_locale(null, null, ['en', 'fr'], 'fr'), 'no header at all');
});

test('a language we cannot serve yields English', function () {
    // This is the shipped setting: default_locale is "en".
    assert_eq('en', fallback_locale(), 'fallback configured to English');
    assert_eq('en', negotiate_locale('de, es, it', null, available_locales(), fallback_locale()), 'none of them matches');
    assert_eq('en', negotiate_locale(null, null, available_locales(), fallback_locale()), 'no header at all');
});

test('the reference language stays French, whatever the fallback serves', function () {
    // The two roles are distinct: "en" is served for want of better, "fr"
    // supplies the keys a partial translation lacks.
    assert_eq('fr', reference_locale(), 'fr remains the reference');
    assert_true(fallback_locale() !== reference_locale(), 'the two are no longer conflated');
});

test('the explicit parameter outranks the header', function () {
    assert_eq('en', negotiate_locale('fr', 'en', ['en', 'fr'], 'fr'), 'the visitor\'s choice takes priority');
});

test('an unknown explicit parameter does not derail negotiation', function () {
    assert_eq('en', negotiate_locale('en', 'klingon', ['en', 'fr'], 'fr'), 'we fall back to the header');
    assert_eq('fr', negotiate_locale('de', '../fr', ['en', 'fr'], 'fr'), 'no path ever becomes a language');
});

// ---------------------------------------------------------------------------
group('Language — dictionaries');

test('French and English are on offer', function () {
    assert_true(in_array('fr', available_locales(), true), 'fr present');
    assert_true(in_array('en', available_locales(), true), 'en present');
});

test('every language on offer translates all the reference keys', function () {
    // fr.php is the reference: a key missing from it would have no fallback.
    foreach (available_locales() as $locale) {
        $missing = array_diff(array_keys(load_lang(reference_locale())), array_keys(load_lang($locale)));
        assert_eq([], array_values($missing), "untranslated keys in \"$locale\": " . implode(', ', $missing));
    }
});

test('no translation leaves an orphan placeholder', function () {
    // A {placeholder} present on one side and absent on the other would show
    // stray text, or lose the data it was meant to carry.
    $fr = load_lang(reference_locale());
    foreach (load_lang('en') as $key => $text) {
        preg_match_all('/\{(\w+)\}/', $fr[$key] ?? '', $expected);
        preg_match_all('/\{(\w+)\}/', $text, $actual);
        sort($expected[1]);
        sort($actual[1]);
        assert_eq($expected[1], $actual[1], "placeholders of \"$key\"");
    }
});

test('a language name that is a path reads no file', function () {
    // The name becomes a path, and "require" executes what it finds:
    // "../../config" points at the repository's config.php, which would then
    // be served as a dictionary. No caller can lead there today — locale()
    // only returns languages that are actually listed — but the function does
    // not rely on its sole caller to stay safe.
    assert_eq([], load_lang('../../config'), 'climbing up to the configuration refused');
    assert_eq([], load_lang('fr/../../../config'), 'traversal refused');
    assert_eq([], load_lang('inexistante'), 'unknown language: empty dictionary');
});

// ---------------------------------------------------------------------------
group('Language — translation');

test('returns the string for the key asked for', function () {
    // Outside an HTTP request no language is requested: the fallback serves.
    assert_eq('not found', t('error.not_found'), 'key translated in the fallback language');
});

test('replaces the placeholders with the values given', function () {
    assert_eq('Error: panne', t('js.error', ['error' => 'panne']), 'placeholder substituted');
});

test('returns the key itself when it does not exist', function () {
    // Visible without being fatal: the page stays readable and the gap is obvious.
    assert_eq('cle.inexistante', t('cle.inexistante'), 'key returned as-is');
});

test('t_html escapes the translation before inserting HTML into it', function () {
    // An absent key comes back as itself, which gives a subject holding the two
    // placeholders without tying this test to a string the interface may
    // reword.
    $out = t_html('{user} & {logout}', [
        '{user}' => '<strong>alice</strong>',
        '{logout}' => '<a href="logout.php">sortir</a>',
    ]);
    assert_contains('<strong>alice</strong>', $out, 'fragment inserted as-is');
    assert_contains('<a href="logout.php">sortir</a>', $out, 'link inserted as-is');
    assert_contains('&amp;', $out, 'the translation itself is escaped');
});

test('t_html neutralises HTML coming from the translation', function () {
    // A translation is data like any other: it must not be able to introduce a
    // tag, even when dropped in by an inattentive operator.
    assert_eq('cle.&lt;script&gt;', t_html('cle.<script>'), 'tag escaped');
});

test('the strings published to the JavaScript are JSON without the "js." prefix', function () {
    $published = json_decode(client_strings(), true);
    assert_true(is_array($published), 'valid JSON');
    assert_eq('Encrypting…', $published['encrypting'] ?? null, 'key stripped of its prefix');
    assert_true(!isset($published['js.encrypting']), 'prefix removed');
    assert_true(!isset($published['error.not_found']), 'only the "js." keys are published');
});

// ---------------------------------------------------------------------------
group('Documentation — bilingual README');

// Two READMEs always end up diverging if nothing watches them. What can be
// checked mechanically is the structure: a section added on one side and not
// the other is the real case, and it is caught here. The content itself
// remains the reviewer's responsibility.
function readme_headings(string $file): array
{
    $headings = [];
    $inFence = false;
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        // Lines inside a code block may start with "#" without being
        // headings: the shell comments in the examples are full of them.
        if (str_starts_with($line, '```')) {
            $inFence = !$inFence;
            continue;
        }
        if ($inFence || !preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
            continue;
        }
        // The level and the emoji identify the section without depending on
        // the language: the heading text is precisely what gets translated.
        preg_match('/^([^\p{L}\p{N}]*)/u', trim($m[2]), $icon);
        $headings[] = strlen($m[1]) . ':' . trim($icon[1]);
    }
    return $headings;
}

test('both READMEs exist and point at each other', function () {
    $root = dirname(__DIR__);
    assert_true(is_file("$root/README.md"), 'README.md (English) present');
    assert_true(is_file("$root/README.fr.md"), 'README.fr.md (French) present');
    assert_contains('README.fr.md', file_get_contents("$root/README.md"), 'the English one points at the French');
    assert_contains('README.md', file_get_contents("$root/README.fr.md"), 'the French one points at the English');
});

test('both READMEs have the same section structure', function () {
    $root = dirname(__DIR__);
    $en = readme_headings("$root/README.md");
    $fr = readme_headings("$root/README.fr.md");
    assert_true($en !== [], 'headings were actually found');
    assert_eq(
        $fr,
        $en,
        "structure diverges between README.md and README.fr.md\n"
        . "(level:emoji, in order — a section was added, removed or moved on one side only)"
    );
});

test('both READMEs announce the same test count', function () {
    // A figure updated on one side only is the easiest drift to commit, and
    // the most visible to a reader.
    $root = dirname(__DIR__);
    $count = static function (string $file): array {
        preg_match_all('/(\d+)\s+tests/', file_get_contents($file), $m);
        return array_unique($m[1]);
    };
    assert_eq($count("$root/README.fr.md"), $count("$root/README.md"), 'same counts announced');
});

exit(summary('Unit tests'));
