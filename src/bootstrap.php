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
        header('Location: login.php');
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

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
