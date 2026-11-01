#!/usr/bin/env bash
# Traer la última versión de GitHub. Correr desde /var/www/volver-a-verde.
set -euo pipefail

git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo chown -R www-data:www-data storage bootstrap/cache database
echo "Actualizado."
