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
    // The setting used to be nothing but a path, and still may be: the variable
    // keeps that meaning. On a database reached over the network it is simply
    // not the setting that decides anything.
    $db = getenv('STASHBIN_DB');
    if (is_string($db) && $db !== '') {
        $config['db'] = is_array($config['db']) ? ['path' => $db] + $config['db'] : $db;
    }

    // One variable per connection detail, because a container is configured
    // with variables and config.php is mounted read-only. Naming any of them
    // settles the shape of the setting, whatever the file holds.
    $connection = [
        'STASHBIN_DB_DRIVER'  => 'driver',
        'STASHBIN_DB_HOST'    => 'host',
        'STASHBIN_DB_PORT'    => 'port',
        'STASHBIN_DB_NAME'    => 'name',
        'STASHBIN_DB_USER'    => 'user',
        'STASHBIN_DB_PASS'    => 'pass',
        'STASHBIN_DB_SOCKET'  => 'socket',
        'STASHBIN_DB_CHARSET' => 'charset',
    ];
    foreach ($connection as $variable => $key) {
        $value = getenv($variable);
        if (is_string($value) && $value !== '') {
            $config['db'] = db_settings($config['db']);
            $config['db'][$key] = $value;
        }
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

    // Same shape as STASHBIN_AUTH, and for the same reason: the value that
    // matters most is the falsy one, which "?:" would swallow.
    $proxy = getenv('STASHBIN_TRUST_PROXY');
    if (is_string($proxy) && $proxy !== '') {
        $config['trust_proxy'] = !in_array(strtolower($proxy), ['0', 'false', 'off', 'no'], true);
    }

    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = db_driver()['connect'](db_settings(config()['db']));
        migrate_schema($pdo);
    }
    return $pdo;
}

// Creates what is missing, on an empty database as well as on one that predates
// the creator's inventory. There is no migration table: a database describes its
// own schema, which is a more reliable answer to "which version is this" than a
// number we would have to remember to bump.
function migrate_schema(PDO $pdo): void
{
    db_driver()['migrate']($pdo);
}

// The "db" setting in the shape the drivers expect. A bare string means SQLite
// and the file it names: that is how the setting has always been written, and
// nothing obliges an instance that is happy with it to write anything else.
function db_settings(mixed $setting): array
{
    $db = is_array($setting) ? $setting : ['path' => (string) $setting];
    $db['driver'] = strtolower(trim((string) ($db['driver'] ?? 'sqlite')));
    return $db;
}

// The databases on offer: one file per engine in src/db/. Adding one means
// dropping a file there, without touching the code — the same arrangement as
// src/lang/, for the same reason.
function available_db_drivers(): array
{
    static $drivers = null;
    if ($drivers === null) {
        $drivers = [];
        foreach (glob(__DIR__ . '/db/*.php') ?: [] as $file) {
            $drivers[] = basename($file, '.php');
        }
        sort($drivers);
    }
    return $drivers;
}

// MariaDB and MySQL are one wire protocol, one PDO driver and one dialect: both
// names lead to the same file rather than to a copy of it kept in step by hand.
function db_driver_file(string $name): string
{
    return $name === 'mariadb' ? 'mysql' : $name;
}

// The name becomes a file path, so it is only read once it is known to be one of
// the files that are there. An unknown engine stops the request outright: it can
// only be a typo, and carrying on would mean starting with no storage at all.
function load_db_driver(string $name): array
{
    $file = db_driver_file($name);
    if (!in_array($file, available_db_drivers(), true)) {
        throw new RuntimeException(
            "Unknown database driver \"$name\". Available: " . implode(', ', available_db_drivers())
        );
    }
    return require __DIR__ . '/db/' . $file . '.php';
}

function db_driver(): array
{
    static $driver = null;
    if ($driver === null) {
        $driver = load_db_driver(db_settings(config()['db'])['driver']);
    }
    return $driver;
}

// A piece of SQL the engines spell differently, taken from src/db/<driver>.php.
// It is interpolated into a query rather than bound to it, so it may only ever
// come from those files: nothing derived from a request has any business here.
function sql(string $name, string ...$arguments): string
{
    $fragment = db_driver()[$name] ?? throw new RuntimeException("No \"$name\" fragment in this driver.");
    return is_callable($fragment) ? $fragment(...$arguments) : (string) $fragment;
}

