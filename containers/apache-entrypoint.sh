#!/bin/sh
# Makes sure the data volume belongs to the user PHP runs as, then hands over
# to Apache. Without this, a volume created (or populated) by
# root laisse Apache sur « attempt to write a readonly database ».
set -e
chown -R www-data:www-data /var/lib/stashbin 2>/dev/null || true
exec apache2-foreground "$@"
