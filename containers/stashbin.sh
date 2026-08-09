#!/usr/bin/env bash
# StashBin test bench: one command per intent, no podman to type.
# See containers/README.md.
set -euo pipefail

VERSIONS=(8.3 8.4 8.5 8.6)
SERVERS=(apache nginx)
DEFAULT_VERSION=8.4
DEFAULT_SERVER=apache

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CTX="$REPO/containers"
NAME=stashbin-test           # a single interactive instance at a time
VOLUME=stashbin-test-data    # accounts and secrets, kept between runs
TEST_NAME=stashbin-selftest  # ephemeral instance used by "test"
TEST_VOLUME=stashbin-selftest-data
IMAGE_PREFIX=stashbin        # images built here: stashbin:<version>-<server>
PORT="${PORT:-8081}"
AUTH="${AUTH:-1}"            # AUTH=0: open instance, without authentication
DB="${DB:-sqlite}"           # DB=mariadb: a database server beside the instance

# Only used when DB is not sqlite. The application then reaches the server by
# name over a network of our own, and its data lives in a volume like the
# SQLite file does.
NET=stashbin-net
DB_NAME=stashbin-db
DB_VOLUME=stashbin-db-data
DB_IMAGE=docker.io/library/mariadb:11
DB_SCHEMA=stashbin
DB_USER=stashbin
DB_PASS=stashbin
DB_ROOT=rootpassword

RED=$'\e[31m'; GREEN=$'\e[32m'; YELLOW=$'\e[33m'; BOLD=$'\e[1m'; OFF=$'\e[0m'
info() { printf '%s\n' "$*"; }
ok()   { printf '%s%s%s\n' "$GREEN" "$*" "$OFF"; }
warn() { printf '%s%s%s\n' "$YELLOW" "$*" "$OFF"; }
die()  { printf '%s%s%s\n' "$RED" "$*" "$OFF" >&2; exit 1; }

contains() { local n=$1; shift; for x in "$@"; do [[ $x == "$n" ]] && return 0; done; return 1; }

# PHP 8.6 is not out yet: its images carry the -rc suffix.
php_tag() {
    local version=$1 server=$2 base
    [[ $version == 8.6 ]] && base=8.6-rc || base=$version
    [[ $server == apache ]] && echo "${base}-apache" || echo "${base}-fpm"
}

check_args() {
    contains "$1" "${VERSIONS[@]}" \
        || die "Unknown PHP version: \"$1\". Expected: ${VERSIONS[*]}"
    contains "$2" "${SERVERS[@]}" \
        || die "Unknown server: \"$2\". Expected: ${SERVERS[*]}"
    contains "$DB" sqlite mariadb \
        || die "Unknown database: \"$DB\". Expected: sqlite mariadb"
}

# Starts the database server, if one is called for, and waits until it answers.
# The schema is never created here: an empty database is all StashBin asks for,
# and it makes its own tables on first use.
ensure_db() {
    # Written with "if" rather than "&&": under "set -e", a list ending on a
    # false test is enough to bring the whole script down.
    if [[ $DB == sqlite ]]; then
        return 0
    fi
    podman network exists "$NET" >/dev/null 2>&1 || podman network create "$NET" >/dev/null
    if podman ps --format '{{.Names}}' | grep -qx "$DB_NAME"; then
        return 0
    fi

    podman rm -f "$DB_NAME" >/dev/null 2>&1 || true
    podman run -d --name "$DB_NAME" --network "$NET" \
        -e "MARIADB_ROOT_PASSWORD=$DB_ROOT" \
        -e "MARIADB_DATABASE=$DB_SCHEMA" \
        -e "MARIADB_USER=$DB_USER" \
        -e "MARIADB_PASSWORD=$DB_PASS" \
        -v "$DB_VOLUME:/var/lib/mysql:z" \
        "$DB_IMAGE" >/dev/null || die "Could not start $DB_IMAGE."

    local i
    for i in $(seq 90); do
        podman exec "$DB_NAME" mariadb -u root -p"$DB_ROOT" -e 'SELECT 1' >/dev/null 2>&1 && return 0
        sleep 1
    done
    podman logs --tail 20 "$DB_NAME" >&2
    die "The database is not answering."
}

