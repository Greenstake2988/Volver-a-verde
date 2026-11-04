#!/usr/bin/env bash
# Prepara el volumen de datos, crea la llave de la app la primera vez y corre las migraciones.
set -euo pipefail
cd /var/www/html

mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
[ -f "$DB_DATABASE" ] || touch "$DB_DATABASE"

# La llave cifra las sesiones; se guarda en el volumen para que sobreviva a reconstruir la imagen.
if [ -z "${APP_KEY:-}" ]; then
  if [ ! -s storage/app_key ]; then
    echo "base64:$(head -c 32 /dev/urandom | base64)" > storage/app_key
  fi
  export APP_KEY="$(cat storage/app_key)"
fi

chown -R www-data:www-data storage bootstrap/cache

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
chown -R www-data:www-data storage bootstrap/cache

exec "$@"
