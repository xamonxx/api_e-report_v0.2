#!/usr/bin/env bash
#
# e-report-deploy.sh — deploy master E-Report (backend + frontend)
#
# Alur: cek dulu apakah origin/master beda dari HEAD (backend & frontend
# masing-masing) sebelum menyentuh apa pun. Kalau beda, deploy repo itu
# dengan titik aman dicatat lebih dulu supaya bisa auto-rollback kalau ada
# langkah yang gagal atau health check pasca-deploy tidak lulus. Service
# hanya di-restart untuk repo yang benar-benar berubah. nginx dan
# cloudflared* tidak pernah disentuh oleh script ini.
#
# Sumber version-controlled: E-REPORT-BACKEND_API/scripts/deploy-production.sh
# Salinan eksekusi stabil di server: /root/e-report-deploy.sh (di luar kedua
# repo, supaya tidak ikut ter-reset saat dia sendiri men-deploy backend).
# Script ini menyalin dirinya sendiri ke lokasi stabil itu di akhir tiap
# deploy backend yang sukses.
#
# Pakai: sudo /root/e-report-deploy.sh

set -Eeuo pipefail

# ---------------------------------------------------------------------------
# Konfigurasi
# ---------------------------------------------------------------------------
BACKEND_DIR="/www/wwwroot/api-ereport.interiorcustom.id"
FRONTEND_DIR="/www/wwwroot/e-report-frontend"
STABLE_SCRIPT="/root/e-report-deploy.sh"
LOCK_FILE="/var/lock/e-report-deploy.lock"
LOG_DIR="/var/log/e-report-deploy"

PHP_BIN="/www/server/php/83/bin/php"
COMPOSER_BIN="/usr/local/bin/composer"
NODE_BIN_DIR="/www/server/nvm/versions/node/v22.23.0/bin"
export PATH="${NODE_BIN_DIR}:${PATH}"

PM2_PROCESS="e_report_frontend"
BACKEND_SERVICES=(php-fpm-83 ereport-queue ereport-reverb)
HEALTH_URL="http://127.0.0.1/up"
HEALTH_HOST="api-ereport.interiorcustom.id"

mkdir -p "$LOG_DIR"
RUN_TS="$(date +%Y%m%d-%H%M%S)"
LOG_FILE="${LOG_DIR}/deploy-${RUN_TS}.log"

BACKEND_CHANGED=0
FRONTEND_CHANGED=0
BACKEND_ROLLBACK_COMMIT=""
FRONTEND_ROLLBACK_COMMIT=""
BACKEND_DEPLOY_OK=0
FRONTEND_DEPLOY_OK=0
START_TS=$(date +%s)

# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------
log() {
  local msg="[$(date '+%Y-%m-%d %H:%M:%S')] $*"
  echo "$msg" | tee -a "$LOG_FILE"
}
ok()   { log "✅ $*"; }
warn() { log "⚠️  $*"; }
fail() { log "❌ $*"; }

# ---------------------------------------------------------------------------
# Lock — cegah dua run barengan
# ---------------------------------------------------------------------------
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
  fail "Deploy lain sedang berjalan (lock: $LOCK_FILE). Batal."
  exit 1
fi

log "=== E-Report deploy run dimulai (log: $LOG_FILE) ==="

# ---------------------------------------------------------------------------
# Cek perubahan dulu — tidak menyentuh apa pun kalau tidak ada perubahan
# ---------------------------------------------------------------------------
ensure_safe_directory() {
  local dir="$1"
  if ! git config --global --get-all safe.directory 2>/dev/null | grep -qxF "$dir"; then
    git config --global --add safe.directory "$dir"
  fi
}

# check_repo_changed menulis hasilnya ke variabel global lewat nameref
# ($3), BUKAN lewat `echo` yang ditangkap command substitution — log()
# juga menulis ke stdout, dan kalau seluruh fungsi ini dipanggil di dalam
# "$(...)" maka baris log ikut tertangkap bersama nilai "0"/"1", membuat
# perbandingan [[ "$X" == "0" ]] SELALU false walau repo benar-benar tidak
# berubah (ditemukan saat test-run pertama di server: fetch gagal karena
# safe.directory, tapi lolos begitu saja jadi "sukses" tanpa deploy apa pun).
check_repo_changed() {
  local dir="$1" name="$2" out_var="$3"
  ensure_safe_directory "$dir"
  git -C "$dir" fetch origin master --quiet
  local head remote
  head="$(git -C "$dir" rev-parse HEAD)"
  remote="$(git -C "$dir" rev-parse origin/master)"
  if [[ "$head" == "$remote" ]]; then
    log "${name}: tidak ada perubahan (HEAD=${head:0:8})"
    printf -v "$out_var" '%s' "0"
  else
    log "${name}: ada perubahan (${head:0:8} -> ${remote:0:8})"
    printf -v "$out_var" '%s' "1"
  fi
}