# How the application is told to reach it. Nothing to say for SQLite, which is
# a file the image already points at.
db_args() {
    if [[ $DB == sqlite ]]; then
        return 0
    fi
    printf '%s\n' --network "$NET" \
        -e STASHBIN_DB_DRIVER=mariadb \
        -e "STASHBIN_DB_HOST=$DB_NAME" \
        -e "STASHBIN_DB_NAME=$DB_SCHEMA" \
        -e "STASHBIN_DB_USER=$DB_USER" \
        -e "STASHBIN_DB_PASS=$DB_PASS"
}

port_busy() { (exec 3<>"/dev/tcp/127.0.0.1/$1") 2>/dev/null && exec 3>&- && return 0 || return 1; }

# Returns the image name on stdout; all progress goes to stderr, so that
# $(build ...) captures nothing but the name.
build() {
    local version=$1 server=$2 image="stashbin:${1}-${2}"
    printf 'Building %s (php:%s)…\n' "$image" "$(php_tag "$version" "$server")" >&2
    podman build --quiet \
        -f "$CTX/Containerfile.$server" \
        --build-arg "PHP_TAG=$(php_tag "$version" "$server")" \
        -t "$image" "$CTX" >/dev/null 2>&1 \
        || { printf 'Building %s failed:\n' "$image" >&2
             podman build -f "$CTX/Containerfile.$server" \
                 --build-arg "PHP_TAG=$(php_tag "$version" "$server")" \
                 -t "$image" "$CTX" >&2
             return 1; }
    echo "$image"
}

# Starts a container and waits until it really answers over HTTP.
start() {
    local image=$1 name=$2 port=$3 volume=$4 auth=${5:-1}
    ensure_db
    local extra=(); mapfile -t extra < <(db_args)
    podman rm -f "$name" >/dev/null 2>&1 || true
    podman run -d --name "$name" -p "127.0.0.1:$port:80" \
        -e "STASHBIN_AUTH=$auth" \
        ${extra[@]+"${extra[@]}"} \
        -v "$REPO:/var/www/stashbin:ro,z" \
        -v "$volume:/var/lib/stashbin:z" \
        "$image" >/dev/null

    # login.php redirects (302) on an open instance: "curl -f" only fails on
    # 4xx and 5xx codes, so the probe stays valid either way.
    for _ in $(seq 40); do
        if curl -fsS -o /dev/null "http://127.0.0.1:$port/login.php" 2>/dev/null; then
            return 0
        fi
        sleep 0.25
    done
    warn "The container is not answering. Log:"
    podman logs --tail 30 "$name" >&2 || true
    return 1
}

cmd_up() {
    local version=${1:-$DEFAULT_VERSION} server=${2:-$DEFAULT_SERVER}
    check_args "$version" "$server"

    if port_busy "$PORT" && ! podman ps --format '{{.Names}}' | grep -qx "$NAME"; then
        die "Port $PORT is already taken. Run again with: PORT=8082 $0 up $version $server"
    fi

    local image; image=$(build "$version" "$server")
    start "$image" "$NAME" "$PORT" "$VOLUME" "$AUTH" || die "Could not start."

    local real; real=$(podman exec "$NAME" php -r 'echo PHP_VERSION;')
    ok "StashBin is running: PHP $real + $server + $DB"
    info ""
    info "  ${BOLD}http://127.0.0.1:$PORT/${OFF}"
    info ""
    case $AUTH in
        0|false|off|no) warn "  Authentication disabled: creation is open to everyone." ;;
        *) info "  Create an account : $0 user <name>" ;;
    esac
    info "  View the logs     : $0 logs"
    info "  Switch version    : $0 up 8.5 nginx"
    info "  Stop              : $0 down"
}

