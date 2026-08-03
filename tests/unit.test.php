<?php
// Tests unitaires des fonctions de src/bootstrap.php, sans passer par HTTP.
declare(strict_types=1);

require __DIR__ . '/lib.php';

// Base isolée : config() mémoïse à son premier appel, donc la variable doit
// être posée avant de charger bootstrap.php.
$dbPath = sys_get_temp_dir() . '/stashbin-unit-' . getmypid() . '.sqlite';
putenv("STASHBIN_DB=$dbPath");
register_shutdown_function(static function () use ($dbPath) {
    foreach ([$dbPath, "$dbPath-wal", "$dbPath-shm"] as $f) {
        @unlink($f);
    }
});

require dirname(__DIR__) . '/src/bootstrap.php';

// ---------------------------------------------------------------------------
group('Échappement HTML — e()');

test('échappe les chevrons et l\'esperluette', function () {
    assert_eq('&lt;script&gt;alert(1)&lt;/script&gt;', e('<script>alert(1)</script>'), 'balise neutralisée');
    assert_eq('a &amp;&amp; b', e('a && b'), 'esperluette échappée');
});

test('échappe les guillemets simples et doubles', function () {
    // ENT_QUOTES : sans lui, une valeur d'attribut serait injectable.
    assert_eq('&quot;', e('"'), 'guillemet double');
    assert_eq('&#039;', e("'"), 'apostrophe');
});

test('préserve l\'UTF-8 valide', function () {
    assert_eq('éàü — 🔐', e('éàü — 🔐'), 'accents et emoji intacts');
});

test('renvoie une chaîne vide sur de l\'UTF-8 invalide (ENT_SUBSTITUTE absent)', function () {
    // Comportement actuel documenté : htmlspecialchars() est appelé sans
    // ENT_SUBSTITUTE, donc une séquence invalide vide la sortie au lieu
    // d'être remplacée par U+FFFD.
    assert_eq('', e("\xC3\x28"), 'séquence UTF-8 invalide');
});

test('laisse passer un texte sans caractère spécial', function () {
    assert_eq('alice', e('alice'), 'texte neutre inchangé');
});

// ---------------------------------------------------------------------------
group('Configuration — config()');

test('expose les clés attendues', function () {
    $c = config();
    foreach (['db', 'auth', 'max_size', 'expirations', 'default_expiration', 'session_name'] as $k) {
        assert_true(array_key_exists($k, $c), "clé « $k » présente");
    }
});

test('honore la variable d\'environnement STASHBIN_DB', function () {
    assert_eq(getenv('STASHBIN_DB'), config()['db'], 'chemin de base surchargé');
});

test('propose les cinq durées de vie, « never » étant illimitée', function () {
    $e = config()['expirations'];
    assert_eq(['1h', '1d', '1w', '1m', 'never'], array_keys($e), 'jeu de durées');
    assert_eq(3600, $e['1h'], 'une heure');
    assert_eq(86400, $e['1d'], 'un jour');
    assert_eq(604800, $e['1w'], 'une semaine');
    assert_eq(2592000, $e['1m'], 'un mois');
    assert_eq(null, $e['never'], '« never » vaut null, pas 0');
});

test('la durée par défaut fait partie des durées proposées', function () {
    assert_true(
        array_key_exists(config()['default_expiration'], config()['expirations']),
        'default_expiration référence une durée existante'
    );
});

test('mémoïse : deux appels renvoient le même tableau', function () {
    assert_eq(config(), config(), 'configuration stable');
});

// ---------------------------------------------------------------------------
group('Authentification facultative — auth_enabled()');

test('exige l\'authentification tant que rien ne la désactive', function () {
    assert_eq(true, config()['auth'], 'clé « auth » à true par défaut');
    assert_true(auth_enabled(), 'auth_enabled() suit la configuration');
});

test('is_authorized() exige une session ouverte quand l\'authentification est active', function () {
    assert_true(!is_authorized(), 'aucune session, donc aucun droit de créer');
});

// ---------------------------------------------------------------------------
group('Surcharges d\'environnement — env_overrides()');

// config.php ne contient que des valeurs littérales : c'est env_overrides()
// qui décide si l'environnement l'emporte, et à quelles conditions.
$base = ['db' => '/chemin/du/fichier.sqlite', 'auth' => true];

test('STASHBIN_AUTH désactive l\'authentification sur une valeur négative', function () use ($base) {
    try {
        foreach (['0', 'false', 'off', 'no', 'FALSE', 'Off'] as $value) {
            putenv("STASHBIN_AUTH=$value");
            assert_eq(false, env_overrides($base)['auth'], "« $value » désactive l'authentification");
        }
    } finally {
        putenv('STASHBIN_AUTH');
    }
});

test('toute autre valeur de STASHBIN_AUTH laisse l\'authentification active', function () use ($base) {
    // Le piège : « 0 » est falsy en PHP, donc un « ?: » sur la valeur du
    // fichier ramènerait justement le cas à désactiver vers true.
    try {
        foreach (['1', 'true', 'on', 'oui', 'yes', 'peut-être'] as $value) {
            putenv("STASHBIN_AUTH=$value");
            assert_eq(true, env_overrides($base)['auth'], "« $value » laisse l'authentification active");
        }
    } finally {
        putenv('STASHBIN_AUTH');
    }
});

