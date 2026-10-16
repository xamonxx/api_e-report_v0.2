# syntax=docker/dockerfile:1.7

ARG COMPOSER_IMAGE=composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac
ARG PHP_IMAGE=php:8.3.35-cli-bookworm@sha256:c24b55afdf860c874b9ec6267ced132ff52de97d308ab08e6cf41e8596e509f7

FROM ${COMPOSER_IMAGE} AS composer

FROM ${PHP_IMAGE} AS runtime

LABEL org.opencontainers.image.source="https://github.com/xamonxx/api_e-report_v0.2"

RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev \
        libpng-dev \
        libicu-dev \
        libonig-dev \
        curl \
    && docker-php-ext-install pdo_mysql mbstring gd zip intl bcmath pcntl opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --link --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY --link composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-progress \
    && composer clear-cache

COPY --link . .
RUN composer dump-autoload --optimize

COPY --link docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/public

EXPOSE 8000

USER www-data
ENTRYPOINT ["entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