running() { podman ps --format '{{.Names}}' | grep -qx "$NAME"; }

# Relays bin/user.php's subcommands into the running container.
cmd_user() {
    running || die "No instance is running. Start one first: $0 up"

    case "${1:-}" in
        "")
            die "Usage: $0 user <add|passwd|del|list> [name]" ;;
        add|passwd|del)
            [[ -n ${2:-} ]] || die "Usage: $0 user $1 <name>" ;;
        list)
            ;;
        *)
            # Shorthand: "user alice" means "user add alice".
            set -- add "$@" ;;
    esac

    # -u www-data: the CLI must write the database under the same identity as
    # the web server, otherwise the latter can no longer create secrets.
    # A pseudo-terminal is only allocated when there is one (add and passwd
    # prompt for the password), so this stays usable from a script.
    local flags=-i; [[ -t 0 ]] && flags=-it
    podman exec $flags -u www-data "$NAME" \
        php /var/www/stashbin/bin/user.php "$@"
}

cmd_logs() { podman logs "$@" "$NAME"; }

cmd_down() {
    # The database, if there is one, goes with the instance it serves; its
    # volume stays, exactly as the SQLite one does.
    podman rm -f "$DB_NAME" >/dev/null 2>&1 || true
    if podman rm -f "$NAME" >/dev/null 2>&1; then
        ok "Instance stopped."
        info "To remove everything (volumes and images): $0 clean"
    else
        info "Nothing to stop."
    fi
}

cmd_reset() {
    cmd_down >/dev/null 2>&1 || true
    podman volume rm -f "$VOLUME" >/dev/null 2>&1 || true
    podman volume rm -f "$DB_VOLUME" >/dev/null 2>&1 || true
    ok "Database wiped (accounts and secrets)."
}

# Removes everything this script created, and nothing else: the containers and
# volumes carry names of our own, and the images are filtered on the stashbin:
# prefix. A volume or an image we did not build is never touched.
cmd_clean() {
    local all=0
    case "${1:-}" in
        "")     ;;
        --all)  all=1 ;;
        *)      die "Usage: $0 clean [--all]" ;;
    esac

    # "podman rm -f" succeeds even on a missing target: we test for existence
    # first, so that the report only announces removals that really happened.
    local removed=0

    for c in "$NAME" "$TEST_NAME" "$DB_NAME"; do
        podman container exists "$c" 2>/dev/null || continue
        podman rm -f "$c" >/dev/null 2>&1 && { info "container $c"; removed=1; }
    done

    for v in "$VOLUME" "$TEST_VOLUME" "$DB_VOLUME"; do
        podman volume exists "$v" 2>/dev/null || continue
        podman volume rm -f "$v" >/dev/null 2>&1 && { info "volume $v"; removed=1; }
    done

    if podman network exists "$NET" 2>/dev/null; then
        podman network rm -f "$NET" >/dev/null 2>&1 && { info "network $NET"; removed=1; }
    fi

    local images
    images=$(podman images --format '{{.Repository}}:{{.Tag}}' \
             | grep -E "(^|/)${IMAGE_PREFIX}:[0-9]" || true)
    if [[ -n $images ]]; then
        while read -r img; do
            podman rmi -f "$img" >/dev/null 2>&1 && { info "image $img"; removed=1; }
        done <<< "$images"
    fi

    if (( all )); then
        # Only the official tags this test bench downloads itself.
        for v in "${VERSIONS[@]}"; do
            for s in "${SERVERS[@]}"; do
                local tag="docker.io/library/php:$(php_tag "$v" "$s")"
                podman image exists "$tag" 2>/dev/null || continue
                podman rmi -f "$tag" >/dev/null 2>&1 && { info "image $tag"; removed=1; }
            done
        done
    fi

    (( removed )) || { info "Nothing to clean up."; return 0; }
    ok "Cleanup done."
    (( all )) || info "The php:* base images are kept (\"$0 clean --all\" removes those too)."
}

