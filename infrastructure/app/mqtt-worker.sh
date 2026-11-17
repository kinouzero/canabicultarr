#!/bin/sh
# The command reads Laravel's configuration and is a no-op while MQTT is disabled.
while :; do
    php artisan measurements:mqtt --seconds=300 --quiet
    sleep 10
done
