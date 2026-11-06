FROM php:8.5-fpm-alpine3.23

RUN apk add --no-cache icu-libs oniguruma libpq libzip libldap unzip \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS icu-dev oniguruma-dev libpq-dev libzip-dev openldap-dev \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath intl ldap mbstring pcntl pdo_pgsql zip \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php/local.ini /usr/local/etc/php/conf.d/local.ini

ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts

COPY . .
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache

CMD ["php-fpm"]
