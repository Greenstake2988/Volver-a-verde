#!/usr/bin/env bash
# Primera instalación en el servidor (Ubuntu/Debian). Correr desde /var/www/volver-a-verde.
set -euo pipefail

composer install --no-dev --optimize-autoloader
[ -f .env ] || cp .env.example .env
grep -q '^APP_KEY=base64' .env || php artisan key:generate --force
sed -i 's/^APP_ENV=.*/APP_ENV=production/; s/^APP_DEBUG=.*/APP_DEBUG=false/' .env
touch database/database.sqlite
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo chown -R www-data:www-data storage bootstrap/cache database
echo "Listo. Abre la app y crea tu usuario de administrador."
