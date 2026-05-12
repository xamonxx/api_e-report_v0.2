#!/bin/bash
# ============================================
# Auto Deploy Script untuk Hostinger
# Repository: https://github.com/xamonxx/webHPI.git
# ============================================

set -e

# ============================================
# KONFIGURASI - Isi sesuai environment server
# ============================================
PROJECT_DIR="$HOME/domains/homeputrainterior.com/webHPI"
REPO_URL="https://github.com/xamonxx/webHPI.git"
BRANCH="main"

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
        log "Installing PHP dependencies..."
        composer install --no-dev --optimize-autoloader --no-interaction
        log_success "Composer dependencies installed"
    else
        log_warning "Composer not found. Make sure vendor folder exists."
    fi

    # 4. Install NPM and Build
    if command -v npm >/dev/null 2>&1; then
        log "Installing and building frontend..."
        npm ci --legacy-peer-deps || npm install --legacy-peer-deps
        npm run build
        log_success "Frontend built successfully"
    else
        log_warning "NPM not found. Skipping frontend build."
    fi

    # 5. Laravel Migration
    log "Running database migrations..."
    php artisan migrate --force --no-interaction || log_warning "Migration completed with warnings"

    # 6. Clear and Cache
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

    # 7. Set Permissions
    log "Setting permissions..."
    chmod -R 775 storage bootstrap/cache 2>/dev/null || true
    chmod -R 755 storage bootstrap 2>/dev/null || true

    # 8. Storage Link
    php artisan storage:link 2>/dev/null || true

    # 9. Final Verification
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