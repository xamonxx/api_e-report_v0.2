# Deployment Guide

## Target Production Stack
- PHP 8.2+
- MySQL 8+
- Redis
- Nginx or Apache
- Supervisor or systemd for queue worker
- Cron for Laravel scheduler

## Recommended Runtime Settings
- `APP_ENV=production`
- `APP_DEBUG=false`
- `CACHE_STORE=redis`
- `SESSION_DRIVER=redis`
- `QUEUE_CONNECTION=redis`
- import job runs on queue `imports`

Gunakan [`.env.production.example`](/C:/laragon/www/E-report/.env.production.example) sebagai template.

## First-Time Deploy
```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan storage:link
php artisan optimize
```

## Queue Workers
Pisahkan worker queue biasa dan queue import agar import CSV tidak mengganggu request utama:

```bash
php artisan queue:work redis --queue=default --sleep=1 --tries=3 --timeout=120
php artisan queue:work redis --queue=imports --sleep=1 --tries=3 --timeout=300
```

Jika belum memakai Redis, fallback sementara:

```bash
php artisan queue:work database --queue=default --sleep=1 --tries=3 --timeout=120
php artisan queue:work database --queue=imports --sleep=1 --tries=3 --timeout=300
```

## Scheduler
Jalankan salah satu:

```bash
php artisan schedule:work
```

Atau cron:

```bash
* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
```

## Health Check
Aplikasi menyediakan endpoint health check:

```text
/up
```

## Post-Deploy Checklist
- pastikan `storage` dan `bootstrap/cache` writable
- pastikan Redis aktif jika memakai session/cache/queue Redis
- cek worker queue berjalan
- cek scheduler aktif
- cek build asset terbaru sudah ada di `public/build`
- cek login, halaman leads, analytics, dan import CSV
