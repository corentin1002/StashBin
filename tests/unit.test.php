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

// ---------------------------------------------------------------------------
group('Langue — lecture d\'Accept-Language');

test('trie les étiquettes par qualité décroissante', function () {
    assert_eq(
        ['de', 'en', 'fr'],
        parse_accept_language('fr;q=0.3, en;q=0.7, de'),
        'la qualité prime sur l\'ordre d\'écriture'
    );
});

test('conserve l\'ordre de l\'en-tête à qualité égale', function () {
    assert_eq(['en', 'fr', 'de'], parse_accept_language('en, fr, de'), 'préférence implicite respectée');
});

test('accepte les espaces et la casse', function () {
    assert_eq(['en-us', 'fr'], parse_accept_language('  EN-US , FR ; Q=0.5 '), 'normalisé en minuscules');
});

test('écarte une étiquette de qualité nulle', function () {
    assert_eq(['fr'], parse_accept_language('en;q=0, fr'), 'q=0 signifie « surtout pas »');
});

test('ignore ce qui n\'a pas la forme d\'une étiquette', function () {
    assert_eq(['fr'], parse_accept_language('*, <script>, 1;;;, fr'), 'entrées illisibles écartées');
    assert_eq([], parse_accept_language(''), 'en-tête vide');
});

// ---------------------------------------------------------------------------
group('Langue — négociation');

test('retient la première langue disponible', function () {
    assert_eq('en', negotiate_locale('en;q=0.9, fr;q=0.2', null, ['en', 'fr'], 'fr'), 'anglais préféré');
    assert_eq('fr', negotiate_locale('fr, en', null, ['en', 'fr'], 'en'), 'français préféré');
});

test('ramène une étiquette régionale à sa langue', function () {
    assert_eq('fr', negotiate_locale('fr-CA', null, ['en', 'fr'], 'en'), 'fr-CA servi en fr');
    assert_eq('en', negotiate_locale('en-GB;q=0.9, de;q=0.8', null, ['en', 'fr'], 'fr'), 'en-GB servi en en');
});

test('saute les langues qu\'on ne sait pas servir', function () {
    assert_eq('en', negotiate_locale('de, es, en', null, ['en', 'fr'], 'fr'), 'première langue connue retenue');
});

test('se replie quand rien ne correspond', function () {
    assert_eq('fr', negotiate_locale('de, es', null, ['en', 'fr'], 'fr'), 'repli sur la langue par défaut');
    assert_eq('fr', negotiate_locale(null, null, ['en', 'fr'], 'fr'), 'aucun en-tête du tout');
});

test('une langue que l\'on ne sait pas servir donne l\'anglais', function () {
    // C'est le réglage livré : default_locale vaut « en ».
    assert_eq('en', fallback_locale(), 'repli configuré sur l\'anglais');
    assert_eq('en', negotiate_locale('de, es, it', null, available_locales(), fallback_locale()), 'aucune ne correspond');
    assert_eq('en', negotiate_locale(null, null, available_locales(), fallback_locale()), 'aucun en-tête du tout');
});

test('la langue de référence reste le français, quoi que serve le repli', function () {
    // Les deux rôles sont distincts : « en » est servi faute de mieux, « fr »
    // fournit les clés qu'une traduction partielle n'a pas.
    assert_eq('fr', reference_locale(), 'fr reste la référence');
    assert_true(fallback_locale() !== reference_locale(), 'les deux ne sont plus confondus');
});

test('le paramètre explicite l\'emporte sur l\'en-tête', function () {
    assert_eq('en', negotiate_locale('fr', 'en', ['en', 'fr'], 'fr'), 'choix du visiteur prioritaire');
});

test('un paramètre explicite inconnu ne fait pas dérailler la négociation', function () {
    assert_eq('en', negotiate_locale('en', 'klingon', ['en', 'fr'], 'fr'), 'on retombe sur l\'en-tête');
    assert_eq('fr', negotiate_locale('de', '../fr', ['en', 'fr'], 'fr'), 'aucun chemin ne devient une langue');
});

