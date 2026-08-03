#!/usr/bin/env bash
# Banc d'essai StashBin : une commande par intention, aucun podman à taper.
# Voir containers/README.md.
set -euo pipefail

VERSIONS=(8.3 8.4 8.5 8.6)
SERVERS=(apache nginx)
DEFAULT_VERSION=8.4
DEFAULT_SERVER=apache

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CTX="$REPO/containers"
NAME=stashbin-test           # une seule instance interactive à la fois
VOLUME=stashbin-test-data    # comptes et secrets, conservés entre les lancements
TEST_NAME=stashbin-selftest  # instance éphémère utilisée par « test »
TEST_VOLUME=stashbin-selftest-data
IMAGE_PREFIX=stashbin        # images construites ici : stashbin:<version>-<serveur>
PORT="${PORT:-8081}"
AUTH="${AUTH:-1}"            # AUTH=0 : instance ouverte, sans authentification

RED=$'\e[31m'; GREEN=$'\e[32m'; YELLOW=$'\e[33m'; BOLD=$'\e[1m'; OFF=$'\e[0m'
info() { printf '%s\n' "$*"; }
ok()   { printf '%s%s%s\n' "$GREEN" "$*" "$OFF"; }
warn() { printf '%s%s%s\n' "$YELLOW" "$*" "$OFF"; }
die()  { printf '%s%s%s\n' "$RED" "$*" "$OFF" >&2; exit 1; }

contains() { local n=$1; shift; for x in "$@"; do [[ $x == "$n" ]] && return 0; done; return 1; }

# PHP 8.6 n'est pas encore sorti : ses images portent le suffixe -rc.
php_tag() {
    local version=$1 server=$2 base
    [[ $version == 8.6 ]] && base=8.6-rc || base=$version
    [[ $server == apache ]] && echo "${base}-apache" || echo "${base}-fpm"
}

check_args() {
    contains "$1" "${VERSIONS[@]}" \
        || die "Version PHP inconnue : « $1 ». Attendu : ${VERSIONS[*]}"
    contains "$2" "${SERVERS[@]}" \
        || die "Serveur inconnu : « $2 ». Attendu : ${SERVERS[*]}"
}

port_busy() { (exec 3<>"/dev/tcp/127.0.0.1/$1") 2>/dev/null && exec 3>&- && return 0 || return 1; }

# Renvoie le nom de l'image sur stdout ; toute la progression part sur stderr,
# pour que $(build ...) ne capture que le nom.
build() {
    local version=$1 server=$2 image="stashbin:${1}-${2}"
    printf 'Construction de %s (php:%s)…\n' "$image" "$(php_tag "$version" "$server")" >&2
    podman build --quiet \
        -f "$CTX/Containerfile.$server" \
        --build-arg "PHP_TAG=$(php_tag "$version" "$server")" \
        -t "$image" "$CTX" >/dev/null 2>&1 \
        || { printf 'La construction de %s a échoué :\n' "$image" >&2
             podman build -f "$CTX/Containerfile.$server" \
                 --build-arg "PHP_TAG=$(php_tag "$version" "$server")" \
                 -t "$image" "$CTX" >&2
             return 1; }
    echo "$image"
}

# Démarre un conteneur et attend qu'il réponde vraiment en HTTP.
start() {
    local image=$1 name=$2 port=$3 volume=$4 auth=${5:-1}
    podman rm -f "$name" >/dev/null 2>&1 || true
    podman run -d --name "$name" -p "127.0.0.1:$port:80" \
        -e "STASHBIN_AUTH=$auth" \
        -v "$REPO:/var/www/stashbin:ro,z" \
        -v "$volume:/var/lib/stashbin:z" \
        "$image" >/dev/null

    # login.php redirige (302) sur une instance ouverte : « curl -f » ne tient
    # en échec que les codes 4xx et 5xx, la sonde reste donc valable.
    for _ in $(seq 40); do
        if curl -fsS -o /dev/null "http://127.0.0.1:$port/login.php" 2>/dev/null; then
            return 0
        fi
        sleep 0.25
    done
    warn "Le conteneur ne répond pas. Journal :"
    podman logs --tail 30 "$name" >&2 || true
    return 1
}

