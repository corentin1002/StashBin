<?php
declare(strict_types=1);

function config(): array
{
    static $config = null;
    if ($config === null) {
        $config = env_overrides(require dirname(__DIR__) . '/config.php');
    }
    return $config;
}

// Applique les surcharges d'environnement par-dessus config.php, qui ne
// contient que des valeurs littérales. Une variable absente ou vide ne
// surcharge rien : le fichier reste la référence.
//
// Fonction séparée, et non quelques lignes dans config(), parce que celle-ci
// mémoïse : c'est le seul point où la lecture de l'environnement est
// éprouvable sans relancer un processus.
function env_overrides(array $config): array
{
    $db = getenv('STASHBIN_DB');
    if (is_string($db) && $db !== '') {
        $config['db'] = $db;
    }

    // Comparaison explicite plutôt qu'un « ?: » : « 0 » est falsy en PHP, et
    // c'est justement la valeur qui doit désactiver l'authentification.
    $auth = getenv('STASHBIN_AUTH');
    if (is_string($auth) && $auth !== '') {
        $config['auth'] = !in_array(strtolower($auth), ['0', 'false', 'off', 'no'], true);
    }

    $locale = getenv('STASHBIN_LOCALE');
    if (is_string($locale) && $locale !== '') {
        $config['default_locale'] = strtolower($locale);
    }

    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $path = config()['db'];
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            pass_hash TEXT NOT NULL,
            created INTEGER NOT NULL
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS pastes (
            id TEXT PRIMARY KEY,
            payload TEXT NOT NULL,
            burn INTEGER NOT NULL DEFAULT 0,
            delete_hash TEXT NOT NULL,
            created INTEGER NOT NULL,
            expires INTEGER
        )');
    }
    return $pdo;
}

// Supprime les pastes expirés (appelé à chaque création, le trafic est faible).
function purge_expired(): void
{
    $stmt = db()->prepare('DELETE FROM pastes WHERE expires IS NOT NULL AND expires < ?');
    $stmt->execute([time()]);
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name(config()['session_name']);
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']),
        'path' => '/',
    ]);
    session_start();
}

function auth_enabled(): bool
{
    return config()['auth'] === true;
}