// ---------------------------------------------------------------------------
group('Langue — dictionnaires');

test('le français et l\'anglais sont offerts', function () {
    assert_true(in_array('fr', available_locales(), true), 'fr présent');
    assert_true(in_array('en', available_locales(), true), 'en présent');
});

test('chaque langue offerte traduit toutes les clés de la référence', function () {
    // fr.php fait référence : une clé qui lui manquerait n'aurait aucun repli.
    foreach (available_locales() as $locale) {
        $missing = array_diff(array_keys(load_lang(reference_locale())), array_keys(load_lang($locale)));
        assert_eq([], array_values($missing), "clés non traduites en « $locale » : " . implode(', ', $missing));
    }
});

test('aucune traduction ne laisse de marqueur orphelin', function () {
    // Un {marqueur} présent d'un côté et absent de l'autre afficherait du
    // texte parasite, ou perdrait la donnée qu'il devait porter.
    $fr = load_lang(reference_locale());
    foreach (load_lang('en') as $key => $text) {
        preg_match_all('/\{(\w+)\}/', $fr[$key] ?? '', $expected);
        preg_match_all('/\{(\w+)\}/', $text, $actual);
        sort($expected[1]);
        sort($actual[1]);
        assert_eq($expected[1], $actual[1], "marqueurs de « $key »");
    }
});

test('un nom de langue qui est un chemin ne lit aucun fichier', function () {
    // Le nom devient un chemin, et « require » exécute ce qu'il trouve :
    // « ../../config » désigne le config.php du dépôt, qui serait alors servi
    // comme un dictionnaire. Aucun appelant ne peut aujourd'hui y conduire —
    // locale() ne renvoie que des langues effectivement listées — mais la
    // fonction ne s'en remet pas à son seul appelant pour rester sûre.
    assert_eq([], load_lang('../../config'), 'remontée jusqu\'à la configuration refusée');
    assert_eq([], load_lang('fr/../../../config'), 'traversée refusée');
    assert_eq([], load_lang('inexistante'), 'langue inconnue : dictionnaire vide');
});

// ---------------------------------------------------------------------------
group('Langue — traduction');

test('rend la chaîne de la clé demandée', function () {
    // Hors requête HTTP, aucune langue n'est demandée : c'est le repli qui sert.
    assert_eq('not found', t('error.not_found'), 'clé traduite dans la langue de repli');
});

test('remplace les marqueurs par les valeurs fournies', function () {
    assert_eq('Error: panne', t('js.error', ['error' => 'panne']), 'marqueur substitué');
});

test('rend la clé elle-même quand elle n\'existe pas', function () {
    // Visible sans être fatal : la page reste lisible et le manque saute aux yeux.
    assert_eq('cle.inexistante', t('cle.inexistante'), 'clé rendue telle quelle');
});

test('t_html échappe la traduction avant d\'y insérer du HTML', function () {
    $out = t_html('create.logged_in', [
        '{user}' => '<strong>alice</strong>',
        '{logout}' => '<a href="logout.php">sortir</a>',
    ]);
    assert_contains('<strong>alice</strong>', $out, 'fragment inséré tel quel');
    assert_contains('<a href="logout.php">sortir</a>', $out, 'lien inséré tel quel');
});

test('t_html neutralise le HTML venu de la traduction', function () {
    // Une traduction est une donnée comme une autre : elle ne doit pas pouvoir
    // introduire de balise, même déposée par un opérateur distrait.
    assert_eq('cle.&lt;script&gt;', t_html('cle.<script>'), 'balise échappée');
});

test('les chaînes publiées au JavaScript sont du JSON sans le préfixe « js. »', function () {
    $published = json_decode(client_strings(), true);
    assert_true(is_array($published), 'JSON valide');
    assert_eq('Encrypting…', $published['encrypting'] ?? null, 'clé dépouillée de son préfixe');
    assert_true(!isset($published['js.encrypting']), 'préfixe retiré');
    assert_true(!isset($published['error.not_found']), 'seules les clés « js. » sont publiées');
});

exit(summary('Tests unitaires'));
