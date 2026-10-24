FROM php:8.3-apache-bookworm

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=info \
    DB_CONNECTION=pgsql \
    SESSION_DRIVER=file \
    SESSION_SECURE_COOKIE=true \
    CACHE_STORE=file \
    QUEUE_CONNECTION=sync \
    PORT=10000

WORKDIR /var/www/html

# The official image already includes Laravel's core extensions, mbstring,
# curl, DOM/XML, fileinfo and iconv. Only the PostgreSQL PDO driver is missing.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libpq5 unzip \
    && docker-php-ext-install pdo_pgsql \
    && apt-get purge -y --auto-remove libpq-dev \
    && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'upload_max_filesize=5M\npost_max_size=8M\n' > "$PHP_INI_DIR/conf.d/uploads.ini" \
    && a2enmod rewrite \
    && printf 'Listen 0.0.0.0:${PORT}\n' > /etc/apache2/ports.conf

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY . .
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# No local storage, cached configuration or vendor directory enters the image.
# Composer's install hooks only discover packages; no database is needed.
RUN mkdir -p storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && COMPOSER_ALLOW_SUPERUSER=1 composer install \
        --no-dev --prefer-dist --no-interaction --optimize-autoloader \
    && composer check-platform-reqs --no-dev \
    && ln -s /var/www/html/storage/app/public public/storage \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

EXPOSE 10000

# Apache expands ${PORT} from the runtime environment in its configuration.
# Its workers run as www-data; the foreground parent uses the image default.
CMD ["apache2-foreground"]
