#!/usr/bin/env bash
# StashBin's full test set.
#
#   ./tests/run.sh                    everything, on the reference version
#   ./tests/run.sh --version 8.6      on another PHP version
#   ./tests/run.sh --server nginx     behind nginx rather than Apache
#   ./tests/run.sh --db mariadb       against MariaDB rather than SQLite
#   ./tests/run.sh --no-browser       without the browser tests (faster)
#   ./tests/run.sh --matrix           PHP suites on all eight combinations
#   ./tests/run.sh --keep             leaves the instance alive for inspection
#
# Exit code: 0 if and only if everything passes.
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CTX="$REPO/containers"

VERSIONS=(8.3 8.4 8.5 8.6)
SERVERS=(apache nginx)
DATABASES=(sqlite mariadb)
REF_VERSION=8.4
REF_SERVER=apache
REF_DB=sqlite

NET=stashbin-tests-net
APP=stashbin-tests-app
VOL=stashbin-tests-data
BROWSER_IMAGE=stashbin-tests-browser

# The database server, when the suites are not run against SQLite. Its
# credentials are those of a container that lives for the length of one run.
DB=stashbin-tests-db
DB_IMAGE=docker.io/library/mariadb:11
DB_SCHEMA=stashbin
DB_USER=stashbin
DB_PASS=stashbin
DB_ROOT=rootpassword

USER_NAME=tester
USER_PASS=testpassword

RED=$'\e[31m'; GREEN=$'\e[32m'; BOLD=$'\e[1m'; OFF=$'\e[0m'
info() { printf '%s\n' "$*"; }

keep=0
# Defined before die(), which uses it: bash resolves calls at run time, and an
# argument error happens before the rest of the file has been read.
teardown() {
    (( keep )) && { info "Instance left alive: podman exec -it $APP bash"; return; }
    podman rm -f "$APP" >/dev/null 2>&1
    podman rm -f "$DB" >/dev/null 2>&1
    podman volume rm -f "$VOL" >/dev/null 2>&1
    podman network rm -f "$NET" >/dev/null 2>&1
}
trap teardown EXIT

die() { printf '%s%s%s\n' "$RED" "$*" "$OFF" >&2; exit 2; }

# The help text is the comment banner at the top of this file: a single source.
usage() { awk '/^#!/ {next} /^#/ {sub(/^# ?/, ""); print; next} {exit}' "$0"; }

version=$REF_VERSION
server=$REF_SERVER
database=$REF_DB
browser=1
matrix=0

while (( $# )); do
    case $1 in
        --version) version=${2:?missing version}; shift 2 ;;
        --server)  server=${2:?missing server}; shift 2 ;;
        --db)      database=${2:?missing database}; shift 2 ;;
        --no-browser) browser=0; shift ;;
        --matrix)  matrix=1; shift ;;
        --keep)    keep=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) die "Unknown option: \"$1\" (see $0 --help)" ;;
    esac
done

contains() { local n=$1; shift; for x in "$@"; do [[ $x == "$n" ]] && return 0; done; return 1; }
contains "$version" "${VERSIONS[@]}" || die "Unknown version: \"$version\". Expected: ${VERSIONS[*]}"
contains "$server" "${SERVERS[@]}"   || die "Unknown server: \"$server\". Expected: ${SERVERS[*]}"
contains "$database" "${DATABASES[@]}" || die "Unknown database: \"$database\". Expected: ${DATABASES[*]}"

# Told to the application through the environment, config.php being mounted
# read-only. Empty for SQLite, which needs nothing said.
db_env=()
if [[ $database == mariadb ]]; then
    db_env=(
        -e "STASHBIN_DB_DRIVER=mariadb"
        -e "STASHBIN_DB_HOST=$DB"
        -e "STASHBIN_DB_NAME=$DB_SCHEMA"
        -e "STASHBIN_DB_USER=$DB_USER"
        -e "STASHBIN_DB_PASS=$DB_PASS"
    )
