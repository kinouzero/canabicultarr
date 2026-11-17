#!/bin/sh
set -eu
running() { supervisorctl status "$1" | grep -q RUNNING; }
running php
running web
if [ "$APP_QUEUE_WORKER_ENABLED" = true ]; then
    running queue
    running integrations
    running exports
fi
if [ "$APP_SCHEDULER_ENABLED" = true ]; then
    running scheduler
    running pump-timers
fi
[ "$APP_MQTT_WORKER_ENABLED" != true ] || running mqtt
[ "$APP_DEV_SERVER" != true ] || running vite
php -r 'exit(@file_get_contents("http://127.0.0.1:8420/up") === false ? 1 : 0);'
if [ "$APP_DEV_SERVER" = true ]; then
    php -r 'exit(@file_get_contents("http://127.0.0.1:5173/") === false ? 1 : 0);'
fi
