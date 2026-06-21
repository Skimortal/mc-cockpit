FROM php:8.4-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
      git unzip libicu-dev libpq-dev libzip-dev libonig-dev \
      poppler-utils libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
  && docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install pdo_pgsql intl opcache zip pcntl gd \
  && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /var/www/html
