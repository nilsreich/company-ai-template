# syntax=docker/dockerfile:1
FROM php:8.5.10-fpm-bookworm@sha256:81b9c405b013ebda0c9b8cd7a1a61424cf3627ca96348d93752a9b0539ce9a25 AS base
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libicu-dev libzip-dev libonig-dev libxml2-dev libfcgi-bin unzip git && docker-php-ext-install -j2 pdo_pgsql intl zip mbstring pcntl bcmath && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY --chmod=755 docker/entrypoint docker/worker-health /usr/local/bin/
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

FROM base AS tooling
RUN curl -fsSL https://getcomposer.org/download/2.10.3/composer.phar -o /usr/local/bin/composer && echo '7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6  /usr/local/bin/composer' | sha256sum -c - && chmod +x /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

FROM tooling AS development
COPY . .

FROM tooling AS dependencies
COPY . .
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader && php artisan filament:assets

FROM node:24-bookworm-slim@sha256:2fe369e969550cde8e867afc3fe370b260140cab4a23d467074295b42163d553 AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts
COPY resources ./resources
COPY vite.config.js ./
RUN npm run build

FROM base AS production
ENV APP_ENV=production APP_DEBUG=false
COPY --from=dependencies --chown=www-data:www-data /app /app
COPY --from=assets --chown=www-data:www-data /app/public/build /app/public/build
RUN mkdir -p /app/storage/app/private /app/storage/framework/cache/data /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs && chown -R www-data:www-data /app/storage /app/bootstrap/cache && rm -rf /app/tests /app/.agents /app/.claude /app/.codex /app/.opencode /app/.ai && apt-get purge -y $PHPIZE_DEPS git libpq-dev libicu-dev libzip-dev libonig-dev libxml2-dev && rm -rf /var/lib/apt/lists/*
USER www-data

FROM nginx:stable-alpine@sha256:dc5069ad14f19660b141b21236140b91656bf89bbc3e2417c70ae650cd66104c AS web
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=production /app/public /app/public
