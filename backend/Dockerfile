FROM php:8.1-fpm

# ── System dependencies ─────────────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libpq-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_pgsql \
        pgsql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# ── PHP production settings ──────────────────────────────────────────
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
RUN echo "upload_max_filesize=64M\npost_max_size=64M\nmemory_limit=256M" \
    > "$PHP_INI_DIR/conf.d/custom.ini"

# ── Install Composer ─────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── Build application in staging directory ───────────────────────────
#    (The actual /var/www will be a shared volume at runtime)
WORKDIR /app-src

# Copy composer files first for Docker layer caching
COPY composer.json ./
COPY composer.loc[k] ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# Copy application code
COPY . .

# Autoload dump
RUN composer dump-autoload --optimize --no-interaction

# Create required Laravel directories
RUN mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# ── Entrypoint script ───────────────────────────────────────────────
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

WORKDIR /var/www

EXPOSE 9000
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]
