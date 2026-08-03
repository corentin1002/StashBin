#!/bin/sh
# Garantit que le volume de données appartient à l'utilisateur qui fait tourner
# PHP, puis passe la main à Apache. Sans cela, un volume créé (ou peuplé) par
# root laisse Apache sur « attempt to write a readonly database ».
set -e
chown -R www-data:www-data /var/lib/stashbin 2>/dev/null || true
exec apache2-foreground "$@"
