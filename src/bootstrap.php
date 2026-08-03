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

// Applies environment overrides on top of config.php, which holds nothing but
// literal values. A variable that is absent or empty overrides nothing: the
// file remains the reference.
//
// A separate function rather than a few lines inside config(), because that one
// memoises: this is the only point where reading the environment can be tested
// without starting a new process.
function env_overrides(array $config): array
{
    $db = getenv('STASHBIN_DB');
    if (is_string($db) && $db !== '') {
        $config['db'] = $db;
    }

    // An explicit comparison rather than a "?:": "0" is falsy in PHP, and that
    // is precisely the value meant to disable authentication.
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

// Deletes expired pastes (called on every creation; traffic is low).
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

// The right to create and delete a secret. With authentication disabled there
// are no accounts at all: every visitor is authorised.
function is_authorized(): bool
{
    return !auth_enabled() || current_user() !== null;
}

// Returns null when authentication is disabled: there is then no user to name,
// and the caller must cope with that rather than invent one.
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

// An API error. The "code" is stable and forms the contract with the
// JavaScript; the "error" beside it is translated, hence meant for display and
// nothing else. Comparing the message would make the client depend on the
// visitor's language.
function json_error(int $status, string $code): never
{
    json_out($status, ['error' => t('error.' . $code), 'code' => $code]);
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------------------
// Language
// ---------------------------------------------------------------------------

// The languages on offer: one file per language in src/lang/. Adding one means
// dropping a file there, without touching the code.
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

// The language whose keys all exist, and from which an incomplete translation
// borrows what it lacks. It is also the only file whose presence is guaranteed:
// StashBin's interface strings are authored in French, and the other languages
// translate them.
//
// To be distinguished from fallback_locale(), which is the language *served*
// when nothing matches. The two started out conflated, and separating them is
// what allows English to be served by default without losing the key-by-key
// fallback.
function reference_locale(): string
{
    return 'fr';
}

// The language served when negotiation finds nothing. A configuration value
// matching no file would fall back to an empty dictionary: better the reference
// language, which is always present.
function fallback_locale(): string
{
    $configured = config()['default_locale'];
    return in_array($configured, available_locales(), true) ? $configured : reference_locale();
}

// Breaks an Accept-Language header down into tags sorted by decreasing
// quality. Anything not shaped like a language tag is ignored rather than made
// to fail negotiation: the header is not under our control, and "*" itself has
// nothing to match here.
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
    // usort() has been stable since PHP 8.0: at equal quality the header's own
    // order is preserved, which is exactly the preference expressed.
    usort($tags, static fn(array $a, array $b) => $b['q'] <=> $a['q']);
    return array_column($tags, 'tag');
}

// Picks the language to serve: explicit parameter, then Accept-Language by
// decreasing quality, then fallback.
//
// A regional tag falls back to its language — "fr-CA" is served as "fr" —
// because there are no regional variants to offer. A pure function, and
// deliberately so: it is the only way to test negotiation without building an
// HTTP request per case.
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

// The language name becomes a file path: it is only read if it is shaped like a
// tag. locale() already returns known values only, but this function does not
// rely on its sole caller to stay safe.
function load_lang(string $locale): array
{
    if (!preg_match('/^[a-z]{1,8}(-[a-z0-9]{1,8})*$/', $locale)) {
        return [];
    }
    $file = __DIR__ . '/lang/' . $locale . '.php';
    return is_file($file) ? require $file : [];
}

// The dictionary of the language served, topped up by the fallback's and then
// the reference's: a key missing from a partial translation shows up in another
// language rather than as a raw identifier. The reference layer holds even if
// default_locale points at a translation that is itself incomplete.
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

// Translates a key, replacing {placeholders} with the values given. The result
// is not escaped: that is the caller's job, as for any other string placed into
// HTML.
function t(string $key, array $vars = []): string
{
    $s = strings()[$key] ?? $key;
    foreach ($vars as $name => $value) {
        $s = str_replace('{' . $name . '}', (string) $value, $s);
    }
    return $s;
}

// Translates, escapes, and only then substitutes HTML fragments that are
// already safe. Order is everything: escaping after substitution would
// neutralise the very tags just inserted.
function t_html(string $key, array $fragments = []): string
{
    return strtr(e(t($key)), $fragments);
}

// Strings meant for the JavaScript, published into the page. The CSP forbids
// inline script, and a second client-side dictionary would end up diverging
// from the first: the "js." keys therefore travel in a <body> attribute.
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

// Carries an explicit language choice from one page to the next. The parameter
// is stateless — neither cookie nor session remembers it — so it must be passed
// on to the URLs the application builds itself, otherwise the first navigation
// would bring the browser's language back.
function lang_param(string $separator = '?'): string
{
    return isset($_GET['lang']) ? $separator . 'lang=' . urlencode(locale()) : '';
}

// The content depends on the language requested: without this header, an
// intermediate cache would serve the first response it received to every
// subsequent visitor, whatever their language.
function vary_language(): void
{
    header('Vary: Accept-Language');
}
