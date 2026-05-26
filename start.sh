#!/usr/bin/env bash
set -e

echo "=== Starting Laravel Setup ==="

php artisan storage:link --force --no-interaction 2>/dev/null || true

php artisan migrate --force --no-interaction

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize

chmod -R 775 storage bootstrap/cache 2>/dev/null || true

echo "=== Laravel Setup Complete ==="
