<?php
// Socle commun aux fichiers de test : assertions, compte rendu, client HTTP.
//
// Chaque fichier de test appelle group() puis test(), et se termine par
// summary() dont la valeur de retour sert de code de sortie au processus.
// Aucune dépendance externe : ni Composer, ni PHPUnit.
declare(strict_types=1);

final class Report
{
    public static int $passed = 0;
    /** @var list<array{string,string,string}> */
    public static array $failures = [];
    public static string $group = '';
    public static bool $color = true;
}

function c(string $code, string $text): string
{
    return Report::$color ? "\033[{$code}m{$text}\033[0m" : $text;
}

function group(string $title): void
{
    Report::$group = $title;
    fwrite(STDOUT, "\n  " . c('1', $title) . "\n");
}

function test(string $name, callable $fn): void
{
    try {
        $fn();
        Report::$passed++;
        fwrite(STDOUT, '    ' . c('32', '✔') . " $name\n");
    } catch (Throwable $e) {
        Report::$failures[] = [Report::$group, $name, $e->getMessage()];
        fwrite(STDOUT, '    ' . c('31', '✘') . " $name\n");
        foreach (explode("\n", $e->getMessage()) as $line) {
            fwrite(STDOUT, '        ' . c('31', $line) . "\n");
        }
    }
}

final class AssertionFailed extends RuntimeException {}

function render(mixed $v): string
{
    if (is_string($v)) {
        $s = strlen($v) > 200 ? substr($v, 0, 200) . '…' : $v;
        return '"' . str_replace("\n", '\n', $s) . '"';
    }
    return var_export($v, true);
}

function assert_true(bool $cond, string $what): void
{
    if (!$cond) {
        throw new AssertionFailed($what);
    }
}

function assert_eq(mixed $expected, mixed $actual, string $what): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(
            "$what\nattendu : " . render($expected) . "\nobtenu  : " . render($actual)
        );
    }
}

function assert_contains(string $needle, string $haystack, string $what): void
{
    if (!str_contains($haystack, $needle)) {
        throw new AssertionFailed(
            "$what\ncherché : " . render($needle) . "\ndans    : " . render($haystack)
        );
    }
}

function assert_not_contains(string $needle, string $haystack, string $what): void
{
    if (str_contains($haystack, $needle)) {
        throw new AssertionFailed(
            "$what\nne devait pas contenir : " . render($needle) . "\ndans : " . render($haystack)
        );
    }
}

function assert_matches(string $pattern, string $subject, string $what): void
{
    if (!preg_match($pattern, $subject)) {
        throw new AssertionFailed(
            "$what\nmotif  : $pattern\nsujet  : " . render($subject)
        );
    }
}

function assert_throws(callable $fn, string $what): void
{
    try {
        $fn();
    } catch (Throwable) {
        return;
    }
    throw new AssertionFailed("$what (aucune exception levée)");
}

function summary(string $suite): int
{
    $failed = count(Report::$failures);
    $total = Report::$passed + $failed;
    fwrite(STDOUT, "\n");
    if ($failed === 0) {
        fwrite(STDOUT, '  ' . c('32', "$suite : $total tests, tous réussis") . "\n");
        return 0;
    }
    fwrite(STDOUT, '  ' . c('31', "$suite : $total tests, $failed en échec") . "\n");
    foreach (Report::$failures as [$g, $n, $m]) {
        fwrite(STDOUT, '    - ' . ($g !== '' ? "$g / " : '') . "$n\n");
    }
    return 1;
}

// ---------------------------------------------------------------------------
// Client HTTP minimal, avec gestion des cookies de session.
// ---------------------------------------------------------------------------

final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {}

    public function json(): mixed
    {
        return json_decode($this->body, true);
    }

    /** Renvoie la première valeur de l'en-tête (comparaison insensible à la casse). */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /** @return list<string> toutes les valeurs de l'en-tête */
    public function headerAll(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }
}

final class Http
{
    private string $jar;

    // La langue est fixée explicitement, et non laissée au repli du serveur :
    // les tests affirment des libellés français, et le repli, lui, sert
    // l'anglais. Passer null n'envoie aucun en-tête — c'est ainsi qu'on éprouve
    // le repli lui-même.
    public function __construct(private string $base, private ?string $language = 'fr')
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'stashbin-cookies-');
    }

    /** Repart d'une session vierge (utile pour tester l'accès anonyme). */
    public function forgetCookies(): void
    {
        file_put_contents($this->jar, '');
    }

    public function get(string $path, bool $follow = false): Response
    {
        return $this->request('GET', $path, null, [], $follow);
    }

    /** @param array<string,string>|string $body */
    public function post(string $path, array|string $body, array $headers = []): Response
    {
        return $this->request('POST', $path, $body, $headers, false);
    }

    public function request(
        string $method,
        string $path,
        array|string|null $body = null,
        array $headers = [],
        bool $follow = false,
        bool $rawPath = false,
    ): Response {
        $url = $this->base . $path;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_TIMEOUT => 30,
            // Laisse le serveur voir « .. » tel quel, sinon curl le résout
            // lui-même et la tentative de remontée n'est jamais transmise.
            CURLOPT_PATH_AS_IS => $rawPath,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
        }
        if ($this->language !== null && !isset($headers['Accept-Language'])) {
            $headers['Accept-Language'] = $this->language;
        }
        if ($headers !== []) {
            $flat = [];
            foreach ($headers as $k => $v) {
                $flat[] = "$k: $v";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $flat);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException('requête HTTP impossible : ' . curl_error($ch) . " ($method $url)");
        }
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headers = [];
        foreach (explode("\r\n", substr($raw, 0, $headerSize)) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))][] = trim($v);
        }

        return new Response($status, $headers, substr($raw, $headerSize));
    }

    /** Ouvre une session pour ce compte. Renvoie le jeton CSRF de la page de création. */
    public function login(string $user, string $password): string
    {
        $page = $this->get('/login.php')->body;
        if (!preg_match('/name="csrf" value="([^"]+)"/', $page, $m)) {
            throw new RuntimeException('jeton CSRF introuvable sur login.php');
        }
        $res = $this->post('/login.php', ['csrf' => $m[1], 'username' => $user, 'password' => $password]);
        if ($res->status !== 302) {
            throw new RuntimeException("connexion refusée pour « $user » (HTTP {$res->status})");
        }
        return $this->csrfToken();
    }

    /** Jeton CSRF publié par index.php à destination du JavaScript. */
    public function csrfToken(): string
    {
        $page = $this->get('/index.php')->body;
        if (!preg_match('/csrf-token" content="([^"]+)"/', $page, $m)) {
            throw new RuntimeException('jeton CSRF introuvable sur index.php');
        }
        return $m[1];
    }

    /** Crée un secret et renvoie la réponse JSON décodée. */
    public function createSecret(array $overrides = [], ?string $token = null): Response
    {
        $payload = ($overrides['payload'] ?? null) === null
            ? ['v' => 1, 'iv' => 'AAAA', 'salt' => 'BBBB', 'iter' => 310000, 'pwd' => 0, 'ct' => 'Q0lQSEVS']
            : $overrides['payload'];

        $body = ['payload' => $payload];
        foreach (['expire', 'burn'] as $k) {
            if (array_key_exists($k, $overrides)) {
                $body[$k] = $overrides[$k];
            }
        }
        if (array_key_exists('raw', $overrides)) {
            $body = $overrides['raw'];
        }

        return $this->post('/api.php', is_string($body) ? $body : json_encode($body), [
            'Content-Type' => 'application/json',
            'X-CSRF-Token' => $token ?? $this->csrfToken(),
        ]);
    }
}
