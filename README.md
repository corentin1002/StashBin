# 🔐 StashBin

***English** · [Français](README.fr.md)*

> End-to-end encrypted secret sharing — creating a secret is restricted to authorised users (or open to everyone, your call), reading one only takes the link.

Inspired by [PrivateBin](https://github.com/PrivateBin/PrivateBin), with one key difference: **only authenticated accounts can create secrets**. This is not a fork — the code is a complete, independent rewrite with no line taken from the original project. No framework, no Composer dependency: PHP, SQLite, and the browser's WebCrypto.

---

## ✨ Features

- **End-to-end encryption** — the text is encrypted in the browser (AES-256-GCM); the server stores ciphertext only and can never read secrets.
- **Authenticated creation** — accounts are managed from the CLI; without one, no secret can be created. Disabled by a single line (`'auth' => false`) for an open instance, when access is already restricted some other way.
- **Read by link** — the decryption key travels in the URL's `#` fragment, which is never sent to the server.
- **Optional password** — mixed into key derivation (PBKDF2-SHA256, 310,000 iterations); with it, the link alone is not enough.
- **Expiry** — from 1 hour to never, with automatic purging.
- **Burn after reading** — with a confirmation screen before the secret is consumed.
- **Deletion link** — handed to the creator, usable only by a signed-in user.
- **Multilingual interface** — English and French, chosen from the browser's language; adding a language means dropping a file into `src/lang/`.

## 🔍 How it works

```
┌─ Browser (creator) ─────────────────┐         ┌─ Server ─────────────────┐
│ random key K (256 bits)             │         │                          │
│ + optional password                 │  POST   │ stores the ciphertext,   │
│ → PBKDF2 → AES-256-GCM → ciphertext ┼────────▶│ never sees K nor the     │
│                                     │         │ password                 │
│ link = view.php?id=xxx#K            │         └──────────────────────────┘
└─────────────────────────────────────┘
                                                ┌─ Browser (reader) ───────┐
   The #K fragment is never transmitted to      │ reads K from the URL,    │
   the server: it stays in the browser.         │ decrypts locally         │
                                                └──────────────────────────┘
```

| Step | Detail |
|---|---|
| Encryption | AES-256-GCM, random 96-bit IV |
| Key derivation | PBKDF2-SHA256, 310,000 iterations, 128-bit salt |
| URL key | 256 random bits, base64url-encoded in the `#` fragment |
| Storage | SQLite: encrypted payload + metadata (expiry, burn) |

## 🚀 Quick start

### With Podman (recommended for trying it out)

```bash
./containers/stashbin.sh up          # PHP 8.4 + Apache
./containers/stashbin.sh user alice  # create an account
```

→ **http://127.0.0.1:8081**

The code is mounted from the project: any change shows up immediately, with no rebuild. The SQLite database lives in a volume, outside the code.

```bash
./containers/stashbin.sh up 8.5 nginx  # another version, another server
./containers/stashbin.sh down          # stop
./containers/stashbin.sh reset         # start over
./containers/stashbin.sh clean         # remove everything once you are done
```

`./containers/stashbin.sh test` replays the full journey — sign in, create, read back, burn after reading — across all eight combinations of version and server. See [`containers/README.md`](containers/README.md).

## 🧪 Tests

```bash
./tests/run.sh          # 183 tests, a few minutes
./tests/run.sh --help   # options: version, server, full matrix…
```

The runner starts a fresh instance, plays the five suites and tears everything down: nothing to set up, nothing to clean. The exit code is `0` if and only if everything passes.

| Suite | Tests | Scope |
|---|--:|---|
| Unit | 56 | Functions in `src/bootstrap.php`: escaping, configuration, environment overrides, schema, purging, CSRF, language |
| API | 44 | Business rules over HTTP: authentication, validation, lifetimes, burn after reading, deletion, error codes |
| Security | 28 | Nothing outside `public/`, headers, session fixation, hashed storage, injection, language selection |
| Open instance | 19 | A second container without authentication: free creation, CSRF still required, guarantees unchanged |
| Browser | 36 | Real Chromium: end-to-end cryptography, interface journeys, language served |

The browser tests exercise the part nothing else covers — `deriveKey`, `encryptText`, `decryptPayload` — and check that a ciphertext altered by a single bit is rejected. The whole set has been validated by mutation: eighteen regressions deliberately introduced into the code, eighteen caught. See [`tests/README.md`](tests/README.md).

### With PHP alone

Requirements: PHP ≥ 8.1 with `pdo_sqlite` (`php-cli` + `php-pdo` on Fedora, `php-cli` + `php-sqlite3` on Debian/Ubuntu). No Composer dependency.

```bash
php bin/user.php add alice          # create an authorised account
php -S localhost:8080 -t public     # development server
```

## ✅ Compatibility

Verified by running the full application journey, with no deprecation and no warning:

| PHP | 8.1 | 8.2 | 8.3 | 8.4 | 8.5 | 8.6 |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| Compatible | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |

Under **Apache + mod_php** as well as **nginx + PHP-FPM** (tested from 8.3 to 8.6 on both servers). PHP 8.6 is tested as a *release candidate*, its final version being expected on 19 November 2026.

PHP 8.3 left active support on 31 December 2025 (security fixes until 31 December 2027): **8.4 is the recommended choice**, with security support until 31 December 2028.

One detail worth knowing when moving up from PHP 8.3: from 8.4 onwards, the default bcrypt cost of `password_hash()` goes from 10 to 12. Existing accounts keep working without intervention, but retain the old cost until their password is changed.

## 📦 Production deployment

> **⚠️ Two non-negotiable rules**
> 1. The document root must point at **`public/`**, never at the project root (otherwise `config.php` and the SQLite database would be exposed).
> 2. Serve over **HTTPS**: the decryption key travels in the URL on the client side.

The `data/` directory must be writable by the PHP user (`www-data`, `apache`…).

<details>
<summary><strong>Apache example</strong></summary>

```apache
<VirtualHost *:443>
    ServerName stash.example.com
    DocumentRoot /var/www/StashBin/public
    <Directory /var/www/StashBin/public>
        Require all granted
    </Directory>
    # + SSL configuration
</VirtualHost>
```
</details>

<details>
<summary><strong>nginx example</strong></summary>

```nginx
server {
    listen 443 ssl;
    server_name stash.example.com;
    root /var/www/StashBin/public;
    index index.php;

    # Required: nginx caps request bodies at 1 MiB by default and returns a
    # 413 beyond that. Without this line, any secret over 1 MiB would be
    # rejected before reaching PHP, even though config.php allows 2 MiB.
    # Keep this value above `max_size`.
    client_max_body_size 8m;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        # try_files before fastcgi_pass: without it, a URL shaped like
        # /nonexistent/x.php can cause another file to be executed.
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```
</details>

## 👥 User management

```bash
php bin/user.php add <name>      # create an account
php bin/user.php passwd <name>   # change the password
php bin/user.php del <name>      # revoke
php bin/user.php list            # list
```

In a container, the same subcommands go through the test-bench script, which runs them as `www-data`:

```bash
./containers/stashbin.sh user add alice
./containers/stashbin.sh user passwd alice
./containers/stashbin.sh user del alice
./containers/stashbin.sh user list
```

> Identity matters: an account created as `root` leaves the database unwritable by the web server, and the failure only shows up when creating a secret — not when signing in.

## ⚙️ Configuration

Everything happens in `config.php`, which holds nothing but literal values: write the one you want, there is nothing else to do.

| Setting | Default | Effect |
|---|---|---|
| `db` | `data/stashbin.sqlite` | Path to the SQLite database |
| `auth` | `true` | Authentication required to create and delete |
| `max_size` | 2 MiB | Maximum size of the encrypted payload |
| `expirations` / `default_expiration` | 1 h → never, `1w` | Lifetimes on offer |
| `default_locale` | `en` | Language served when the browser's is not translated |
| `session_name` | `stashbin` | Name of the session cookie |

Three of them also accept an environment variable, which is indispensable in a container where the file is mounted read-only: `STASHBIN_DB` for the database path, `STASHBIN_AUTH` for authentication, `STASHBIN_LOCALE` for the fallback language. The variable wins over the file when it is set and non-empty; absent, it changes nothing.

### Interface language

The interface is served in the visitor's language, deduced from the `Accept-Language` header their browser sends. A regional tag falls back to its language — `fr-CA` is served as `fr`.

**When none of the requested languages is translated, English is served** (`default_locale`), as the more widely read of the two. A German- or Spanish-speaking visitor therefore gets the English interface, never a half-translated page.

The `?lang=` parameter forces the language of a page, whatever the browser says:

```
https://example.org/index.php?lang=fr
```

That choice is stateless: neither a cookie nor a session remembers it, it is simply carried over onto the links the application builds itself.

**Adding a language** — drop a file into `src/lang/`, named after its tag (`de.php`, `pt-br.php`), returning the same array of keys as `src/lang/fr.php`. Nothing else: the code discovers the languages on offer by listing that directory. An incomplete translation is accepted, missing keys being borrowed from another language rather than shown as identifiers.

Two roles not to be confused:

- **`default_locale` (`en`) is the language *served*** when negotiation finds nothing.
- **`src/lang/fr.php` is the *reference* language**: it is the one a partial translation borrows its missing strings from. StashBin is written in French, its strings are born there, and other languages translate them. Every key used in the code must therefore appear in it.

A unit test checks that every language on offer translates the reference in full, and that its `{placeholders}` match.

### Open instance, without authentication

```php
'auth' => false,        // in config.php
```

```bash
STASHBIN_AUTH=0 …                     # or through the environment
AUTH=0 ./containers/stashbin.sh up    # or on the test bench
```

Creation and deletion then become available to any visitor: no more accounts, no more sign-in page, `login.php` redirects to the creation page, and the deletion link requires nothing but its token.

> **⚠️ Only do this if access to the instance is already restricted some other way** — internal network, VPN, authenticating proxy. On the public Internet, this is a secret store open to writing by anyone.

Everything else is unchanged: encryption still happens in the browser, the server still reads nothing, the deletion token is still stored hashed, and CSRF protection and hardening headers stay in place. Those guarantees are verified separately against an open instance by the `tests/noauth.test.php` suite.

## 🗂 Structure

```
config.php          settings (authentication, expiries, max size,
                    database path, fallback language)
containers/         multi-version test bench (see containers/README.md)
├── stashbin.sh     single driver: up, user, logs, down, reset, clean,
│                   list, test
├── Containerfile.apache    Apache + mod_php
├── Containerfile.nginx     nginx + PHP-FPM
├── nginx.conf              nginx server configuration
└── *-entrypoint.sh         startup for both stacks
public/             document root: pages, API, assets
├── index.php       secret creation (authenticated)
├── view.php        public reading
├── api.php         JSON API (create, read, delete)
├── login.php       sign-in
└── assets/         WebCrypto encryption + styles
tests/              full test set (see its README)
├── run.sh          single runner: builds, plays, cleans up
├── unit.test.php   functions in src/bootstrap.php
├── api.test.php    business rules of the API
├── security.test.php  security guarantees
├── noauth.test.php    behaviour of the open instance
├── browser.test.mjs   cryptography and journeys, in Chromium
└── lib.php         assertions and HTTP client, dependency-free
src/bootstrap.php   database, sessions, CSRF, language, helpers
src/lang/           one file per language on offer (fr.php is the reference)
bin/user.php        account management from the CLI
data/               SQLite database (created automatically)
└── .htaccess       "Require all denied": a guard rail in case the document
                    root is misconfigured and points at the project root
```

## 🛡 Security model

- The server **never** sees the plaintext, the key, or the optional password.
- Creating a secret requires an account; reading one only requires the link (plus the password, if any).
- Deleting requires being signed in **and** holding the token handed to the creator.
- Those last two rules — and only those — fall away if the operator sets `'auth' => false`: an explicit choice, never the shipped default.
- `HttpOnly`/`SameSite` sessions, CSRF tokens, a strict CSP, hashed passwords (`password_hash`).
- What a compromised server can do: delete secrets, serve malicious JavaScript to future visitors. This is the same limit as PrivateBin — server integrity still matters.

## 📄 Licence

Distributed under the [MIT](LICENSE) licence.

StashBin is an independent implementation, written from scratch. The
[PrivateBin](https://github.com/PrivateBin/PrivateBin) project (zlib/libpng licence)
inspired its concept and security model, but no code comes from it: the mention
above is an acknowledgement, not a licence obligation.
