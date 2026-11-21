# Stage 1: Build React 19 + Tailwind CSS v4 frontend assets with Vite
FROM node:22-alpine AS frontend-builder

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.ts tsconfig.json ./
COPY public ./public
COPY resources ./resources
COPY src ./src

ENV NODE_OPTIONS="--max-old-space-size=1024"
RUN npm run build

# Stage 2: PHP 8.3 + Apache runtime for Laravel 12 on Render.com
FROM php:8.3-apache

WORKDIR /var/www/html

# Install system dependencies and PHP extensions (MySQL, PostgreSQL, SQLite)
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        libpq-dev \
        libsqlite3-dev \
        libonig-dev \
        libxml2-dev \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        pdo_pgsql \
        pdo_sqlite \
        mbstring \
        bcmath \
        opcache \
        zip \
        dom \
        xml \
    && rm -rf /var/lib/apt/lists/*

# Tune PHP & OPcache for Render Free tier (512 MB RAM)
RUN { \
        echo "memory_limit=256M"; \
        echo "upload_max_filesize=20M"; \
        echo "post_max_size=25M"; \
        echo "max_execution_time=60"; \
        echo "expose_php=Off"; \
        echo "opcache.enable=1"; \
        echo "opcache.memory_consumption=96"; \
        echo "opcache.interned_strings_buffer=16"; \
        echo "opcache.max_accelerated_files=10000"; \
        echo "opcache.validate_timestamps=0"; \
    } > /usr/local/etc/php/conf.d/99-render-production.ini

# Enable Apache modules (ensure ONLY mpm_prefork is loaded to prevent AH00534)
RUN rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf \
    && a2enmod mpm_prefork rewrite headers
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/mpm_prefork.conf /etc/apache2/mods-available/mpm_prefork.conf

# Install Composer from official image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy composer manifests first for Docker layer caching
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

# Copy application source and compiled Vite assets
COPY . .
COPY --from=frontend-builder /app/public/build ./public/build

# Run Laravel package discovery after full source is present
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan package:discover --ansi

# Prepare runtime directories and entrypoint
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache database

EXPOSE 10000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
