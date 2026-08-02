# Image de test : Apache + mod_php, le projet est monté sur /var/www/stashbin.
#   podman build -t stashbin .
#   podman run -d --name stashbin -p 8080:80 \
#       -v .:/var/www/stashbin:ro,Z -v stashbin-data:/var/lib/stashbin stashbin
FROM docker.io/library/php:8.3-apache

# Document root sur public/ ; la base SQLite vit hors du code, dans un volume.
RUN sed -i 's#/var/www/html#/var/www/stashbin/public#g' \
        /etc/apache2/sites-available/000-default.conf \
    && mkdir -p /var/lib/stashbin \
    && chown www-data:www-data /var/lib/stashbin

ENV STASHBIN_DB=/var/lib/stashbin/stashbin.sqlite

VOLUME /var/lib/stashbin
