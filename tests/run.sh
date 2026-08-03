#!/usr/bin/env bash
# Jeu de test complet de StashBin.
#
#   ./tests/run.sh                    tout, sur la version de référence
#   ./tests/run.sh --version 8.6      sur une autre version de PHP
#   ./tests/run.sh --server nginx     derrière nginx plutôt qu'Apache
#   ./tests/run.sh --no-browser       sans les tests navigateur (plus rapide)
#   ./tests/run.sh --matrix           tests PHP sur les huit combinaisons
#   ./tests/run.sh --keep             laisse l'instance en vie pour inspection
#
# Code de sortie : 0 si et seulement si tout passe.
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CTX="$REPO/containers"

VERSIONS=(8.3 8.4 8.5 8.6)
SERVERS=(apache nginx)
REF_VERSION=8.4
REF_SERVER=apache

NET=stashbin-tests-net
APP=stashbin-tests-app
VOL=stashbin-tests-data
BROWSER_IMAGE=stashbin-tests-browser

USER_NAME=testeur
USER_PASS=motdepassetest

RED=$'\e[31m'; GREEN=$'\e[32m'; BOLD=$'\e[1m'; OFF=$'\e[0m'
info() { printf '%s\n' "$*"; }

keep=0
# Défini avant die(), qui s'en sert : bash résout les appels à l'exécution, et
# une erreur d'argument survient avant que le reste du fichier ne soit lu.
teardown() {
    (( keep )) && { info "Instance laissée en vie : podman exec -it $APP bash"; return; }
    podman rm -f "$APP" >/dev/null 2>&1
    podman volume rm -f "$VOL" >/dev/null 2>&1
    podman network rm -f "$NET" >/dev/null 2>&1
}
trap teardown EXIT

die() { printf '%s%s%s\n' "$RED" "$*" "$OFF" >&2; exit 2; }

# L'aide est le bandeau de commentaires en tête de fichier : une seule source.
usage() { awk '/^#!/ {next} /^#/ {sub(/^# ?/, ""); print; next} {exit}' "$0"; }

version=$REF_VERSION
server=$REF_SERVER
browser=1
matrix=0

while (( $# )); do
    case $1 in
        --version) version=${2:?version manquante}; shift 2 ;;
        --server)  server=${2:?serveur manquant}; shift 2 ;;
        --no-browser) browser=0; shift ;;
        --matrix)  matrix=1; shift ;;
        --keep)    keep=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) die "Option inconnue : « $1 » (voir $0 --help)" ;;
    esac
done

contains() { local n=$1; shift; for x in "$@"; do [[ $x == "$n" ]] && return 0; done; return 1; }
contains "$version" "${VERSIONS[@]}" || die "Version inconnue : « $version ». Attendu : ${VERSIONS[*]}"
contains "$server" "${SERVERS[@]}"   || die "Serveur inconnu : « $server ». Attendu : ${SERVERS[*]}"

php_tag() {
    local v=$1 s=$2 base
    [[ $v == 8.6 ]] && base=8.6-rc || base=$v
    [[ $s == apache ]] && echo "${base}-apache" || echo "${base}-fpm"
}

# Démarre une instance neuve et attend qu'elle réponde réellement.
# Les arguments qui suivent la version et le serveur sont passés tels quels à
# « podman run » (la suite « noauth » s'en sert pour poser STASHBIN_AUTH=0).
start_app() {
    local v=$1 s=$2 image="stashbin-tests:${1}-${2}"
    shift 2

    podman build --quiet -f "$CTX/Containerfile.$s" \
        --build-arg "PHP_TAG=$(php_tag "$v" "$s")" -t "$image" "$CTX" >/dev/null 2>&1 \
        || die "Construction de l'image $image impossible."

    podman rm -f "$APP" >/dev/null 2>&1
    podman volume rm -f "$VOL" >/dev/null 2>&1
    podman network exists "$NET" >/dev/null 2>&1 || podman network create "$NET" >/dev/null

    podman run -d --name "$APP" --network "$NET" "$@" \
        -v "$REPO:/var/www/stashbin:ro,z" \
        -v "$VOL:/var/lib/stashbin:z" \
        "$image" >/dev/null || die "Démarrage du conteneur impossible."

    local i
    for i in $(seq 60); do
        podman exec "$APP" curl -fsS -o /dev/null http://127.0.0.1/login.php 2>/dev/null && break
        sleep 0.25
        (( i == 60 )) && { podman logs --tail 20 "$APP" >&2; die "L'application ne répond pas."; }
    done

    printf '%s\n' "$USER_PASS" | podman exec -i -u www-data "$APP" \
        php /var/www/stashbin/bin/user.php add "$USER_NAME" >/dev/null 2>&1 \
        || die "Création du compte de test impossible."
}