fi

php_tag() {
    local v=$1 s=$2 base
    [[ $v == 8.6 ]] && base=8.6-rc || base=$v
    [[ $s == apache ]] && echo "${base}-apache" || echo "${base}-fpm"
}

# Starts the database server, once per run, and waits until it answers. The
# tables are never created here: making them on an empty database is exactly
# what the application is expected to do on its own.
start_db() {
    podman ps --format '{{.Names}}' | grep -qx "$DB" && return 0
    podman rm -f "$DB" >/dev/null 2>&1
    podman run -d --name "$DB" --network "$NET" \
        -e "MARIADB_ROOT_PASSWORD=$DB_ROOT" \
        -e "MARIADB_DATABASE=$DB_SCHEMA" \
        -e "MARIADB_USER=$DB_USER" \
        -e "MARIADB_PASSWORD=$DB_PASS" \
        "$DB_IMAGE" >/dev/null || die "Could not start $DB_IMAGE."

    local i
    for i in $(seq 90); do
        podman exec "$DB" mariadb -u root -p"$DB_ROOT" -e 'SELECT 1' >/dev/null 2>&1 && return 0
        sleep 1
    done
    podman logs --tail 20 "$DB" >&2
    die "The database is not answering."
}

# Hands the next suite an empty database, as wiping the volume does for SQLite.
# Dropping the database takes the tables with it: what the application creates
# on the way back up is what the tests then read.
reset_db() {
    podman exec "$DB" mariadb -u root -p"$DB_ROOT" -e "
        DROP DATABASE IF EXISTS \`$DB_SCHEMA\`;
        CREATE DATABASE \`$DB_SCHEMA\` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
        GRANT ALL PRIVILEGES ON \`$DB_SCHEMA\`.* TO '$DB_USER'@'%';" >/dev/null 2>&1 \
        || die "Could not empty the database."
}

# Starts a fresh instance and waits until it really answers.
# The arguments after the version and the server are passed through to
# "podman run" as-is (the "noauth" suite uses that to set STASHBIN_AUTH=0).
start_app() {
    local v=$1 s=$2 image="stashbin-tests:${1}-${2}"
    shift 2

    podman build --quiet -f "$CTX/Containerfile.$s" \
        --build-arg "PHP_TAG=$(php_tag "$v" "$s")" -t "$image" "$CTX" >/dev/null 2>&1 \
        || die "Could not build image $image."

    podman rm -f "$APP" >/dev/null 2>&1
    podman volume rm -f "$VOL" >/dev/null 2>&1
    podman network exists "$NET" >/dev/null 2>&1 || podman network create "$NET" >/dev/null

    if [[ $database == mariadb ]]; then
        start_db
        reset_db
    fi

    podman run -d --name "$APP" --network "$NET" ${db_env[@]+"${db_env[@]}"} "$@" \
        -v "$REPO:/var/www/stashbin:ro,z" \
        -v "$VOL:/var/lib/stashbin:z" \
        "$image" >/dev/null || die "Could not start the container."

    local i
    for i in $(seq 60); do
        podman exec "$APP" curl -fsS -o /dev/null http://127.0.0.1/login.php 2>/dev/null && break
        sleep 0.25
        (( i == 60 )) && { podman logs --tail 20 "$APP" >&2; die "The application is not answering."; }
    done

    printf '%s\n' "$USER_PASS" | podman exec -i -u www-data "$APP" \
        php /var/www/stashbin/bin/user.php add "$USER_NAME" >/dev/null 2>&1 \
        || die "Could not create the test account."
}

# Runs a PHP test file inside the container, under the server's identity.
# The arguments after the suite name are appended to "podman exec".
run_php_suite() {
    local suite=$1
    shift
    podman exec -u www-data \
        -e "STASHBIN_URL=http://127.0.0.1" \
        -e "STASHBIN_USER=$USER_NAME" \
        -e "STASHBIN_PASS=$USER_PASS" \
        "$@" \
        "$APP" php "/var/www/stashbin/tests/$suite"
}

# The three ordinary suites, then "noauth" on a fresh instance started without
# authentication: the setting is read when the server starts, so a second
# container is needed rather than a hot switch.
run_php_suites() {
    local v=$1 s=$2 prefix=${3:-}
    start_app "$v" "$s"
    for suite in unit.test.php api.test.php security.test.php; do
        if run_php_suite "$suite"; then
            record "$prefix${suite%.test.php}" OK
        else
            record "$prefix${suite%.test.php}" FAILED
        fi
    done

    start_app "$v" "$s" -e STASHBIN_AUTH=0
    if run_php_suite noauth.test.php -e STASHBIN_AUTH=0; then
        record "${prefix}noauth" OK
    else
        record "${prefix}noauth" FAILED
    fi
}

run_browser_suite() {
    podman build --quiet -f "$REPO/tests/Containerfile.browser" \
        -t "$BROWSER_IMAGE" "$REPO/tests" >/dev/null 2>&1 \
        || die "Could not build the browser test image."

    # "--network container:" makes the browser share the application's network
    # namespace: the app is therefore reachable on 127.0.0.1, an origin
    # browsers consider secure. Without this, crypto.subtle is unavailable and
    # nothing can be encrypted.
    #
    # The script is copied next to the dependencies: ES modules resolve by
    # walking up the tree, and /app is mounted read-only.
    podman run --rm --network "container:$APP" \
        -v "$REPO:/app:ro,z" \
        -e "STASHBIN_URL=http://127.0.0.1" \
        -e "STASHBIN_USER=$USER_NAME" \
        -e "STASHBIN_PASS=$USER_PASS" \
        "$BROWSER_IMAGE" \
        sh -c 'cp /app/tests/browser.test.mjs /opt/harness/ && node /opt/harness/browser.test.mjs'
}

# ---------------------------------------------------------------------------
declare -a RESULTS
failed=0

record() {
    RESULTS+=("$1|$2")
    [[ $2 == OK ]] || failed=1
}

info "${BOLD}Jeu de test StashBin${OFF}"

if (( matrix )); then
    info "PHP suites on all eight combinations ($database), browser tests on $REF_VERSION/$REF_SERVER."
    for v in "${VERSIONS[@]}"; do
        for s in "${SERVERS[@]}"; do
            info ""
            info "${BOLD}══ PHP $v / $s ══${OFF}"
            run_php_suites "$v" "$s" "$v/$s "
        done
    done
    # The browser tests do not depend on the PHP version: one pass is enough.
    if (( browser )); then
        info ""
        info "${BOLD}══ Browser ($REF_VERSION/$REF_SERVER) ══${OFF}"
        start_app "$REF_VERSION" "$REF_SERVER"
        if run_browser_suite; then record "browser" OK; else record "browser" FAILED; fi
    fi
else
    info "PHP $version / $server / $database$( (( browser )) || echo ' — without the browser tests')"
    run_php_suites "$version" "$server"
    if (( browser )); then
        # The "noauth" suite left an open instance behind: the browser, for its
        # part, plays the authenticated journey and needs an ordinary one.
        start_app "$version" "$server"
        if run_browser_suite; then record "browser" OK; else record "browser" FAILED; fi
    elif (( keep )); then
        start_app "$version" "$server"
    fi
fi

# ---------------------------------------------------------------------------
info ""
info "${BOLD}Summary${OFF}"
printf '%s\n' "${RESULTS[@]}" | column -t -s'|' | sed 's/^/  /'
info ""
if (( failed )); then
    printf '%sAt least one suite failed.%s\n' "$RED" "$OFF"
    exit 1
fi
printf '%sEvery suite passes.%s\n' "$GREEN" "$OFF"
exit 0
