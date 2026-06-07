FROM php:8.4-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
      git unzip libicu-dev libpq-dev libzip-dev libonig-dev \
  && docker-php-ext-install pdo_pgsql intl opcache zip pcntl \
  && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /var/www/html