# Exécute un fichier de test PHP dans le conteneur, sous l'identité du serveur.
# Les arguments qui suivent le nom de la suite sont ajoutés à « podman exec ».
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

# Les trois suites ordinaires, puis « noauth » sur une instance neuve démarrée
# sans authentification : le réglage se lit au démarrage du serveur, il faut
# donc un second conteneur plutôt qu'une bascule à chaud.
run_php_suites() {
    local v=$1 s=$2 prefix=${3:-}
    start_app "$v" "$s"
    for suite in unit.test.php api.test.php security.test.php; do
        if run_php_suite "$suite"; then
            record "$prefix${suite%.test.php}" OK
        else
            record "$prefix${suite%.test.php}" ÉCHEC
        fi
    done

    start_app "$v" "$s" -e STASHBIN_AUTH=0
    if run_php_suite noauth.test.php -e STASHBIN_AUTH=0; then
        record "${prefix}noauth" OK
    else
        record "${prefix}noauth" ÉCHEC
    fi
}

run_browser_suite() {
    podman build --quiet -f "$REPO/tests/Containerfile.browser" \
        -t "$BROWSER_IMAGE" "$REPO/tests" >/dev/null 2>&1 \
        || die "Construction de l'image des tests navigateur impossible."

    # « --network container: » fait partager au navigateur l'espace réseau de
    # l'application : celle-ci est donc joignable sur 127.0.0.1, une origine
    # que les navigateurs tiennent pour sûre. Sans cela, crypto.subtle est
    # indisponible et rien ne peut être chiffré.
    #
    # Le script est copié auprès des dépendances : les modules ES résolvent en
    # remontant l'arborescence, et /app est monté en lecture seule.
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
    info "Tests PHP sur les huit combinaisons, tests navigateur sur $REF_VERSION/$REF_SERVER."
    for v in "${VERSIONS[@]}"; do
        for s in "${SERVERS[@]}"; do
            info ""
            info "${BOLD}══ PHP $v / $s ══${OFF}"
            run_php_suites "$v" "$s" "$v/$s "
        done
    done
    # Les tests navigateur ne dépendent pas de la version de PHP : une passe suffit.
    if (( browser )); then
        info ""
        info "${BOLD}══ Navigateur ($REF_VERSION/$REF_SERVER) ══${OFF}"
        start_app "$REF_VERSION" "$REF_SERVER"
        if run_browser_suite; then record "navigateur" OK; else record "navigateur" ÉCHEC; fi
    fi
else
    info "PHP $version / $server$( (( browser )) || echo ' — sans les tests navigateur')"
    run_php_suites "$version" "$server"
    if (( browser )); then
        # La suite « noauth » a laissé une instance ouverte : le navigateur, lui,
        # joue le parcours authentifié et exige une instance ordinaire.
        start_app "$version" "$server"
        if run_browser_suite; then record "navigateur" OK; else record "navigateur" ÉCHEC; fi
    elif (( keep )); then
        start_app "$version" "$server"
    fi
fi

# ---------------------------------------------------------------------------
info ""
info "${BOLD}Bilan${OFF}"
printf '%s\n' "${RESULTS[@]}" | column -t -s'|' | sed 's/^/  /'
info ""
if (( failed )); then
    printf '%sAu moins une suite a échoué.%s\n' "$RED" "$OFF"
    exit 1
fi
printf '%sToutes les suites passent.%s\n' "$GREEN" "$OFF"
exit 0
