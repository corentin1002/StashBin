# Changelog

Every notable change to StashBin is documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- MIT licence.
- End-to-end encryption in the browser: AES-256-GCM with a key derived by PBKDF2-SHA256 (310,000 iterations); the key travels in the URL's `#` fragment and never reaches the server.
- Secret creation restricted to authenticated users (PHP sessions, CSRF protection).
- Authentication can be disabled through `config.php`'s `auth` setting, for an open instance whose access is already restricted some other way. It remains required by default: that is the whole point of the project. Disabled, creation and deletion become available to any visitor, `login.php` redirects to the creation page which announces the open mode, and the deletion link requires nothing but its token. Nothing else moves: browser-side encryption, the hashed deletion token, CSRF protection and hardening headers are all maintained, and a dedicated test suite (`tests/noauth.test.php`, 19 tests) checks that against a genuinely open instance.
- An inventory for each creator (`secrets.php`, linked from the creation page): every secret they made, named by the identifier its link carries, with its state — never read, read *n* times, destroyed after reading, deleted, expired — the date it was created and the one it disappeared on, the log of accesses it received, and a delete button while it still lives. Secrets are not readable from that page: their key never left the browser that created them, and the server has no way of getting it back.

  Nothing on that page describes a secret's content, not even a label: the entries are told apart by their identifier and their dates. A plaintext title was written and then dropped — it made the list easier to read, at the price of the one telling piece of metadata the server would have been able to read.

  Two things do follow, and they are worth stating plainly. Secrets now carry an `owner_id`, so the server knows which account created which secret, where it used to know nothing. And every access is logged with its date, the reader's IP address as the web server saw it — never as `X-Forwarded-For` claims it, since the reader writes that header themselves — and the browser they declared, which is usually the explanation when a single-use secret is spent before its recipient opens it: a chat application preloading the link. An instance without authentication records none of it: no owner, no log, no page.

  Deleting a secret, reading a single-use one and letting one expire no longer remove the row: the ciphertext is wiped and the row stays as a headstone, so the list can say what became of it. Its owner removes it for good with "remove from history", or clears the lot with a single button — expired entries pile up on their own, and taking them away one at a time is exactly the chore it looks like. Neither touches a secret that is still live. A secret nobody owns is deleted outright, as before, along with its log.

- `config.php` now holds nothing but literal values, directly editable. The environment-variable overrides — `STASHBIN_DB` for the database path, `STASHBIN_AUTH` for authentication, `STASHBIN_LOCALE` for the fallback language — are gathered in `env_overrides()` in `src/bootstrap.php`, which only applies a variable when it is set and non-empty.
- `AUTH=0 ./containers/stashbin.sh up` starts a development instance without authentication.
- Public reading by link, with an optional password mixed into key derivation.
- Configurable expiry (1 hour, 1 day, 1 week, 1 month, never) with automatic purging of expired secrets.
- Burn after first reading, preceded by a confirmation screen so the secret is not consumed by accident.
- Deletion link handed to the creator.
- Account management from the command line (`bin/user.php`: `add`, `passwd`, `del`, `list`).
- SQLite storage with no external dependency, schema created automatically.
- Multilingual interface: English and French, chosen from the browser's `Accept-Language` header, falling back to `default_locale` (`en` by default, overridable through `STASHBIN_LOCALE`): a visitor none of whose requested languages is translated gets English. `src/lang/fr.php` remains the reference language, the one a partial translation borrows its missing keys from — the two roles are distinct. A regional tag falls back to its language (`fr-CA` → `fr`). The `?lang=` parameter forces the language of a page, with no cookie and no session. Adding a language means dropping a file into `src/lang/`: the code lists that directory. Responses carry `Vary: Accept-Language`.
- API errors now carry a stable `code` field (`not_found`, `unauthorized`, `bad_csrf`…) beside the message, which is translated: the JavaScript compares the code, never the text.
- Automatic light/dark theme, following the system preference.
- `containers/` test bench: Apache + mod_php and nginx + PHP-FPM images, parameterised by PHP version (8.3 to 8.6), with the code mounted read-only and the SQLite database in a dedicated volume (path overridable through `STASHBIN_DB`).
- `containers/stashbin.sh`: single driver for the test bench (`up`, `user`, `logs`, `down`, `reset`, `clean`, `list`, `test`). `clean` removes the containers, volumes and images the bench produced — and nothing else; `clean --all` adds the downloaded `php:*` base images. `user` relays `bin/user.php`'s subcommands (`add`, `passwd`, `del`, `list`) into the container, under the `www-data` identity. `test` replays the full application journey across all eight version × server combinations and fails if any of them regresses.
- Compatibility verified from PHP 8.1 to 8.6 (8.6 as a release candidate), under Apache as well as nginx: no deprecation and no warning.
- Complete test set (`tests/run.sh`): 223 tests across five suites — unit (functions in `src/bootstrap.php`), API (business rules), security (the guarantees the README makes), open instance (behaviour without authentication, played against a second container) and browser (end-to-end cryptography and interface journeys in Chromium). The runner starts a fresh instance, plays the suites and tears everything down; its exit code is 0 if and only if everything passes. No Composer dependency: the assertions and the HTTP client fit in `tests/lib.php`.
- The cryptography of `public/assets/stashbin.js` is now covered: encrypt/decrypt round trips on varied texts, rejection of an altered password, key, vector or ciphertext, and checks on the announced sizes (256-bit key, 128-bit salt, 96-bit vector, 310,000 iterations).
- Security headers: strict CSP, `X-Content-Type-Options`, `Referrer-Policy`, `HttpOnly`/`SameSite` cookies.
- English `README.md` with a French translation alongside it in `README.fr.md`, kept in step by three consistency tests (identical section structure, identical announced test counts, mutual links).

### Fixed

- The interface was uncomfortable on a phone, without ever being broken. Fields inherited the 0.9rem of the label surrounding them and were rendered at 14.4px: below 16px, Safari iOS zooms the page in when a field takes focus and never zooms back out. They are now at 16px. Tap targets — buttons, the sign-out link, the "burn after reading" row — reach 44px on a touch pointer, the card no longer sits flush against the edges of a narrow screen, the text area is capped at half the viewport height so that a landscape phone still shows the submit button, and `:hover` is now behind `@media (hover: hover)`, since a tap used to leave the hovered shade stuck on the button. Five browser tests measure all this on a 360×640 screen, four of which fail against the previous stylesheet.

- The nginx configuration example for production did not set `client_max_body_size`: since nginx caps request bodies at 1 MiB by default, any secret between 1 and 2 MiB was rejected with a `413` before reaching PHP, even though `config.php` allows 2 MiB.
- The test bench's smoke journey (`containers/stashbin.sh test`) matched the API's error *messages*, which became translated: with English as the fallback, `grep 'introuvable'` no longer matched and two checks failed silently on every combination. It now matches the stable `code` field, exactly as the browser JavaScript does.

### Security

- The deletion link now requires an authenticated session in addition to the token: a link that leaks is unusable by a third party who is not signed in.
- A secret's owner may delete it from their inventory without holding the deletion token, and only their own: ownership and the token are two separate credentials, and neither grants the other. A signed-in stranger deletes nothing that is not theirs, and erases nothing from anybody else's history.
- The nginx example for production now places `try_files $uri =404;` before `fastcgi_pass`, so that a URL shaped like `/nonexistent/x.php` cannot cause another file to be executed.