cmd_up() {
    local version=${1:-$DEFAULT_VERSION} server=${2:-$DEFAULT_SERVER}
    check_args "$version" "$server"

    if port_busy "$PORT" && ! podman ps --format '{{.Names}}' | grep -qx "$NAME"; then
        die "Le port $PORT est déjà pris. Relancez avec : PORT=8082 $0 up $version $server"
    fi

    local image; image=$(build "$version" "$server")
    start "$image" "$NAME" "$PORT" "$VOLUME" "$AUTH" || die "Démarrage impossible."

    local real; real=$(podman exec "$NAME" php -r 'echo PHP_VERSION;')
    ok "StashBin tourne : PHP $real + $server"
    info ""
    info "  ${BOLD}http://127.0.0.1:$PORT/${OFF}"
    info ""
    case $AUTH in
        0|false|off|no) warn "  Authentification désactivée : la création est ouverte à tous." ;;
        *) info "  Créer un compte     : $0 user <nom>" ;;
    esac
    info "  Voir les journaux   : $0 logs"
    info "  Changer de version  : $0 up 8.5 nginx"
    info "  Arrêter             : $0 down"
}

running() { podman ps --format '{{.Names}}' | grep -qx "$NAME"; }

# Relaie les sous-commandes de bin/user.php dans le conteneur en cours.
cmd_user() {
    running || die "Aucune instance ne tourne. Lancez d'abord : $0 up"

    case "${1:-}" in
        "")
            die "Usage : $0 user <add|passwd|del|list> [nom]" ;;
        add|passwd|del)
            [[ -n ${2:-} ]] || die "Usage : $0 user $1 <nom>" ;;
        list)
            ;;
        *)
            # Raccourci : « user alice » vaut « user add alice ».
            set -- add "$@" ;;
    esac

    # -u www-data : la CLI doit écrire la base sous la même identité que le
    # serveur web, sinon celui-ci ne peut plus créer de secrets ensuite.
    # Le pseudo-terminal n'est alloué que s'il y en a un (add et passwd
    # demandent le mot de passe), pour rester utilisable depuis un script.
    local flags=-i; [[ -t 0 ]] && flags=-it
    podman exec $flags -u www-data "$NAME" \
        php /var/www/stashbin/bin/user.php "$@"
}

cmd_logs() { podman logs "$@" "$NAME"; }

cmd_down() {
    if podman rm -f "$NAME" >/dev/null 2>&1; then
        ok "Instance arrêtée."
        info "Pour tout retirer (volumes et images) : $0 clean"
    else
        info "Rien à arrêter."
    fi
}

cmd_reset() {
    cmd_down >/dev/null 2>&1 || true
    podman volume rm -f "$VOLUME" >/dev/null 2>&1 || true
    ok "Base effacée (comptes et secrets)."
}

# Retire tout ce que ce script a créé, et rien d'autre : les conteneurs et
# volumes portent des noms qui nous sont propres, et les images sont filtrées
# sur le préfixe stashbin:. Un volume ou une image que nous n'avons pas
# fabriqué n'est jamais touché.
cmd_clean() {
    local all=0
    case "${1:-}" in
        "")     ;;
        --all)  all=1 ;;
        *)      die "Usage : $0 clean [--all]" ;;
    esac

    # « podman rm -f » réussit même sur une cible absente : on teste l'existence
    # d'abord, pour que le compte rendu n'annonce que des suppressions réelles.
    local removed=0

    for c in "$NAME" "$TEST_NAME"; do
        podman container exists "$c" 2>/dev/null || continue
        podman rm -f "$c" >/dev/null 2>&1 && { info "conteneur $c"; removed=1; }
    done

    for v in "$VOLUME" "$TEST_VOLUME"; do
        podman volume exists "$v" 2>/dev/null || continue
        podman volume rm -f "$v" >/dev/null 2>&1 && { info "volume $v"; removed=1; }
    done

    local images
    images=$(podman images --format '{{.Repository}}:{{.Tag}}' \
             | grep -E "(^|/)${IMAGE_PREFIX}:[0-9]" || true)
    if [[ -n $images ]]; then
        while read -r img; do
            podman rmi -f "$img" >/dev/null 2>&1 && { info "image $img"; removed=1; }
        done <<< "$images"
    fi

    if (( all )); then
        # Uniquement les tags officiels que ce banc d'essai télécharge lui-même.
        for v in "${VERSIONS[@]}"; do
            for s in "${SERVERS[@]}"; do
                local tag="docker.io/library/php:$(php_tag "$v" "$s")"
                podman image exists "$tag" 2>/dev/null || continue
                podman rmi -f "$tag" >/dev/null 2>&1 && { info "image $tag"; removed=1; }
            done
        done
    fi

    (( removed )) || { info "Rien à nettoyer."; return 0; }
    ok "Nettoyage terminé."
    (( all )) || info "Les images de base php:* sont conservées (« $0 clean --all » pour les retirer aussi)."
}

