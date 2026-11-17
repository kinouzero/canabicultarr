# syntax=docker/dockerfile:1
FROM node:26-bookworm-slim AS frontend
WORKDIR /app
COPY frontend/package*.json ./
RUN npm ci --no-fund
COPY frontend/ ./
ARG APP_COVERAGE=0
RUN if [ "$APP_COVERAGE" = 1 ]; then npm run build -- --sourcemap; else npm run build; fi

FROM postgres:18-trixie AS postgres-tools
RUN mkdir -p /opt/postgres-tools/bin /opt/postgres-tools/lib \
 && cp /usr/lib/postgresql/18/bin/pg_dump /opt/postgres-tools/bin/ \
 && cp "$(ldd /opt/postgres-tools/bin/pg_dump | awk '$1 == "libpq.so.5" {print $3}')" /opt/postgres-tools/lib/libpq.so.5

FROM php:8.5-fpm-trixie AS php
RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx supervisor unzip age python3 python3-venv libpq-dev libicu-dev libzip-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install pdo_pgsql intl zip bcmath pcntl gd exif \
 && pecl install redis-6.3.0 \
 && docker-php-ext-enable redis \
 && rm -rf /var/lib/apt/lists/* /tmp/pear
COPY --from=postgres-tools /opt/postgres-tools /opt/postgres-tools
COPY backend/scripts/bluetooth/requirements.txt /tmp/bluetooth-requirements.txt
RUN python3 -m venv /opt/bluetooth \
 && /opt/bluetooth/bin/pip install --no-cache-dir -r /tmp/bluetooth-requirements.txt \
 && rm /tmp/bluetooth-requirements.txt
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY infrastructure/app/php.ini /usr/local/etc/php/conf.d/uploads.ini
WORKDIR /var/www/html
COPY backend/composer.* ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --no-progress
COPY backend/ ./
COPY frontend/package.json resources/application.json
COPY .env.example resources/backup-environment.example
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && composer dump-autoload --no-dev --optimize --no-interaction \
 && chmod -R a+rX /var/www/html
RUN chown -R www-data:www-data storage bootstrap/cache
COPY --from=frontend /app/dist /var/www/frontend
RUN chmod -R a+rX /var/www/frontend
COPY infrastructure/app/nginx.conf /etc/nginx/nginx.conf
COPY infrastructure/app/browser-security.conf /etc/nginx/browser-security.conf
COPY infrastructure/app/php-request.conf /etc/nginx/php-request.conf
COPY infrastructure/app/ui.conf /etc/nginx/app-ui-production.conf
COPY infrastructure/app/ui-dev.conf /etc/nginx/app-ui-development.conf
RUN ln -s /etc/nginx/app-ui-production.conf /etc/nginx/app-ui.conf
COPY infrastructure/app/supervisord.conf /etc/supervisor/supervisord.conf
COPY --chmod=755 infrastructure/app/entrypoint.sh /usr/local/bin/app-entrypoint
COPY --chmod=755 infrastructure/app/mqtt-worker.sh /usr/local/bin/app-mqtt
COPY --chmod=755 infrastructure/app/healthcheck.sh /usr/local/bin/app-healthcheck
ENV APP_AUTO_MIGRATE=true APP_QUEUE_WORKER_ENABLED=true APP_SCHEDULER_ENABLED=true APP_MQTT_WORKER_ENABLED=true APP_DEV_SERVER=false APP_PROCESS_USER=www-data
EXPOSE 8420
HEALTHCHECK --interval=15s --timeout=5s --start-period=120s --retries=3 CMD ["app-healthcheck"]
ENTRYPOINT ["app-entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf"]

FROM php AS development
COPY --from=frontend /usr/local/bin/node /usr/local/bin/node
COPY --from=frontend /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
 && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx
COPY --from=frontend /app /app
RUN composer install --no-interaction --prefer-dist --no-progress

FROM php AS runtime