// Validates an expiry instant chosen by the creator, given as a Unix timestamp
// in seconds. Returns null when it cannot be used, which the caller turns into
// a 400: never a silently corrected value, since a secret outliving what its
// author asked for is the one mistake that matters here.
//
// The upper bound is the end of year 9999, which is what a date input can
// express — a guard against a mistyped year, not a policy: "never" is on offer
// in the list above it.
function custom_expiry(mixed $value, int $now): ?int
{
    // JSON integers arrive as int; a numeric string is accepted rather than
    // refused on a technicality, but "12abc" or a float is not.
    if (is_string($value) && preg_match('/^\d{1,12}$/', $value)) {
        $value = (int) $value;
    }
    if (!is_int($value) || $value <= $now || $value > 253402300799) {
        return null;
    }
    return $value;
}

// Claims a single-use secret for the request that is about to serve it, and
// says whether it won. Exactly one caller can: the write is the only atomic
// point in a request, so its row count — not a check performed earlier — is
// what decides.
//
// Reading the row, serving it, then destroying it looks equivalent and is not.
// The gap between the read and the destruction is a whole PHP request wide, and
// every reader arriving inside it sees a live secret: twenty simultaneous
// readers used to walk away with seventeen copies of a secret promised to one.
function claim_burned(string $id): bool
{
    $owned = db()->prepare(
        "UPDATE pastes SET payload = '', gone = ?, gone_cause = 'burned'
         WHERE id = ? AND gone IS NULL AND owner_id IS NOT NULL"
    );
    $owned->execute([time(), $id]);
    if ($owned->rowCount() === 1) {
        return true;
    }
    // No owner: the row is removed outright, and the same reasoning applies —
    // whoever the DELETE reports as having removed it is the one reader.
    $orphan = db()->prepare('DELETE FROM pastes WHERE id = ? AND owner_id IS NULL');
    $orphan->execute([$id]);
    return $orphan->rowCount() === 1;
}

// Wipes the ciphertext but keeps the row, so that the creator's list can still
// say what became of the secret and when. A secret nobody owns has no list to
// appear in: it goes for good, and its access log with it.
//
// Idempotent: a secret already at rest keeps the cause that put it there.
function entomb(string $id, string $cause): void
{
    db()->prepare(
        "UPDATE pastes SET payload = '', gone = ?, gone_cause = ?
         WHERE id = ? AND gone IS NULL AND owner_id IS NOT NULL"
    )->execute([time(), $cause, $id]);
    db()->prepare('DELETE FROM pastes WHERE id = ? AND owner_id IS NULL')->execute([$id]);
}

// Retires expired secrets (called on every creation; traffic is low). They stop
// being readable the moment they expire, wherever they are read from: this only
// decides what is left behind.
function purge_expired(): void
{
    $now = time();
    db()->prepare(
        "UPDATE pastes SET payload = '', gone = ?, gone_cause = 'expired'
         WHERE expires IS NOT NULL AND expires < ? AND gone IS NULL AND owner_id IS NOT NULL"
    )->execute([$now, $now]);
    db()->prepare(
        'DELETE FROM pastes WHERE expires IS NOT NULL AND expires < ? AND owner_id IS NULL'
    )->execute([$now]);
}

// Records an access to a secret's payload: served, or already gone — a replayed
// link is precisely what the creator wants to know about. The probe that
// precedes a burn-after-reading confirmation is not an access and is not
// recorded.
//
// Nothing is written for a secret without an owner: nobody would ever read it.
// The INSERT ... SELECT makes that condition part of the write itself, so a
// vanished row cannot leave a dangling log entry behind.
// Bounded on purpose. Anyone holding a link can call the API, and each call
// used to write a row carrying 200 characters of their choosing — including on
// a secret long gone, since a replayed link is worth recording. Unbounded, that
// is a stranger filling the disk and burying the entries their owner cares
// about. The first accesses are the ones that answer "did they read it?", so
// the cap keeps those and drops the flood.
function record_read(string $id, string $outcome): void
{
    db()->prepare(
        'INSERT INTO paste_reads (paste_id, at, outcome, ip, agent)
         SELECT id, ?, ?, ?, ? FROM pastes
          WHERE id = ? AND owner_id IS NOT NULL
            AND (SELECT COUNT(*) FROM paste_reads WHERE paste_id = ?) < ' . sql('integer', '?')
    )->execute([time(), $outcome, client_ip(), client_agent(), $id, $id, config()['access_log_max']]);
}

