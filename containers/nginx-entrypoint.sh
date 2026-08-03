#!/bin/sh
# Démarre PHP-FPM en arrière-plan, puis nginx au premier plan (c'est nginx qui
# tient le conteneur en vie). Si PHP-FPM refuse de démarrer, on s'arrête tout
# de suite plutôt que de servir des 502 en silence.
set -e

# Le volume de données doit appartenir à l'utilisateur qui fait tourner PHP,
# sinon PHP-FPM échoue sur « attempt to write a readonly database ».
chown -R www-data:www-data /var/lib/stashbin 2>/dev/null || true

php-fpm --daemonize

i=0
while [ $i -lt 50 ]; do
    if php -r 'exit(@fsockopen("127.0.0.1", 9000) ? 0 : 1);' 2>/dev/null; then
        exec nginx -g 'daemon off;'
    fi
    i=$((i + 1))
    sleep 0.1
done

echo "PHP-FPM n'écoute pas sur 127.0.0.1:9000 après 5 s — abandon." >&2
exit 1
