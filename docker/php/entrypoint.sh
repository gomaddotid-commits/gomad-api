#!/bin/sh
set -eu

cd /var/www/html

if [ ! -f .env ]; then
    cp .env.example .env
fi

if [ -z "${APP_KEY:-}" ]; then
    unset APP_KEY
    if ! grep -Eq '^APP_KEY=base64:.+' .env; then
        php artisan key:generate --force
    fi
fi

exec "$@"
