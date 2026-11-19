# Development / CI image: PHP 8.4 CLI with the extensions GameVault needs, Composer and Node 22.
FROM php:8.4-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip curl ca-certificates libzip-dev libicu-dev libsqlite3-dev default-mysql-client \
    && docker-php-ext-install pdo_mysql zip intl bcmath pcntl opcache \
    && pecl install redis && docker-php-ext-enable redis \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/gamevault.ini

WORKDIR /app
