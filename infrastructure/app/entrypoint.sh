#!/bin/sh
set -eu
cd /var/www/html
mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/framework/testing storage/logs bootstrap/cache
chown www-data:www-data storage storage/app storage/app/private storage/app/public \
    storage/framework storage/framework/cache storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/framework/testing storage/logs bootstrap/cache
# Existing document volumes may contain files written by the former root worker.
find storage bootstrap/cache ! -user www-data -exec chown www-data:www-data {} +
export APP_PROCESS_USER=www-data
if [ "${1:-}" = supervisord ]; then
    if [ -z "${APP_KEY:-}" ]; then
        echo "APP_KEY is missing. Run sh scripts/bootstrap.sh first." >&2
        exit 1
    fi
    case "${APP_AUTO_MIGRATE:-true}" in
        true|1)
            echo "Preparing application database before starting services..."
            if ! su -s /bin/sh www-data -c 'php artisan app:initialize --no-interaction'; then
                echo "Database initialization failed. Application services will not start." >&2
                exit 1
            fi
            ;;
        false|0) echo "Automatic database migrations disabled." ;;
        *) echo "APP_AUTO_MIGRATE must be true or false." >&2; exit 1 ;;
    esac
    if [ "$APP_DEV_SERVER" = true ]; then
        export APP_PROCESS_USER=root
        printf '[www]\nuser=root\ngroup=root\n' > /usr/local/etc/php-fpm.d/zz-development.conf
        ln -sf /etc/nginx/app-ui-development.conf /etc/nginx/app-ui.conf
        mkdir -p /app/node_modules/.vite /app/node_modules/.vite-temp
        chown -R www-data:www-data /app/node_modules/.vite /app/node_modules/.vite-temp
    else
        ln -sf /etc/nginx/app-ui-production.conf /etc/nginx/app-ui.conf
    fi
fi
exec "$@"