test('une variable absente ou vide ne surcharge rien', function () {
    $file = ['db' => '/valeur/du/fichier.sqlite', 'auth' => false];
    $saved = getenv('STASHBIN_DB');
    try {
        putenv('STASHBIN_DB');
        putenv('STASHBIN_AUTH');
        assert_eq($file, env_overrides($file), 'valeurs du fichier conservées');
        putenv('STASHBIN_DB=');
        putenv('STASHBIN_AUTH=');
        assert_eq($file, env_overrides($file), 'une variable vide n\'écrase pas le fichier');
    } finally {
        putenv('STASHBIN_AUTH');
        putenv(is_string($saved) ? "STASHBIN_DB=$saved" : 'STASHBIN_DB');
    }
});

test('STASHBIN_DB remplace le chemin de la base', function () use ($base) {
    $saved = getenv('STASHBIN_DB');
    try {
        putenv('STASHBIN_DB=/ailleurs/base.sqlite');
        assert_eq('/ailleurs/base.sqlite', env_overrides($base)['db'], 'chemin surchargé');
    } finally {
        putenv(is_string($saved) ? "STASHBIN_DB=$saved" : 'STASHBIN_DB');
    }
});

// ---------------------------------------------------------------------------
group('Base de données — db()');

test('crée le schéma attendu', function () {
    $tables = db()->query(
        "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"
    )->fetchAll(PDO::FETCH_COLUMN);
    assert_true(in_array('users', $tables, true), 'table users créée');
    assert_true(in_array('pastes', $tables, true), 'table pastes créée');
});

test('la table pastes porte les colonnes attendues', function () {
    $cols = array_column(db()->query('PRAGMA table_info(pastes)')->fetchAll(), 'name');
    foreach (['id', 'payload', 'burn', 'delete_hash', 'created', 'expires'] as $col) {
        assert_true(in_array($col, $cols, true), "colonne « $col »");
    }
});

test('active le journal WAL et les clés étrangères', function () {
    assert_eq('wal', strtolower((string) db()->query('PRAGMA journal_mode')->fetchColumn()), 'journal WAL');
    assert_eq(1, (int) db()->query('PRAGMA foreign_keys')->fetchColumn(), 'clés étrangères actives');
});

test('impose l\'unicité du nom d\'utilisateur', function () {
    db()->prepare('INSERT INTO users (username, pass_hash, created) VALUES (?, ?, ?)')
        ->execute(['doublon', 'x', time()]);
    assert_throws(
        fn () => db()->prepare('INSERT INTO users (username, pass_hash, created) VALUES (?, ?, ?)')
                     ->execute(['doublon', 'y', time()]),
        'un second compte du même nom est refusé'
    );
});

test('renvoie des entiers natifs, pas des chaînes', function () {
    // Depuis PHP 8.1, PDO_SQLite restitue les types natifs : api.php compare
    // « expires » à time() sans transtypage, ce qui en dépend.
    db()->prepare('INSERT INTO pastes (id, payload, burn, delete_hash, created, expires) VALUES (?,?,?,?,?,?)')
        ->execute(['typecheck', '{}', 0, 'h', 1000, 2000]);
    $row = db()->query("SELECT created, expires FROM pastes WHERE id='typecheck'")->fetch();
    assert_true(is_int($row['created']), 'created est un entier');
    assert_true(is_int($row['expires']), 'expires est un entier');
});

// ---------------------------------------------------------------------------
group('Purge des secrets expirés — purge_expired()');

$seed = static function (string $id, ?int $expires): void {
    db()->prepare('INSERT INTO pastes (id, payload, burn, delete_hash, created, expires) VALUES (?,?,?,?,?,?)')
        ->execute([$id, '{}', 0, 'hash', time(), $expires]);
};
$exists = static fn (string $id): bool
    => (bool) db()->query("SELECT 1 FROM pastes WHERE id='" . $id . "'")->fetchColumn();

test('supprime un secret dont la date est dépassée', function () use ($seed, $exists) {
    $seed('purge-passe', time() - 60);
    purge_expired();
    assert_true(!$exists('purge-passe'), 'secret expiré retiré');
});

test('conserve un secret encore valide', function () use ($seed, $exists) {
    $seed('purge-futur', time() + 3600);
    purge_expired();
    assert_true($exists('purge-futur'), 'secret non expiré conservé');
});

test('conserve un secret sans expiration', function () use ($seed, $exists) {
    $seed('purge-jamais', null);
    purge_expired();
    assert_true($exists('purge-jamais'), '« never » survit à la purge');
});

test('conserve un secret expirant à la seconde près', function () use ($seed, $exists) {
    // La condition est « expires < time() » : l'instant pile ne doit pas purger.
    $seed('purge-limite', time());
    purge_expired();
    assert_true($exists('purge-limite'), 'la borne est exclusive');
});

// ---------------------------------------------------------------------------
group('Jetons CSRF — csrf_token() / check_csrf()');

test('produit un jeton de 256 bits en hexadécimal', function () {
    $t = csrf_token();
    assert_matches('/^[0-9a-f]{64}$/', $t, 'jeton hexadécimal de 64 caractères');
});

test('reste stable pendant la session', function () {
    assert_eq(csrf_token(), csrf_token(), 'jeton constant sur une même session');
});

test('accepte le jeton courant', function () {
    assert_true(check_csrf(csrf_token()), 'jeton valide accepté');
});

test('refuse un jeton erroné, vide ou tronqué', function () {
    assert_true(!check_csrf('mauvais'), 'jeton arbitraire refusé');
    assert_true(!check_csrf(''), 'jeton vide refusé');
    assert_true(!check_csrf(substr(csrf_token(), 0, 63)), 'jeton tronqué refusé');
});

test('refuse un jeton dont un seul caractère diffère', function () {
    $t = csrf_token();
    $altered = $t[0] === 'a' ? 'b' . substr($t, 1) : 'a' . substr($t, 1);
    assert_true(!check_csrf($altered), 'comparaison stricte');
});

exit(summary('Tests unitaires'));
