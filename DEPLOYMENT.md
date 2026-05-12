# Auto Deploy ke Hostinger

## 📋 Langkah Setup

### 1. GitHub Secrets
Buat secrets di GitHub repository (`Settings > Secrets and variables > Actions`):

| Secret Name | Value |
|-------------|-------|
| `HOSTINGER_SSH_HOST` | `153.92.9.128` |
| `HOSTINGER_SSH_PORT` | `65002` |
| `HOSTINGER_SSH_USER` | `u603012205` |
| `HOSTINGER_SSH_PRIVATE_KEY` | (SSH Key dari server) |
| `PROJECT_DIR` | `~/domains/homeputrainterior.com/webHPI` |
| `PRODUCTION_APP_URL` | `https://homeputrainterior.com` |
| `PRODUCTION_DB_DATABASE` | `u603012205_hpi` |
| `PRODUCTION_DB_USERNAME` | `u603012205_hpi` |
| `PRODUCTION_DB_PASSWORD` | `Hsn090698@#` |

### 2. Setup SSH Key di Server

Di terminal server (via SSH), generate SSH key:
```bash
ssh-keygen -t ed25519 -C "github-deploy"
```

Copy public key ke authorized_keys:
```bash
cat ~/.ssh/id_ed25519.pub >> ~/.ssh/authorized_keys
```

### 3. Setup .env di Server

Login ke server via SSH:
```bash
ssh -p 65002 u603012205@153.92.9.128
```

Buat .env:
```bash
cd ~/domains/homeputrainterior.com
mkdir -p webHPI
cd webHPI
cp .env.example .env
nano .env
```

Isi .env:
```env
APP_NAME="Home Putra Interior"
APP_ENV=production
APP_KEY=base64:XXXXX... (generate dengan: php artisan key:generate)
APP_DEBUG=false
APP_URL=https://homeputrainterior.com

LOG_CHANNEL=stack
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=u603012205_hpi
DB_USERNAME=u603012205_hpi
DB_PASSWORD=Hsn090698@#

CACHE_DRIVER=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=public
```

### 4. Generate APP_KEY
```bash
cd ~/domains/homeputrainterior.com/webHPI
php artisan key:generate
```

### 5. Push ke GitHub
```bash
git add .
git commit -m "Add auto deploy configuration"
git push origin main
```

## 🚀 Cara Kerja

Setiap push ke branch `main` akan trigger workflow:
1. Checkout kode
2. Setup PHP 8.2
3. Install SSH key
4. SSH ke server Hostinger
5. Pull latest code
6. Install composer
7. Build frontend (npm)
8. Run migrations
9. Clear & cache Laravel
10. Set permissions
11. Verify deployment

## ⚠️ Catatan Keamanan

- Jangan commit file .env
- Pastikan APP_DEBUG=false di production
- SSH key lebih aman daripada password
-，定期 rotate SSH key