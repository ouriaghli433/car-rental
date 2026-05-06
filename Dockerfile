FROM php:8.2-fpm-alpine

# System packages needed by PHP extensions and Composer
RUN apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    bash

# PHP extensions required by Laravel + PostgreSQL
RUN docker-php-ext-install \
    pdo \
    pdo_pgsql \
    zip \
    pcntl \
    bcmath

# Copy Composer from its official image (no separate install needed)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy application code into the image
COPY . .

# Install PHP dependencies (with dev deps so tests work inside the container)
RUN composer install --no-interaction --prefer-dist

# Laravel needs write access to these two directories
RUN chown -R www-data:www-data storage bootstrap/cache

# Startup script handles migrations then launches the server
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/entrypoint.sh"]