// Whether a proxy in front of us may be believed. Off by default: X-Forwarded-*
// is written by whoever sends the request, so believing it unconditionally lets
// any reader dictate both what the log says about them and whether their
// session cookie is treated as secure.
function trust_proxy(): bool
{
    return config()['trust_proxy'] === true;
}

// Pure, and deliberately so — the same reason negotiate_locale() is: it is the
// only way to test every combination without standing up a proxy.
function pick_client_ip(?string $remote, ?string $forwarded, bool $trusted): ?string
{
    if ($trusted && $forwarded !== null) {
        // The first entry is the client; what follows are the proxies it went
        // through. An entry that is not an address is discarded rather than
        // recorded, since it can only be a forgery or a mistake.
        $first = trim(explode(',', $forwarded)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
            return $first;
        }
    }
    return ($remote === null || $remote === '') ? null : $remote;
}

// "on", "1" and any non-empty value other than "off" mean HTTPS: the variable
// is set by the web server and its spelling varies between them.
function pick_https(?string $https, ?string $forwardedProto, bool $trusted): bool
{
    if ($https !== null && $https !== '' && strtolower($https) !== 'off') {
        return true;
    }
    return $trusted && strtolower(trim((string) $forwardedProto)) === 'https';
}

// The reader's address, as the server saw it — or as a trusted proxy reports it.
function client_ip(): ?string
{
    return pick_client_ip($_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null, trust_proxy());
}

// Whether this request reached us over HTTPS. Behind a proxy terminating TLS,
// PHP sees plain HTTP and only the proxy knows better, which is what
// trust_proxy is for: without it the session cookie loses its Secure flag on
// exactly the deployment the README recommends.
function request_is_https(): bool
{
    return pick_https($_SERVER['HTTPS'] ?? null, $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null, trust_proxy());
}

// The browser the reader declares. Kept because it is often the explanation for
// a burn-after-reading secret consumed before its recipient got to it: a chat
// application preloading the link.
function client_agent(): ?string
{
    $agent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    return $agent === '' ? null : mb_substr($agent, 0, 200);
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
        'secure' => request_is_https(),
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

// An uncaught exception used to reach the visitor as a stack trace naming
// internal paths — under a 200, since the headers were already out, so a crash
// read as a success and a race condition hid behind it for as long as it did.
// The detail belongs in the server's log and nowhere else.
set_exception_handler(static function (Throwable $e): void {
    error_log('StashBin: ' . $e);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => 'internal error', 'code' => 'internal_error'], JSON_UNESCAPED_SLASHES);
});

function security_headers(): void
{
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    // Nothing PHP serves here is worth keeping: the API's body is the
    // ciphertext itself, which would otherwise outlive a burnt secret in the
    // reader's disk cache, and the inventory carries the addresses of everyone
    // who opened a link. The stylesheet and the script are served by the web
    // server and keep their caching.
    header('Cache-Control: no-store');
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

// Dates are shown in UTC, and say so. The server's timezone is nobody's in
// particular, and a reader's access is worth timing against a fixed reference
// rather than against wherever the machine happens to think it is.
function utc_stamp(?int $stamp): string
{
    return $stamp === null ? t('secrets.unknown') : gmdate('Y-m-d H:i', $stamp) . ' UTC';
}

// A date the browser rewrites into the reader's own timezone, which the server
// has no way of knowing. The element carries the instant twice: once for the
// script, once as text — so a page served without JavaScript still shows a date,
// in UTC, and says which.
//
// Returns HTML, hence the escaping here rather than at the call site; the values
// are numeric, but a helper that returns markup should never depend on that.
function time_tag(?int $stamp): string
{
    if ($stamp === null) {
        return e(t('secrets.unknown'));
    }
    return '<time datetime="' . e(gmdate('c', $stamp)) . '" data-stamp="' . $stamp . '">'
        . e(utc_stamp($stamp)) . '</time>';
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
