<?php
// Unit tests of the functions in src/bootstrap.php, without going through HTTP.
declare(strict_types=1);

require __DIR__ . '/lib.php';

// Isolated database: config() memoises on its first call, so the variable must
// be set before bootstrap.php is loaded.
$dbPath = sys_get_temp_dir() . '/stashbin-unit-' . getmypid() . '.sqlite';
putenv("STASHBIN_DB=$dbPath");
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
    foreach (['id', 'payload', 'burn', 'delete_hash', 'created', 'expires'] as $col) {
        assert_true(in_array($col, $cols, true), "\"$col\" column");
    }
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
    $out = t_html('create.logged_in', [
        '{user}' => '<strong>alice</strong>',
        '{logout}' => '<a href="logout.php">sortir</a>',
    ]);
    assert_contains('<strong>alice</strong>', $out, 'fragment inserted as-is');
    assert_contains('<a href="logout.php">sortir</a>', $out, 'link inserted as-is');
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