cmd_list() {
    info "${BOLD}Available combinations${OFF}"
    for v in "${VERSIONS[@]}"; do
        for s in "${SERVERS[@]}"; do
            local note=""
            [[ $v == 8.6 ]] && note=" (release candidate)"
            [[ $v == 8.3 ]] && note=" (security support only)"
            printf '  %s up %-4s %-7s→ php:%s%s\n' "$0" "$v" "$s" "$(php_tag "$v" "$s")" "$note"
        done
    done
    info ""
    if podman ps --format '{{.Names}}' | grep -qx "$NAME"; then
        ok "Running: $(podman exec "$NAME" php -r 'echo PHP_VERSION;') on http://127.0.0.1:$PORT/"
    else
        info "No instance running."
    fi
}

# --- Automated test of the whole matrix --------------------------------------
# Replays the real journey (sign in, create, read back, burn) against the web
# server, not against PHP's built-in one.
smoke() {
    local port=$1 b="http://127.0.0.1:$1" jar; jar=$(mktemp)
    local fails=()

    local csrf; csrf=$(curl -s -c "$jar" "$b/login.php" \
        | sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p')
    [[ -n $csrf ]] || { echo "no CSRF token found on login.php"; rm -f "$jar"; return 1; }

    printf 'testpassword\n' | podman exec -i -u www-data "$2" \
        php /var/www/stashbin/bin/user.php add smoke >/dev/null 2>&1 || true

    local code; code=$(curl -s -b "$jar" -c "$jar" -o /dev/null -w '%{http_code}' \
        -d "csrf=$csrf&username=smoke&password=testpassword" "$b/login.php")
    [[ $code == 302 ]] || fails+=("sign-in (HTTP $code instead of 302)")

    code=$(curl -s -b "$jar" -o /dev/null -w '%{http_code}' "$b/index.php")
    [[ $code == 200 ]] || fails+=("index.php (HTTP $code)")

    local token; token=$(curl -s -b "$jar" "$b/index.php" \
        | sed -n 's/.*csrf-token" content="\([^"]*\)".*/\1/p')
    local resp; resp=$(curl -s -b "$jar" -X POST "$b/api.php" \
        -H "X-CSRF-Token: $token" -H 'Content-Type: application/json' \
        -d '{"payload":{"v":1,"iv":"AAA","salt":"BBB","iter":310000,"pwd":false,"ct":"Q0lQSEVS"},"expire":"1h","burn":true}')
    local id; id=$(echo "$resp" | sed -n 's/.*"id":"\([^"]*\)".*/\1/p')
    [[ -n $id ]] || fails+=("secret creation ($resp)")

    # The error codes are matched, never the messages: those are translated and
    # follow the caller's Accept-Language. Matching "introuvable" here is
    # exactly what broke when the interface became multilingual.
    if [[ -n $id ]]; then
        curl -s "$b/api.php?id=$id" | grep -q 'Q0lQSEVS' || fails+=("reading the secret back")
        curl -s "$b/api.php?id=$id" | grep -q '"code":"not_found"' || fails+=("burn after reading")
    fi

    curl -s "$b/api.php?id=%21%21" | grep -q '"code":"invalid_id"' || fails+=("rejecting an invalid identifier")
    curl -s -o /dev/null -w '%{http_code}' "$b/assets/style.css" | grep -q 200 || fails+=("serving static files")
    # --path-as-is: otherwise curl resolves the ".." itself and the server never
    # sees the attempt to climb out of public/.
    if curl -s --path-as-is "$b/../config.php" | grep -q 'STASHBIN_DB'; then
        fails+=("config.php served from outside public/")
    fi

    rm -f "$jar"
    (( ${#fails[@]} == 0 )) && return 0
    printf '%s\n' "${fails[@]}"
    return 1
}

cmd_test() {
    local versions=("$@"); (( $# )) || versions=("${VERSIONS[@]}")
    for v in "${versions[@]}"; do contains "$v" "${VERSIONS[@]}" || die "Unknown version: \"$v\""; done

    local tport=${TEST_PORT:-8099} tname=$TEST_NAME tvol=$TEST_VOLUME
    port_busy "$tport" && die "Port $tport is busy. Run again with: TEST_PORT=9099 $0 test"

    local rows=() failed=0
    for v in "${versions[@]}"; do
        for s in "${SERVERS[@]}"; do
            printf '  %-4s %-7s ' "$v" "$s"
            podman volume rm -f "$tvol" >/dev/null 2>&1 || true
            local image; image=$(build "$v" "$s" 2>/dev/null)
            # Authentication always on: the journey smoke() plays starts with a
            # sign-in, whatever AUTH says in the environment.
            if ! start "$image" "$tname" "$tport" "$tvol" 1 >/dev/null 2>&1; then
                printf '%sFAILED%s (the container will not start)\n' "$RED" "$OFF"
                rows+=("$v|$s|will not start"); failed=1; continue
            fi
            local real; real=$(podman exec "$tname" php -r 'echo PHP_VERSION;')
            local out
            if out=$(smoke "$tport" "$tname" 2>&1); then
                printf '%sOK%s      PHP %s\n' "$GREEN" "$OFF" "$real"
                rows+=("$v|$s|OK ($real)")
            else
                printf '%sFAILED%s  PHP %s\n' "$RED" "$OFF" "$real"
                printf '%s\n' "$out" | sed 's/^/           /'
                rows+=("$v|$s|FAILED: $(echo "$out" | head -1)")
                failed=1
            fi
            podman rm -f "$tname" >/dev/null 2>&1 || true
        done
    done
    podman volume rm -f "$tvol" >/dev/null 2>&1 || true

    info ""
    info "${BOLD}Summary${OFF}"
    printf '%s\n' "${rows[@]}" | column -t -s'|' | sed 's/^/  /'
    (( failed == 0 )) && ok "Every combination passes." || die "At least one combination failed."
}

usage() {
    cat <<EOF
${BOLD}StashBin — multi-version test bench${OFF}

  $0 up [version] [server]    start (default: $DEFAULT_VERSION $DEFAULT_SERVER)
  $0 user add <name>          create an account   (shorthand: $0 user <name>)
  $0 user passwd <name>       change its password
  $0 user del <name>          revoke the account
  $0 user list                list the accounts
  $0 logs [-f]                container logs
  $0 down                     stop the instance
  $0 reset                    wipe the database (accounts and secrets)
  $0 clean [--all]            remove the bench's containers, volumes and
                              images; --all includes the php:* base images
  $0 list                     available combinations and current state
  $0 test [version…]          replay the full journey across the whole matrix

Versions: ${VERSIONS[*]}        Servers: ${SERVERS[*]}
Variables: PORT (default 8081), TEST_PORT (default 8099),
           AUTH (default 1; "AUTH=0 $0 up" opens creation to everyone),
           DB (default sqlite; "DB=mariadb $0 up" runs a database server beside it)
EOF
}

case "${1:-}" in
    up)    shift; cmd_up "$@" ;;
    user)  shift; cmd_user "$@" ;;
    logs)  shift; cmd_logs "$@" ;;
    down)  cmd_down ;;
    reset) cmd_reset ;;
    clean) shift; cmd_clean "$@" ;;
    list)  cmd_list ;;
    test)  shift; cmd_test "$@" ;;
    ""|-h|--help|help) usage ;;
    *)     printf '%sUnknown command: "%s"%s\n\n' "$RED" "$1" "$OFF" >&2; usage >&2; exit 1 ;;
esac
