#!/usr/bin/env bash
# =====================================================================
#  Deploy API E-Report (Laravel) — jalankan di server via SSH.
#  Lokasi: /home/u603012205/domains/interiorcustom.id/public_html/api-ereport
#  Pakai:  bash deploy.sh
#  CATATAN: script ini TIDAK menghapus data. Reset data dilakukan terpisah
#           & manual dengan: php artisan app:reset-for-launch
# =====================================================================
set -euo pipefail

cd "$(dirname "$0")"

echo "==> 1/6 Tarik kode terbaru (git pull)"
git pull origin master

echo "==> 2/6 Install dependency produksi"
composer install --no-dev --optimize-autoloader

echo "==> 3/6 Migrasi database"
php artisan migrate --force

echo "==> 4/6 Symlink storage"
php artisan storage:link || true

echo "==> 5/6 Bersihkan cache lama"
php artisan optimize:clear

echo "==> 6/6 Build cache produksi"
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "✅ Deploy API selesai."
