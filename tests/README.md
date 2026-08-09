# Test set

One command, one exit code: `0` if everything passes, `1` if anything fails.

```bash
./tests/run.sh
```

The runner builds the image, starts a fresh instance, creates a test account, plays the five suites, prints a summary and tears everything down. There is nothing to set up and nothing to clean afterwards.

## Options

| Option | Effect |
|---|---|
| *(none)* | Everything, on PHP 8.4 + Apache |
| `--version 8.6` | On another PHP version (8.3, 8.4, 8.5, 8.6) |
| `--server nginx` | Behind nginx + PHP-FPM rather than Apache |
| `--db mariadb` | Against a MariaDB server started beside the instance, rather than SQLite |
| `--no-browser` | Without the browser tests — faster, but leaves the cryptography without a net |
| `--matrix` | PHP suites on all eight version × server combinations |
| `--keep` | Leaves the instance alive for inspection afterwards |

## What is covered

**278 tests** across five suites, played against SQLite and against MariaDB.

| Suite | Tests | Scope |
|---|--:|---|
| `unit.test.php` | 91 | Functions in `src/bootstrap.php` without going through HTTP: escaping, configuration and environment overrides, the choice of a database engine, the schema and its migration, purging expired secrets, CSRF tokens, dates in UTC and the markup that lets a browser localise them, the expiry instants a creator may choose, language negotiation and dictionaries |
| `api.test.php` | 64 | Business rules over HTTP: authentication, CSRF, payload validation, lifetimes preset and chosen, burn after reading and the single reader it is promised to, deletion links, ownership, the access log and the headstones left behind, refused methods, error codes and the language of the response |
| `security.test.php` | 39 | The guarantees the README makes: nothing outside `public/`, hardening headers, session and fixation, hashed storage, nothing kept in a cache, one creator's inventory kept out of another's reach, sweeps that spare live secrets, injection, and the fact that choosing a language opens nothing |
| `noauth.test.php` | 22 | Open instance (`auth` set to false): creation without an account, CSRF still required, deletion by the token alone, no inventory, no owner and nothing recorded about readers, and everything that does not move |
| `browser.test.mjs` | 62 | Real Chromium: end-to-end cryptography, complete interface journeys, the creator's inventory as they see it, its badges and its dates in the reader's own timezone, an expiry picked by hand, the interface served in the browser's language, the layout on a phone-sized screen, and what a visitor without JavaScript is told |

The PHP tests run **inside the application container**, under the `www-data` identity. They can therefore compare the HTTP response with what is actually written to the database — that is how we check that a deletion token really is stored hashed, or that a payload is never decrypted server-side.

`--db mariadb` starts a MariaDB server on the test network and points the instance at it; each suite gets an empty database, as each gets an empty volume otherwise. The suites reached through HTTP then read the engine the instance really runs on, so their SQL is written to be understood by both — that is where the differences between engines show up, and every one of them found so far was found this way. `unit.test.php` is the exception: it drops those variables and keeps a SQLite file of its own, because half of what it asserts is read through `PRAGMA`. What is engine-independent in it — the shape of the `db` setting, the driver files in `src/db/`, the SQL fragments they hand out — is checked without any database at all.

`noauth.test.php` needs a server started with `STASHBIN_AUTH=0`: the setting is read at startup, there is no hot switch. The runner therefore replaces the instance for the duration of that suite, then starts an ordinary one again for the browser tests. In `--matrix` mode the suite is replayed on all eight combinations: the environment-variable override goes through mod_php on one side and PHP-FPM on the other, which is not the same path.

The browser tests drive Chromium against the real application. They cover the part nothing else exercises: `deriveKey`, `encryptText` and `decryptPayload` from `public/assets/stashbin.js`, called directly in the page, then the complete journeys — creation, reading back, password, burn after reading, deletion link, and the whole journey replayed in an English-speaking browser.

The last group opens a **phone-sized context** (360×640, touch, then 667×375 for landscape). The server sends the same HTML to every client: everything that makes the interface usable on a phone lives in the stylesheet, and no other suite would notice it breaking. What is asserted is what a visitor would suffer from — a page wider than the screen, a field under 16px that makes Safari iOS zoom in on focus and never zoom back out, a target too small for a fingertip, a text area that swallows a landscape screen — never a CSS declaration.

The language is **set explicitly on both sides**: the Chromium context through `locale: 'fr-FR'`, the HTTP client of `lib.php` through an `Accept-Language: fr` header on every request. The interface now follows the language requested, and the server's fallback serves English — leaving the language to chance would make every label the tests assert unpredictable.

To test the fallback itself, build a silent client: `new Http($base, language: null)` sends no language header at all. Tests that check a translation additionally open their own Chromium context, without a session, otherwise `login.php` would redirect to the creation page.

## Two design points

**The browser shares the application container's network namespace.** `crypto.subtle` only exists in a "secure context": HTTPS, or a loopback origin. By serving the application on `127.0.0.1` from the browser's point of view, we get a secure context without making a certificate or passing Chromium a bypass flag. It is also the concrete demonstration that **HTTPS is not advice but a condition of operation**: without a secure context, StashBin cannot encrypt anything at all.

**Playwright's npm package is installed in a derived image.** The official image ships the browsers but not the module that drives them; `tests/Containerfile.browser` adds it once and for all, so that no run depends on the network.

## Writing a test

The PHP files use `tests/lib.php`, a hundred-line foundation — neither Composer nor PHPUnit, in keeping with the project's stance.

```php
group('What I am checking');

test('the description reads like a sentence', function () use ($http, $token) {
    $res = $http->createSecret(['expire' => '1h'], $token);
    assert_eq(201, $res->status, 'creation accepted');
});
```

Available assertions: `assert_true`, `assert_eq`, `assert_contains`, `assert_not_contains`, `assert_matches`, `assert_throws`. Each takes as its last argument a description of what is expected, reproduced verbatim in the failure message.

`Http` handles session cookies: `login()`, `csrfToken()`, `createSecret()`, `get()`, `post()`, and `request()` with the `rawPath: true` option so the server sees `..` without curl resolving it.

## Checking that the test set is worth anything

A test set that never fails proves nothing. This one has been validated by mutation: twenty-eight regressions were introduced into the code one at a time, and **all twenty-eight were caught** — CSRF check removed, creation opened to anonymous visitors, read-once secret not destroyed, deletion token stored in the clear, size limit removed, purging disabled, HTML escaping neutralised, CSP weakened, cookie without `HttpOnly`, session not regenerated on sign-in, PBKDF2 iterations lowered to 1,000, URL key reduced to 64 bits, `load_lang()` stripped of its path guard, `?lang=` ignored by negotiation, `Vary: Accept-Language` removed, a key missing from the English translation, an API error stripped of its stable code, JavaScript comparing the error message rather than the code, the previous stylesheet put back (four of the five phone-sized tests fail), the inventory's ownership filter removed, the access log left unwritten, a burned secret deleted outright instead of leaving a headstone, a chosen expiry accepted in the past, its upper bound removed, a single-use secret served before being claimed, the access log's cap bound as text rather than as an integer, the no-store header dropped, and X-Forwarded-For believed without a trusted proxy.

That last one deserves a word: it is exactly the regression the first translation produced. `showError()` rested on `err.message === 'introuvable'`, which stopped working the moment the message could be in English. The contract is now the `code` field, and a browser test checks it.

It is worth repeating the exercise after adding a feature: break it deliberately and check that at least one test notices.
