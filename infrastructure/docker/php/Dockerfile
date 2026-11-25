FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache icu-libs libpq libzip git unzip bash \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev postgresql-dev libzip-dev linux-headers \
    && docker-php-ext-install -j"$(nproc)" intl pdo_pgsql zip opcache bcmath \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY infrastructure/docker/php/php.ini /usr/local/etc/php/conf.d/zz-forno.ini

WORKDIR /var/www/backend

ENV COMPOSER_ALLOW_SUPERUSER=1

CMD ["php-fpm"]