cmd_list() {
    info "${BOLD}Combinaisons disponibles${OFF}"
    for v in "${VERSIONS[@]}"; do
        for s in "${SERVERS[@]}"; do
            local note=""
            [[ $v == 8.6 ]] && note=" (release candidate)"
            [[ $v == 8.3 ]] && note=" (support sécurité uniquement)"
            printf '  %s up %-4s %-7s→ php:%s%s\n' "$0" "$v" "$s" "$(php_tag "$v" "$s")" "$note"
        done
    done
    info ""
    if podman ps --format '{{.Names}}' | grep -qx "$NAME"; then
        ok "En cours : $(podman exec "$NAME" php -r 'echo PHP_VERSION;') sur http://127.0.0.1:$PORT/"
    else
        info "Aucune instance en cours."
    fi
}

# --- Test automatisé de toute la matrice -------------------------------------
# Rejoue le parcours réel (connexion, création, relecture, destruction) contre
# le serveur web, pas contre le serveur intégré de PHP.
smoke() {
    local port=$1 b="http://127.0.0.1:$1" jar; jar=$(mktemp)
    local fails=()

    local csrf; csrf=$(curl -s -c "$jar" "$b/login.php" \
        | sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p')
    [[ -n $csrf ]] || { echo "jeton CSRF introuvable sur login.php"; rm -f "$jar"; return 1; }

    printf 'motdepassetest\n' | podman exec -i -u www-data "$2" \
        php /var/www/stashbin/bin/user.php add smoke >/dev/null 2>&1 || true

    local code; code=$(curl -s -b "$jar" -c "$jar" -o /dev/null -w '%{http_code}' \
        -d "csrf=$csrf&username=smoke&password=motdepassetest" "$b/login.php")
    [[ $code == 302 ]] || fails+=("connexion (HTTP $code au lieu de 302)")

    code=$(curl -s -b "$jar" -o /dev/null -w '%{http_code}' "$b/index.php")
    [[ $code == 200 ]] || fails+=("index.php (HTTP $code)")

    local token; token=$(curl -s -b "$jar" "$b/index.php" \
        | sed -n 's/.*csrf-token" content="\([^"]*\)".*/\1/p')
    local resp; resp=$(curl -s -b "$jar" -X POST "$b/api.php" \
        -H "X-CSRF-Token: $token" -H 'Content-Type: application/json' \
        -d '{"payload":{"v":1,"iv":"AAA","salt":"BBB","iter":310000,"pwd":false,"ct":"Q0lQSEVS"},"expire":"1h","burn":true}')
    local id; id=$(echo "$resp" | sed -n 's/.*"id":"\([^"]*\)".*/\1/p')
    [[ -n $id ]] || fails+=("création du secret ($resp)")

    if [[ -n $id ]]; then
        curl -s "$b/api.php?id=$id" | grep -q 'Q0lQSEVS' || fails+=("relecture du secret")
        curl -s "$b/api.php?id=$id" | grep -q 'introuvable' || fails+=("destruction après lecture")
    fi

    curl -s "$b/api.php?id=%21%21" | grep -q 'identifiant invalide' || fails+=("rejet d'un identifiant invalide")
    curl -s -o /dev/null -w '%{http_code}' "$b/assets/style.css" | grep -q 200 || fails+=("service des fichiers statiques")
    # --path-as-is : sinon curl résout le « .. » lui-même et le serveur ne voit
    # jamais la tentative de remontée hors de public/.
    if curl -s --path-as-is "$b/../config.php" | grep -q 'STASHBIN_DB'; then
        fails+=("config.php servi hors de public/")
    fi

    rm -f "$jar"
    (( ${#fails[@]} == 0 )) && return 0
    printf '%s\n' "${fails[@]}"
    return 1
}

cmd_test() {
    local versions=("$@"); (( $# )) || versions=("${VERSIONS[@]}")
    for v in "${versions[@]}"; do contains "$v" "${VERSIONS[@]}" || die "Version inconnue : « $v »"; done

    local tport=${TEST_PORT:-8099} tname=$TEST_NAME tvol=$TEST_VOLUME
    port_busy "$tport" && die "Le port $tport est occupé. Relancez avec : TEST_PORT=9099 $0 test"

    local rows=() failed=0
    for v in "${versions[@]}"; do
        for s in "${SERVERS[@]}"; do
            printf '  %-4s %-7s ' "$v" "$s"
            podman volume rm -f "$tvol" >/dev/null 2>&1 || true
            local image; image=$(build "$v" "$s" 2>/dev/null)
            # Authentification toujours active : le parcours joué par smoke()
            # commence par une connexion, quel que soit AUTH dans l'environnement.
            if ! start "$image" "$tname" "$tport" "$tvol" 1 >/dev/null 2>&1; then
                printf '%sÉCHEC%s (le conteneur ne démarre pas)\n' "$RED" "$OFF"
                rows+=("$v|$s|démarrage impossible"); failed=1; continue
            fi
            local real; real=$(podman exec "$tname" php -r 'echo PHP_VERSION;')
            local out
            if out=$(smoke "$tport" "$tname" 2>&1); then
                printf '%sOK%s      PHP %s\n' "$GREEN" "$OFF" "$real"
                rows+=("$v|$s|OK ($real)")
            else
                printf '%sÉCHEC%s   PHP %s\n' "$RED" "$OFF" "$real"
                printf '%s\n' "$out" | sed 's/^/           /'
                rows+=("$v|$s|ÉCHEC : $(echo "$out" | head -1)")
                failed=1
            fi
            podman rm -f "$tname" >/dev/null 2>&1 || true
        done
    done
    podman volume rm -f "$tvol" >/dev/null 2>&1 || true

    info ""
    info "${BOLD}Résumé${OFF}"
    printf '%s\n' "${rows[@]}" | column -t -s'|' | sed 's/^/  /'
    (( failed == 0 )) && ok "Toutes les combinaisons passent." || die "Au moins une combinaison a échoué."
}

usage() {
    cat <<EOF
${BOLD}StashBin — banc d'essai multi-versions${OFF}

  $0 up [version] [serveur]   démarre (défaut : $DEFAULT_VERSION $DEFAULT_SERVER)
  $0 user add <nom>           crée un compte      (raccourci : $0 user <nom>)
  $0 user passwd <nom>        change son mot de passe
  $0 user del <nom>           révoque le compte
  $0 user list                liste les comptes
  $0 logs [-f]                journaux du conteneur
  $0 down                     arrête l'instance
  $0 reset                    efface la base (comptes et secrets)
  $0 clean [--all]            retire conteneurs, volumes et images du banc
                              d'essai ; --all inclut les images de base php:*
  $0 list                     combinaisons disponibles et état
  $0 test [version…]          rejoue le parcours complet sur toute la matrice

Versions : ${VERSIONS[*]}        Serveurs : ${SERVERS[*]}
Variables : PORT (défaut 8081), TEST_PORT (défaut 8099),
            AUTH (défaut 1 ; « AUTH=0 $0 up » ouvre la création à tous)
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
    *)     printf '%sCommande inconnue : « %s »%s\n\n' "$RED" "$1" "$OFF" >&2; usage >&2; exit 1 ;;
esac