check_repo_changed "$BACKEND_DIR" "Backend" BACKEND_CHANGED
check_repo_changed "$FRONTEND_DIR" "Frontend" FRONTEND_CHANGED

if [[ "$BACKEND_CHANGED" == "0" && "$FRONTEND_CHANGED" == "0" ]]; then
  ok "Tidak ada perubahan di backend maupun frontend. Tidak ada yang di-deploy."
  exit 0
fi

# ---------------------------------------------------------------------------
# Rollback helpers
# ---------------------------------------------------------------------------
rollback_backend() {
  fail "Rollback backend ke ${BACKEND_ROLLBACK_COMMIT:0:8} ..."
  cd "$BACKEND_DIR"
  git reset --hard "$BACKEND_ROLLBACK_COMMIT" || true
  if [[ -d vendor.bak ]]; then
    rm -rf vendor
    mv vendor.bak vendor
  fi
  systemctl restart "${BACKEND_SERVICES[@]}" || true
  fail "Backend di-rollback ke ${BACKEND_ROLLBACK_COMMIT:0:8}, service di-restart di kode lama."
}

rollback_frontend() {
  fail "Rollback frontend ke ${FRONTEND_ROLLBACK_COMMIT:0:8} ..."
  cd "$FRONTEND_DIR"
  git reset --hard "$FRONTEND_ROLLBACK_COMMIT" || true
  if [[ -d .next.bak ]]; then
    rm -rf .next
    mv .next.bak .next
  fi
  pm2 restart "$PM2_PROCESS" --update-env || true
  pm2 save || true
  fail "Frontend di-rollback ke ${FRONTEND_ROLLBACK_COMMIT:0:8}, PM2 di-restart di build lama."
}

# ---------------------------------------------------------------------------
# Deploy backend
# ---------------------------------------------------------------------------
deploy_backend() {
  cd "$BACKEND_DIR"
  BACKEND_ROLLBACK_COMMIT="$(git rev-parse HEAD)"
  log "Backend: titik aman dicatat di ${BACKEND_ROLLBACK_COMMIT:0:8}"

  trap 'rollback_backend' ERR

  [[ -d vendor ]] && rm -rf vendor.bak && mv vendor vendor.bak

  git reset --hard origin/master
  "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction

  # Backup schema cepat sebelum migrate (jaring pengaman murah)
  if [[ -f .env ]]; then
    local db_name db_user db_pass
    db_name="$(grep -E '^DB_DATABASE=' .env | tail -n1 | cut -d= -f2-)"
    db_user="$(grep -E '^DB_USERNAME=' .env | tail -n1 | cut -d= -f2-)"
    db_pass="$(grep -E '^DB_PASSWORD=' .env | tail -n1 | cut -d= -f2-)"
    if [[ -n "$db_name" ]]; then
      mkdir -p "${LOG_DIR}/schema-backups"
      MYSQL_PWD="$db_pass" mysqldump --no-data -u"$db_user" "$db_name" \
        > "${LOG_DIR}/schema-backups/schema-${RUN_TS}.sql" 2>>"$LOG_FILE" || warn "Backup schema gagal, lanjut tanpa backup schema (bukan fatal)."
    fi
  fi

  "$PHP_BIN" artisan migrate --force --no-interaction
  "$PHP_BIN" artisan optimize:clear
  "$PHP_BIN" artisan config:cache
  "$PHP_BIN" artisan route:cache
  "$PHP_BIN" artisan view:cache
  "$PHP_BIN" artisan storage:link || true
  chown -R www:www storage bootstrap/cache

  trap - ERR
  BACKEND_DEPLOY_OK=1
  ok "Backend: deploy langkah kode selesai (${BACKEND_ROLLBACK_COMMIT:0:8} -> $(git rev-parse HEAD | cut -c1-8))"
}

