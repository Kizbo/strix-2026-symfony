FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

FROM php:8.4-fpm-alpine
RUN apk add --no-cache libpq \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev \
    && docker-php-ext-install pdo_pgsql opcache \
    && apk del .build-deps
WORKDIR /var/www/html
COPY --from=vendor /usr/bin/composer /usr/bin/composer
COPY --from=vendor /app/vendor ./vendor
COPY . .
ENV APP_ENV=prod APP_DEBUG=0
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && mkdir -p var && chown -R www-data:www-data var
USER www-data
