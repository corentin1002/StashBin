<?php
// Common ground for the test files: assertions, reporting, HTTP client.
//
// Every test file calls group() then test(), and ends with summary() whose
// return value becomes the process's exit code. No external dependency:
// neither Composer nor PHPUnit.
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
            "$what\nexpected: " . render($expected) . "\ngot     : " . render($actual)
        );
    }
}

function assert_contains(string $needle, string $haystack, string $what): void
{
    if (!str_contains($haystack, $needle)) {
        throw new AssertionFailed(
            "$what\nlooked for: " . render($needle) . "\nin        : " . render($haystack)
        );
    }
}

function assert_not_contains(string $needle, string $haystack, string $what): void
{
    if (str_contains($haystack, $needle)) {
        throw new AssertionFailed(
            "$what\nshould not have contained: " . render($needle) . "\nin: " . render($haystack)
        );
    }
}

function assert_matches(string $pattern, string $subject, string $what): void
{
    if (!preg_match($pattern, $subject)) {
        throw new AssertionFailed(
            "$what\npattern: $pattern\nsubject: " . render($subject)
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
    throw new AssertionFailed("$what (no exception thrown)");
}

function summary(string $suite): int
{
    $failed = count(Report::$failures);
    $total = Report::$passed + $failed;
    fwrite(STDOUT, "\n");
    if ($failed === 0) {
        fwrite(STDOUT, '  ' . c('32', "$suite: $total tests, all passed") . "\n");
        return 0;
    }
    fwrite(STDOUT, '  ' . c('31', "$suite: $total tests, $failed failed") . "\n");
    foreach (Report::$failures as [$g, $n, $m]) {
        fwrite(STDOUT, '    - ' . ($g !== '' ? "$g / " : '') . "$n\n");
    }
    return 1;
}

// The columns of a table, whichever engine holds it. Every engine has its own
// catalogue — PRAGMA here, information_schema there — but they all describe the
// result of a query, and a query returning no rows still describes its columns.
//
// @return list<string>
function table_columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->query("SELECT * FROM $table LIMIT 0");
    $columns = [];
    for ($i = 0; $i < $stmt->columnCount(); $i++) {
        $columns[] = $stmt->getColumnMeta($i)['name'];
    }
    return $columns;
}

// ---------------------------------------------------------------------------
// Minimal HTTP client, with session cookie handling.
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

    /** Returns the header's first value (case-insensitive comparison). */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /** @return list<string> every value of the header */
    public function headerAll(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }
}

final class Http
{
    private string $jar;

    // The language is set explicitly rather than left to the server's fallback:
    // the tests assert French labels, while the fallback serves English.
    // Passing null sends no header at all — that is how the fallback itself
    // gets tested.
    public function __construct(private string $base, private ?string $language = 'fr')
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'stashbin-cookies-');
    }

    /** Starts again from a blank session (useful for testing anonymous access). */
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
            // Lets the server see ".." as-is; otherwise curl resolves it
            // itself and the climb-out attempt is never transmitted.
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
            throw new RuntimeException('HTTP request failed: ' . curl_error($ch) . " ($method $url)");
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

    /**
     * Fires the same GET several times at once, and returns every status and
     * body. Bodies matter: a crash came back as 200 with a stack trace in it,
     * so counting statuses alone would call a failure a success.
     *
     * @return list<array{status:int,body:string}>
     */
    public function parallelGet(string $path, int $times): array
    {
        $multi = curl_multi_init();
        $handles = [];
        for ($i = 0; $i < $times; $i++) {
            $ch = curl_init($this->base . $path);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
            curl_multi_add_handle($multi, $ch);
            $handles[] = $ch;
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi);
            }
        } while ($running && $status === CURLM_OK);

        $out = [];
        foreach ($handles as $ch) {
            $out[] = [
                'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                'body' => (string) curl_multi_getcontent($ch),
            ];
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);

        return $out;
    }

    /** Opens a session for this account. Returns the CSRF token of the creation page. */
    public function login(string $user, string $password): string
    {
        $page = $this->get('/login.php')->body;
        if (!preg_match('/name="csrf" value="([^"]+)"/', $page, $m)) {
            throw new RuntimeException('no CSRF token found on login.php');
        }
        $res = $this->post('/login.php', ['csrf' => $m[1], 'username' => $user, 'password' => $password]);
        if ($res->status !== 302) {
            throw new RuntimeException("sign-in refused for \"$user\" (HTTP {$res->status})");
        }
        return $this->csrfToken();
    }

    /** CSRF token published by index.php for the JavaScript. */
    public function csrfToken(): string
    {
        $page = $this->get('/index.php')->body;
        if (!preg_match('/csrf-token" content="([^"]+)"/', $page, $m)) {
            throw new RuntimeException('no CSRF token found on index.php');
        }
        return $m[1];
    }

    /** Creates a secret and returns the decoded JSON response. */
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
