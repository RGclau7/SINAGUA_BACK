#!/bin/sh
set -e

# En desarrollo el código se monta como volumen; instala dependencias si faltan o si
# composer.lock cambió (por ejemplo, al bajar una rama que agrega o quita paquetes).
if [ ! -f vendor/autoload.php ] || [ composer.lock -nt vendor/composer/installed.json ]; then
    composer install --no-interaction --prefer-dist
fi

exec "$@"