function current_user(): ?array
{
    start_session();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT id, username FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

// Droit de créer et de supprimer un secret. L'authentification désactivée,
// il n'y a plus de compte du tout : tout visiteur est autorisé.
function is_authorized(): bool
{
    return !auth_enabled() || current_user() !== null;
}

// Renvoie null quand l'authentification est désactivée : il n'y a alors aucun
// utilisateur à nommer, et l'appelant doit s'en accommoder plutôt que d'en
// inventer un.
function require_login(): ?array
{
    if (!auth_enabled()) {
        return null;
    }
    $user = current_user();
    if ($user === null) {
        header('Location: login.php' . lang_param());
        exit;
    }
    return $user;
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function check_csrf(string $token): bool
{
    start_session();
    return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

function security_headers(): void
{
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
}

function json_out(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

// Erreur d'API. Le « code » est stable et constitue le contrat avec le
// JavaScript ; le « error » qui l'accompagne est traduit, donc destiné à
// l'affichage et à rien d'autre. Comparer le message reviendrait à faire
// dépendre le client de la langue du visiteur.
function json_error(int $status, string $code): never
{
    json_out($status, ['error' => t('error.' . $code), 'code' => $code]);
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------------------
// Langue
// ---------------------------------------------------------------------------

// Langues offertes : un fichier par langue dans src/lang/. En ajouter une, c'est
// déposer un fichier, sans toucher au code.
function available_locales(): array
{
    static $locales = null;
    if ($locales === null) {
        $locales = [];
        foreach (glob(__DIR__ . '/lang/*.php') ?: [] as $file) {
            $locales[] = basename($file, '.php');
        }
        sort($locales);
    }
    return $locales;
}

// Langue dont toutes les clés existent, et à laquelle une traduction
// incomplète emprunte ce qui lui manque. C'est aussi le seul fichier dont la
// présence soit garantie : StashBin est écrit en français, ses chaînes y
// naissent, et les autres langues les traduisent.
//
// À distinguer de fallback_locale(), qui est la langue *servie* quand rien ne
// correspond. Les deux ont commencé confondues, et les séparer permet de servir
// l'anglais par défaut sans perdre le repli clé à clé.
function reference_locale(): string
{
    return 'fr';
}

// Langue servie quand la négociation ne trouve rien. Une valeur de
// configuration qui ne correspond à aucun fichier retomberait sur un
// dictionnaire vide : mieux vaut la langue de référence, toujours présente.
function fallback_locale(): string
{
    $configured = config()['default_locale'];
    return in_array($configured, available_locales(), true) ? $configured : reference_locale();
}

// Décompose un en-tête Accept-Language en étiquettes triées par qualité
// décroissante. Ce qui n'a pas la forme d'une étiquette de langue est ignoré
// plutôt que de faire échouer la négociation : l'en-tête n'est pas maîtrisé,
// et « * » lui-même n'a rien à quoi correspondre ici.
function parse_accept_language(string $header): array
{
    $tags = [];
    foreach (explode(',', $header) as $part) {
        $bits = explode(';', trim($part));
        $tag = strtolower(trim($bits[0]));
        if (!preg_match('/^[a-z]{1,8}(-[a-z0-9]{1,8})*$/', $tag)) {
            continue;
        }
        $q = 1.0;
        foreach (array_slice($bits, 1) as $param) {
            if (preg_match('/^\s*q\s*=\s*(\d(?:\.\d+)?)\s*$/i', $param, $m)) {
                $q = (float) $m[1];
            }
        }
        if ($q <= 0) {
            continue;
        }
        $tags[] = ['tag' => $tag, 'q' => $q];
    }
    // usort() est stable depuis PHP 8.0 : à qualité égale, l'ordre de l'en-tête
    // est conservé, ce qui est exactement la préférence exprimée.
    usort($tags, static fn(array $a, array $b) => $b['q'] <=> $a['q']);
    return array_column($tags, 'tag');
}

// Choisit la langue à servir : paramètre explicite, puis Accept-Language par
// qualité décroissante, puis repli.
//
// Une étiquette régionale retombe sur sa langue — « fr-CA » est servi en
// « fr » — parce qu'il n'y a pas de variantes régionales à offrir. Fonction
// pure, et c'est délibéré : c'est le seul moyen d'éprouver la négociation sans
// fabriquer une requête HTTP par cas.
function negotiate_locale(?string $header, ?string $override, array $available, string $fallback): string
{
    if ($override !== null && in_array($override, $available, true)) {
        return $override;
    }
    foreach (parse_accept_language((string) $header) as $tag) {
        if (in_array($tag, $available, true)) {
            return $tag;
        }
        $primary = strstr($tag, '-', true);
        if ($primary !== false && in_array($primary, $available, true)) {
            return $primary;
        }
    }
    return $fallback;
}

function locale(): string
{
    static $locale = null;
    if ($locale === null) {
        $locale = negotiate_locale(
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null,
            isset($_GET['lang']) ? strtolower(trim((string) $_GET['lang'])) : null,
            available_locales(),
            fallback_locale(),
        );
    }
    return $locale;
}

// Le nom de la langue devient un chemin de fichier : il ne se lit que s'il a la
// forme d'une étiquette. locale() ne renvoie déjà que des valeurs connues, mais
// cette fonction ne dépend pas de son seul appelant pour rester sûre.
function load_lang(string $locale): array
{
    if (!preg_match('/^[a-z]{1,8}(-[a-z0-9]{1,8})*$/', $locale)) {
        return [];
    }
    $file = __DIR__ . '/lang/' . $locale . '.php';
    return is_file($file) ? require $file : [];
}

// Dictionnaire de la langue servie, complété par celui du repli puis par celui
// de référence : une clé absente d'une traduction partielle s'affiche dans une
// autre langue plutôt qu'en identifiant brut. La couche de référence tient même
// si default_locale désigne une traduction elle-même incomplète.
function strings(): array
{
    static $strings = null;
    if ($strings === null) {
        $strings = load_lang(locale())
            + load_lang(fallback_locale())
            + load_lang(reference_locale());
    }
    return $strings;
}

// Traduit une clé, en remplaçant les marqueurs {ainsi} par les valeurs données.
// Le résultat n'est pas échappé : c'est à l'appelant de le faire, comme pour
// toute autre chaîne posée dans du HTML.
function t(string $key, array $vars = []): string
{
    $s = strings()[$key] ?? $key;
    foreach ($vars as $name => $value) {
        $s = str_replace('{' . $name . '}', (string) $value, $s);
    }
    return $s;
}

// Traduit, échappe, puis seulement ensuite substitue des fragments HTML déjà
// sûrs. L'ordre fait tout : échapper après la substitution neutraliserait les
// balises qu'on vient d'insérer.
function t_html(string $key, array $fragments = []): string
{
    return strtr(e(t($key)), $fragments);
}

// Chaînes destinées au JavaScript, publiées dans la page. La CSP interdit le
// script inline, et un second dictionnaire côté client finirait par diverger
// du premier : les clés « js. » voyagent donc en attribut de <body>.
function client_strings(): string
{
    $out = [];
    foreach (strings() as $key => $value) {
        if (str_starts_with($key, 'js.')) {
            $out[substr($key, 3)] = $value;
        }
    }
    return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// Reconduit un choix de langue explicite d'une page à la suivante. Le paramètre
// est sans état — ni cookie ni session ne le mémorisent — il faut donc le
// repasser aux URL que l'application fabrique elle-même, sans quoi la première
// navigation ramènerait la langue du navigateur.
function lang_param(string $separator = '?'): string
{
    return isset($_GET['lang']) ? $separator . 'lang=' . urlencode(locale()) : '';
}

// Le contenu dépend de la langue demandée : sans cet en-tête, un cache
// intermédiaire servirait la première réponse reçue à tous les visiteurs
// suivants, quelle que soit leur langue.
function vary_language(): void
{
    header('Vary: Accept-Language');
}
