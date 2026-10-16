#!/bin/sh
set -e

echo "Menunggu MySQL siap..."
until php artisan db:show > /dev/null 2>&1; do
  sleep 2
done
echo "MySQL siap."

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  php artisan migrate --force
fi

if [ "${RUN_STORAGE_LINK:-false}" = "true" ] \
  && [ ! -e public/storage ] \
  && [ ! -L public/storage ]; then
  php artisan storage:link
fi

# Cache config/route/view/event dibuat saat start, bukan saat build, karena .env
# baru tersedia lewat bind mount runtime. Cukup satu service (be-php) yang
# menjalankannya; bootstrap/cache tiap container terpisah.
if [ "${RUN_OPTIMIZE:-false}" = "true" ]; then
  php artisan optimize
fi

exec "$@"
