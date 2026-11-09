FROM php:8.5-fpm-alpine3.23
RUN apk add --no-cache icu-libs oniguruma libpq libzip libldap unzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev oniguruma-dev libpq-dev libzip-dev openldap-dev \
    && docker-php-ext-install -j2 bcmath intl ldap mbstring pcntl pdo_pgsql zip \
    && apk del .build-deps
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-progress
COPY . .
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --no-dev --optimize \
    && chown -R www-data:www-data storage bootstrap/cache
COPY deploy/production/php.ini /usr/local/etc/php/conf.d/production.ini
USER www-data
CMD ["php-fpm"]
