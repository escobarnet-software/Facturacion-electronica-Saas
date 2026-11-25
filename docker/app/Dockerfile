# Imagen de producción de la aplicación (php-fpm + nginx + horizon + scheduler con supervisord).
# Independiente del hosting: el mismo contenedor se usa en staging y producción con roles distintos
# (ROL=web | horizon | scheduler). Secretos solo por variables de entorno del gestor de secretos.

# --- Assets (Vite + Tailwind) ---
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json vite.config.js ./
RUN npm ci --no-audit --no-fund
COPY resources ./resources
RUN npm run build

# --- Dependencias PHP ---
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader --ignore-platform-reqs

# --- Imagen final ---
FROM php:8.3-fpm-alpine
RUN apk add --no-cache nginx supervisor postgresql-libs libzip icu-libs libpng freetype libjpeg-turbo \
 && apk add --no-cache --virtual .build $PHPIZE_DEPS postgresql-dev libzip-dev icu-dev libpng-dev freetype-dev libjpeg-turbo-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql bcmath intl zip gd opcache pcntl \
 && pecl install redis && docker-php-ext-enable redis \
 && apk del .build \
 && sed -i 's/^expose_php.*/expose_php = Off/' "$PHP_INI_DIR/php.ini-production" \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build
COPY docker/app/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/app/supervisord.conf /etc/supervisord.conf
COPY docker/app/iniciar.sh /usr/local/bin/iniciar
RUN chmod +x /usr/local/bin/iniciar && rm -f .env && mkdir -p storage/framework/{cache,sessions,views} storage/logs \
 && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s CMD wget -qO- http://127.0.0.1:8080/up >/dev/null || exit 1
ENTRYPOINT ["iniciar"]