# ---------------------------------------------------------------------------
# Deploy frontend
# ---------------------------------------------------------------------------
deploy_frontend() {
  cd "$FRONTEND_DIR"
  FRONTEND_ROLLBACK_COMMIT="$(git rev-parse HEAD)"
  log "Frontend: titik aman dicatat di ${FRONTEND_ROLLBACK_COMMIT:0:8}"

  trap 'rollback_frontend' ERR

  [[ -d .next ]] && rm -rf .next.bak && mv .next .next.bak

  git reset --hard origin/master
  npm ci
  npm run build

  trap - ERR
  FRONTEND_DEPLOY_OK=1
  ok "Frontend: deploy langkah kode selesai (${FRONTEND_ROLLBACK_COMMIT:0:8} -> $(git rev-parse HEAD | cut -c1-8))"
}

# ---------------------------------------------------------------------------
# Restart service — hanya untuk repo yang berubah. nginx & cloudflared* tidak disentuh.
# ---------------------------------------------------------------------------
restart_backend_services() {
  log "Restart service backend: ${BACKEND_SERVICES[*]}"
  systemctl restart "${BACKEND_SERVICES[@]}"
}

restart_frontend_service() {
  log "Restart PM2: $PM2_PROCESS"
  pm2 restart "$PM2_PROCESS" --update-env
  pm2 save
}

# ---------------------------------------------------------------------------
# Health check — kegagalan di sini dianggap kegagalan deploy (trigger rollback)
# ---------------------------------------------------------------------------
health_check_backend() {
  local tries=5
  for ((i=1; i<=tries; i++)); do
    sleep 2
    # nginx routing berbasis Host header (virtual hosting) - tanpa header ini
    # 127.0.0.1 jatuh ke vhost default dan selalu 404 walau backend sehat.
    if curl -sf -o /dev/null -H "Host: ${HEALTH_HOST}" "$HEALTH_URL"; then
      ok "Health check backend OK ($HEALTH_URL, Host: ${HEALTH_HOST})"
      return 0
    fi
    warn "Health check backend percobaan ${i}/${tries} gagal, coba lagi..."
  done
  return 1
}

health_check_frontend() {
  local tries=5
  for ((i=1; i<=tries; i++)); do
    sleep 2
    local status restarts
    status="$(pm2 jlist | node -e "const l=JSON.parse(require('fs').readFileSync(0,'utf8'));const p=l.find(x=>x.name==='${PM2_PROCESS}');console.log(p?p.pm2_env.status:'missing')" 2>/dev/null || echo "missing")"
    if [[ "$status" == "online" ]]; then
      ok "Health check frontend OK (PM2 status: online)"
      return 0
    fi
    warn "Health check frontend percobaan ${i}/${tries}: status=${status}, coba lagi..."
  done
  return 1
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
if [[ "$BACKEND_CHANGED" == "1" ]]; then
  deploy_backend
  restart_backend_services
  if ! health_check_backend; then
    rollback_backend
    restart_backend_services
    fail "Deploy backend GAGAL total (health check tidak lulus setelah rollback). Cek log: $LOG_FILE"
    exit 1
  fi
  rm -rf "${BACKEND_DIR}/vendor.bak"
  # Salin versi script terbaru ke lokasi eksekusi stabil (di luar repo).
  cp "${BACKEND_DIR}/scripts/deploy-production.sh" "$STABLE_SCRIPT"
  chmod +x "$STABLE_SCRIPT"
fi

if [[ "$FRONTEND_CHANGED" == "1" ]]; then
  deploy_frontend
  restart_frontend_service
  if ! health_check_frontend; then
    rollback_frontend
    restart_frontend_service
    fail "Deploy frontend GAGAL total (health check tidak lulus setelah rollback). Cek log: $LOG_FILE"
    exit 1
  fi
  rm -rf "${FRONTEND_DIR}/.next.bak"
fi

DURATION=$(( $(date +%s) - START_TS ))
ok "=== Deploy selesai dalam ${DURATION}s ==="
[[ "$BACKEND_CHANGED" == "1" ]] && ok "Backend: ${BACKEND_ROLLBACK_COMMIT:0:8} -> $(git -C "$BACKEND_DIR" rev-parse HEAD | cut -c1-8), service: ${BACKEND_SERVICES[*]}"
[[ "$FRONTEND_CHANGED" == "1" ]] && ok "Frontend: ${FRONTEND_ROLLBACK_COMMIT:0:8} -> $(git -C "$FRONTEND_DIR" rev-parse HEAD | cut -c1-8), service: PM2 $PM2_PROCESS"
log "Log lengkap: $LOG_FILE"
