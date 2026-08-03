#!/bin/sh
# Starts PHP-FPM in the background, then nginx in the foreground (nginx is what
# keeps the container alive). If PHP-FPM refuses to start, we stop right away
# rather than silently serving 502s.
set -e

# The data volume must belong to the user PHP runs as, otherwise PHP-FPM fails
# with "attempt to write a readonly database".
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

echo "PHP-FPM is not listening on 127.0.0.1:9000 after 5s — giving up." >&2
exit 1
