#!/usr/bin/env bash
#
# rollback.sh — kembalikan trafik E-Report dari stack Docker ke stack native.
#
# Pakai (root): /opt/ereport/rollback.sh [BACKUP_DIR]
# Tanpa argumen memakai backup terakhir yang dicatat cutover.sh di /opt/ereport/cutover-backup-dir.
#
# Database tidak pernah disentuh. Container be-php/be-web/frontend dibiarkan jalan
# (tidak menerima trafik); worker container (queue/scheduler/reverb) dimatikan
# SEBELUM queue native dinyalakan supaya job tidak diproses dobel.
# Tiap langkah best-effort: kegagalan dicatat lalu lanjut ke langkah berikutnya.

set -uo pipefail

STACK_DIR=/opt/ereport
COMPOSE_FILE="$STACK_DIR/compose.yml"
STACK_ENV="$STACK_DIR/.env"
API_HOST=api-ereport.interiorcustom.id
FE_URL=https://putrainterior.com/login
NGX_REWRITE=/www/server/panel/vhost/rewrite/${API_HOST}.conf
NGX_REVERB=/www/server/panel/vhost/nginx/extension/${API_HOST}/reverb.conf
CRON_FILE=/etc/cron.d/ereport-scheduler
TUNNEL_CFG=/etc/cloudflared/putrainterior-config.yml
PM2_PROCESS=e_report_frontend

export PM2_HOME=/root/.pm2
export PATH=/www/server/nvm/versions/node/v22.23.0/bin:$PATH

BACKUP_DIR="${1:-$(cat "$STACK_DIR/cutover-backup-dir" 2>/dev/null || true)}"
TS="$(date +%Y%m%d-%H%M%S)"
LOG_FILE="/var/log/e-report-deploy/docker-rollback-${TS}.log"
FAILED=()

mkdir -p "$(dirname "$LOG_FILE")"
log()  { echo "[$(date '+%H:%M:%S')] $*" | tee -a "$LOG_FILE"; }
code() { curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$@" || true; }
fail() { log "  GAGAL: $*"; FAILED+=("$*"); }

wait_code() {
  local want=$1 tries=$2
  shift 2
  for ((i = 1; i <= tries; i++)); do
    [[ "$(code "$@")" == "$want" ]] && return 0
    sleep 2
  done
  return 1
}

[[ $EUID -eq 0 ]] || { echo "harus dijalankan sebagai root"; exit 1; }
[[ -n "$BACKUP_DIR" && -d "$BACKUP_DIR" ]] || { echo "BACKUP_DIR tidak ditemukan: '${BACKUP_DIR}'"; exit 1; }
log "== Rollback memakai backup ${BACKUP_DIR}"

# 1. Frontend dulu (paling terlihat user): nyalakan FE native, baru balikkan tunnel.
log "== 1. Frontend kembali ke pm2 127.0.0.1:3000"
pm2 restart "$PM2_PROCESS" >> "$LOG_FILE" 2>&1 || fail "pm2 restart $PM2_PROCESS"
wait_code 200 30 -H 'X-Forwarded-Proto: https' http://127.0.0.1:3000/login || fail "FE native 3000 tidak 200"
if [[ -f "$BACKUP_DIR/putrainterior-config.yml" ]]; then
  cp -a "$BACKUP_DIR/putrainterior-config.yml" "$TUNNEL_CFG" \
    && cloudflared tunnel --config "$TUNNEL_CFG" ingress validate >> "$LOG_FILE" 2>&1 \
    && systemctl restart cloudflared-putrainterior \
    || fail "restore tunnel putrainterior"
else
  fail "backup tunnel tidak ada"
fi

# 2. Reverb native dulu (8080), baru nginx dikembalikan menunjuk ke sana.
log "== 2. Reverb native + nginx vhost"
systemctl enable --now ereport-reverb >> "$LOG_FILE" 2>&1 || fail "start ereport-reverb"
cp -a "$BACKUP_DIR/rewrite.conf" "$NGX_REWRITE" || fail "restore rewrite.conf"
cp -a "$BACKUP_DIR/reverb.conf" "$NGX_REVERB" || fail "restore reverb.conf"
if nginx -t >> "$LOG_FILE" 2>&1; then
  systemctl reload nginx || fail "reload nginx"
else
  fail "nginx -t gagal setelah restore"
fi

# 3. Worker container mati DULU, baru queue native + cron scheduler.
log "== 3. Worker container off, worker native on"
touch "$STACK_ENV"
if grep -q '^WORKER_SCALE=' "$STACK_ENV"; then
  sed -i 's/^WORKER_SCALE=.*/WORKER_SCALE=0/' "$STACK_ENV"
else
  echo 'WORKER_SCALE=0' >> "$STACK_ENV"
fi
docker compose -f "$COMPOSE_FILE" --env-file "$STACK_ENV" up -d >> "$LOG_FILE" 2>&1 \
  || fail "compose up WORKER_SCALE=0"
for s in queue scheduler reverb; do
  docker compose -f "$COMPOSE_FILE" --env-file "$STACK_ENV" ps --status running --services 2>/dev/null | grep -cx "$s" >/dev/null \
    && fail "worker container $s masih running"
done
systemctl enable --now ereport-queue >> "$LOG_FILE" 2>&1 || fail "start ereport-queue"
if [[ -f "${CRON_FILE}.disabled-docker" ]]; then
  mv "${CRON_FILE}.disabled-docker" "$CRON_FILE" || fail "kembalikan cron scheduler"
elif [[ ! -f "$CRON_FILE" ]]; then
  cp -a "$BACKUP_DIR/ereport-scheduler.cron" "$CRON_FILE" || fail "restore cron scheduler dari backup"
fi

# 4. Verifikasi.
log "== 4. Verifikasi"
wait_code 200 10 "https://${API_HOST}/up" || fail "API publik /up tidak 200"
wait_code 204 5 "https://${API_HOST}/sanctum/csrf-cookie" || fail "csrf publik tidak 204"
wait_code 200 20 "$FE_URL" || fail "FE publik tidak 200"
systemctl is-active ereport-queue ereport-reverb php-fpm-83 nginx cloudflared-putrainterior | tr '\n' ' ' | tee -a "$LOG_FILE"; echo

rm -f "$STACK_DIR/CUTOVER_DONE"
if ((${#FAILED[@]})); then
  log "== ROLLBACK SELESAI DENGAN ${#FAILED[@]} MASALAH:"
  printf '   - %s\n' "${FAILED[@]}" | tee -a "$LOG_FILE"
  log "Log: ${LOG_FILE}"
  exit 1
fi
log "== ROLLBACK SELESAI — trafik kembali ke native. Log: ${LOG_FILE}"
