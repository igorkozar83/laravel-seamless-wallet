# Laravel on Octane + FrankenPHP: the application boots once per worker and stays in memory,
# instead of bootstrapping the whole framework on every request like PHP-FPM does.
FROM dunglas/frankenphp:1-php8.4

RUN install-php-extensions pdo_pgsql redis pcntl zip intl opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# dependencies first, so editing application code doesn't invalidate this layer
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY . .
# configuration comes from environment variables; an empty .env keeps the framework from warning
RUN touch .env \
    && composer dump-autoload --optimize \
    && php artisan package:discover --ansi \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8000

CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8000", "--workers=4", "--max-requests=500"]
