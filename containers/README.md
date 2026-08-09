# Multi-version test bench

Run StashBin on any combination of **PHP 8.3 / 8.4 / 8.5 / 8.6** and **Apache / nginx**, without typing a single `podman` command.

Everything goes through one script: `containers/stashbin.sh`.

## Getting started

```bash
./containers/stashbin.sh up            # PHP 8.4 + Apache, the defaults
./containers/stashbin.sh up 8.5 nginx  # or any other combination
```

The script builds the image if needed, starts the container, waits until the server really answers, then prints the URL. It only hands control back once the service is reachable — no "it should be ready by now".

Next, an account is needed to create secrets:

```bash
./containers/stashbin.sh user alice    # the password is prompted for
```

→ **http://127.0.0.1:8081**

To try out the open instance, the one that asks for no account:

```bash
AUTH=0 ./containers/stashbin.sh up
```

The script sets `STASHBIN_AUTH=0` in the container, which overrides `config.php`'s `'auth' => true` without touching it — the repository is mounted read-only.

To run the instance on **MariaDB** rather than SQLite:

```bash
DB=mariadb ./containers/stashbin.sh up
```

A `stashbin-db` container is started on a network of its own, and the instance is pointed at it through `STASHBIN_DB_DRIVER` and its companions. Its data lives in the `stashbin-db-data` volume, so it survives `down` and version changes like the SQLite one does; `reset` and `clean` take it away. The database is created empty — StashBin makes its own tables on first use.

## The commands

| Command | Effect |
|---|---|
| `up [version] [server]` | Builds and starts. Default: `8.4 apache` (`DB=mariadb` for a database server) |
| `user add\|passwd\|del <name>` | Manages the accounts of the running instance |
| `user list` | Lists the accounts |
| `logs [-f]` | Container logs |
| `down` | Stops the instance |
| `reset` | Wipes the database (accounts and secrets) |
| `clean [--all]` | Removes the bench's containers, volumes and images |
| `list` | Available combinations and current state |
| `test [version…]` | Replays the full journey across the whole matrix |

`user` relays `bin/user.php`'s subcommands into the container, running them under the `www-data` identity. `user <name>` with no subcommand is shorthand for `user add <name>`.

`up` replaces the previous instance: only one runs at a time, so there is never any wondering which one answers on which port.

## Testing the whole matrix

```bash
./containers/stashbin.sh test          # all 8 combinations
./containers/stashbin.sh test 8.5 8.6  # only those versions
```

For each combination the script builds the image, starts the stack and replays a real journey against the web server — sign-in with a CSRF token, creating a secret, reading it back, burn after reading, rejecting an invalid identifier, serving static files, and checking that `config.php` and `src/` are not exposed outside `public/`. It ends with a summary table and exits with an error if anything fails.

```
  8.4  apache  OK (8.4.24)
  8.4  nginx   OK (8.4.24)
  8.5  apache  OK (8.5.9)
  ...
```

The journey matches the API's **error codes**, never its messages: those are translated and follow the caller's `Accept-Language`. Matching `introuvable` there is exactly what broke when the interface became multilingual.

## Tidying up afterwards

```bash
./containers/stashbin.sh clean        # containers, volumes and images built here
./containers/stashbin.sh clean --all  # + the php:* base images downloaded
```

`clean` only touches what the test bench made: the `stashbin-test` and `stashbin-selftest` containers, the `stashbin-test-data` and `stashbin-selftest-data` volumes, and the `stashbin:<version>-<server>` images. A volume or an image you created elsewhere is never affected. The command announces every real removal and answers "Nothing to clean up" when there is nothing to do, so it can be run again safely.

`--all` adds the official `php:<version>-apache` and `php:<version>-fpm` tags the bench downloads itself. This is not an expensive thing to undo: podman keeps the shared layers, and a subsequent `up` rebuilds in seconds.

## What you need to know

**The code is mounted, not copied.** A change in `public/` or `src/` shows up on page reload, with no rebuild. Only a change inside `containers/` calls for a new `up`.

**The database survives version changes.** The `stashbin-test-data` volume is shared: moving from 8.4 to 8.6 keeps the accounts and the secrets, which is what makes it possible to test an upgrade against existing data. `reset` starts over from scratch.

**Ports.** The interactive instance listens on 8081, the test matrix on 8099, on `127.0.0.1` only. In case of a conflict:

```bash
PORT=8082 ./containers/stashbin.sh up
TEST_PORT=9099 ./containers/stashbin.sh test
```

**`AUTH`.** `AUTH=0 ./containers/stashbin.sh up` starts an instance without authentication (default: `1`). The variable only concerns `up`: `test` forces authentication on, since the journey it replays starts with a sign-in.

**`DB`.** `DB=mariadb` starts a MariaDB server beside the instance (default: `sqlite`, which needs nothing). It applies to `test` as well, which then replays the whole journey against it.

**PHP 8.6 is not out** (final expected on 19 November 2026): `up 8.6` uses the `php:8.6-rc` image. The script does the translation, there is nothing to adjust.

## The two images

| File | Stack | Base |
|---|---|---|
| `Containerfile.apache` | Apache + mod_php, a single process | `php:<version>-apache` |
| `Containerfile.nginx` | nginx + PHP-FPM in one container | `php:<version>-fpm` |

Both take the version as a build argument (`--build-arg PHP_TAG=…`), serve `public/` as the web root, and put the SQLite database in `/var/lib/stashbin` (a volume), outside the code. Both also carry `pdo_mysql`, which the official images do not build in, beside the `pdo_sqlite` they do.

Two details are worth pointing out, because each costs an hour of debugging when discovered the hard way:

- **Apache is configured with `AllowOverride None`.** Without it, on a read-only mount, it tries to read a `.htaccess`, fails, and returns `403` on *every* page with the message "Server unable to read htaccess file, denying access to be safe".
- **Both entrypoints fix the volume's ownership at startup.** If the database was created by root, PHP then runs as `www-data` and fails with `attempt to write a readonly database` — but only when creating a secret, not when signing in. That is also why `stashbin.sh user` runs as `www-data`.

## Building by hand

If you would rather do without the script:

```bash
podman build -f containers/Containerfile.nginx \
    --build-arg PHP_TAG=8.5-fpm -t stashbin:8.5-nginx containers/

podman run -d --name stashbin -p 127.0.0.1:8081:80 \
    -v "$PWD:/var/www/stashbin:ro,z" \
    -v stashbin-test-data:/var/lib/stashbin:z \
    stashbin:8.5-nginx

podman exec -it -u www-data stashbin \
    php /var/www/stashbin/bin/user.php add alice
```

The build context is `containers/` (not the repository root): the images contain nothing but the server configuration, never the application code.
