#!/bin/sh
set -e

if [ ! -f /var/www/html/config.json ] && [ -f /var/www/html/config.example.json ]; then
    cp /var/www/html/config.example.json /var/www/html/config.json
fi

chown -R www-data:www-data /var/www/html

exec "$@"
