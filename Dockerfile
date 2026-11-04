# Imagen de la app: PHP 8.3 + Apache, SQLite. Los datos (base y comprobantes) viven en el volumen /var/www/html/storage.
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev --ignore-platform-reqs

FROM php:8.3-apache
RUN apt-get update \
 && apt-get install -y --no-install-recommends libicu-dev libzip-dev sqlite3 \
 && docker-php-ext-install intl zip opcache \
 && apt-get clean && rm -rf /var/lib/apt/lists/* \
 && a2enmod rewrite headers
COPY docker/php.ini /usr/local/etc/php/conf.d/volver-a-verde.ini
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
 && rm -rf storage/* bootstrap/cache/*.php \
 && chown -R www-data:www-data storage bootstrap/cache

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/var/www/html/storage/database.sqlite

VOLUME /var/www/html/storage
EXPOSE 80
HEALTHCHECK --interval=1m --timeout=5s CMD curl -fsS http://localhost/up || exit 1
ENTRYPOINT ["entrypoint"]
CMD ["apache2-foreground"]
