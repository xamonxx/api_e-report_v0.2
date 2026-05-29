#!/bin/bash
# ============================================
# Auto Deploy Script untuk Hostinger
# Repository: https://github.com/xamonxx/api_e-report_v0.2.git
# ============================================

set -e

# ============================================
# KONFIGURASI - Isi sesuai environment server
# ============================================
PROJECT_DIR="${PROJECT_DIR:-$HOME/domains/interiorcustom.id/public_html/api-ereport}"
REPO_URL="${REPO_URL:-https://github.com/xamonxx/api_e-report_v0.2.git}"
BRANCH="${BRANCH:-master}"

# ============================================
# FUNGSI LOG
# ============================================
log() {
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] $1"
}

log_success() {
    echo "✅ [$(date +'%Y-%m-%d %H:%M:%S')] $1"
}

log_error() {
    echo "❌ [$(date +'%Y-%m-%d %H:%M:%S')] $1" >&2
}

log_warning() {
    echo "⚠️  [$(date +'%Y-%m-%d %H:%M:%S')] $1"
}

# ============================================
# MAIN DEPLOYMENT
# ============================================
main() {
    log "Starting deployment..."
    
    # 1. Clone atau Update Repository
    if [ ! -d "$PROJECT_DIR" ]; then
        log "Project folder not found. Cloning repository..."
        mkdir -p "$(dirname $PROJECT_DIR)"
        git clone -b "$BRANCH" "$REPO_URL" "$PROJECT_DIR"
        log_success "Repository cloned successfully"
    else
        log "Pulling latest code..."
        cd "$PROJECT_DIR"
        git fetch origin "$BRANCH"
        git reset --hard "origin/$BRANCH"
        log_success "Code updated successfully"
    fi

    cd "$PROJECT_DIR"

    # 2. Validasi .env exists
    if [ ! -f ".env" ]; then
        log_error ".env file not found!"
        log_error "Please create .env file on server first:"
        log_error "  cp .env.example .env"
        log_error "  nano .env"
        exit 1
    fi

    # 3. Install Composer Dependencies
    if command -v composer >/dev/null 2>&1; then
        COMPOSER_CMD="composer"
    elif [ -f "composer.phar" ]; then
        COMPOSER_CMD="php composer.phar"
    else
        log "Composer not found. Installing local composer.phar..."
        php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
        php composer-setup.php --quiet
        rm -f composer-setup.php
        COMPOSER_CMD="php composer.phar"
    fi

    log "Installing PHP dependencies..."
    $COMPOSER_CMD install --no-dev --optimize-autoloader --no-interaction
    log_success "Composer dependencies installed"

    if ! grep -q '^APP_KEY=base64:' .env; then
        log "Generating Laravel APP_KEY..."
        php artisan key:generate --force --no-interaction
        log_success "APP_KEY generated"
    fi

    # 4. Laravel Migration
    log "Running database migrations..."
    php artisan migrate --force --no-interaction

    # 5. Clear and Cache
    log "Clearing and caching Laravel..."
    php artisan config:clear
    php artisan cache:clear
    php artisan route:clear
    php artisan view:clear
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan optimize
    log_success "Cache cleared and optimized"

    # 6. Set Permissions
    log "Setting permissions..."
    chmod -R 755 storage bootstrap 2>/dev/null || true

    # 7. Storage Link
    php artisan storage:link 2>/dev/null || true

    # 8. Final Verification
    log "Verifying deployment..."
    if [ -f "artisan" ] && [ -d "vendor" ]; then
        log_success "Deployment verified successfully!"
    else
        log_error "Deployment verification failed!"
        exit 1
    fi

    log_success "=== Deployment Complete ==="
    log "Time: $(date)"
}

# Jalankan main function
main "$@"
