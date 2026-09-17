# AllTrue Daan staging PHP-FPM image.
# Build context = repository root (see docker-compose.yml).
# Separate from backend/Dockerfile so staging MySQL PDO parity does not
# classify as a Pi production application runtime change.
#
# Exact-SHA application code is baked under /opt/alltrue-src (immutable image
# layer). At runtime the staging entrypoint syncs into a *named Docker volume*
# mounted at /var/www. The host Git checkout is never bind-mounted writable.
FROM php:8.1-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    rsync \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libpq-dev \
    default-libmysqlclient-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_mysql \
        pdo_pgsql \
        pgsql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
RUN echo "upload_max_filesize=64M\npost_max_size=64M\nmemory_limit=192M" \
    > "$PHP_INI_DIR/conf.d/custom.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /opt/alltrue-src

COPY backend/composer.json ./
COPY backend/composer.loc[k] ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

COPY backend/ .

RUN composer dump-autoload --optimize --no-interaction

RUN mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

COPY infra/daan-staging/docker-entrypoint-staging.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

WORKDIR /var/www

EXPOSE 9000
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]
