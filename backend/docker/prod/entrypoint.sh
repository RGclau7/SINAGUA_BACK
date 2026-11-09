#!/bin/sh
# Arranque en la nube: escucha en $PORT, prepara la configuración y aplica las migraciones.
set -e

: "${PORT:=8080}"
sed -ri "s/Listen [0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

if [ -z "$APP_KEY" ]; then
    echo "Falta APP_KEY. Genérela una vez con: php artisan key:generate --show" >&2
    exit 1
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache

if [ "$RUN_MIGRATIONS" = "true" ]; then
    # Solo agrega tablas y columnas; nunca borra datos.
    php artisan migrate --force
    php artisan db:seed --class=Database\\Seeders\\RoleSeeder --force
fi

# Primer superadministrador (solo si se definieron SINAGUA_SUPERADMIN_EMAIL y _PASSWORD y no existe).
php artisan sinagua:superadmin --desde-entorno

exec "$@"
