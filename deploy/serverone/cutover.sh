#!/usr/bin/env bash
#
# cutover.sh — pindahkan trafik E-Report dari stack native ke stack Docker "ereport".
#
# Plan: E-REPORT-APP/PLAN (INI ADALAH IDE ATAU UPDATE)/PLAN_DOCKER_HYBRID_SERVERONE.md (Fase 4)
#
# Pakai (root, dari /opt/ereport):
#   ./cutover.sh --check   # cek prasyarat saja, tidak mengubah apa pun
#   ./cutover.sh           # jalankan cutover
#
# Yang diubah: ereport-queue/ereport-reverb (disable), cron ereport-scheduler (rename),
# rewrite + extension reverb nginx vhost api-ereport, tunnel putrainterior, pm2 stop FE.
# Yang TIDAK pernah disentuh: database (aturan user 2026-10-17), Redis, php-fpm-83,
# isi .env/storage, cloudflared api-ereport.
#
# Kalau langkah apa pun gagal setelah perubahan pertama, rollback.sh dijalankan otomatis.

set -Eeuo pipefail

STACK_DIR=/opt/ereport
COMPOSE_FILE="$STACK_DIR/compose.yml"
STACK_ENV="$STACK_DIR/.env"
BE_DIR=/www/wwwroot/api-ereport.interiorcustom.id
API_HOST=api-ereport.interiorcustom.id
FE_URL=https://putrainterior.com/login
NGX_REWRITE=/www/server/panel/vhost/rewrite/${API_HOST}.conf
NGX_REVERB=/www/server/panel/vhost/nginx/extension/${API_HOST}/reverb.conf
CRON_FILE=/etc/cron.d/ereport-scheduler
TUNNEL_CFG=/etc/cloudflared/putrainterior-config.yml
PM2_PROCESS=e_report_frontend
MARKER="$STACK_DIR/cutover-backup-dir"

export PM2_HOME=/root/.pm2
export PATH=/www/server/nvm/versions/node/v22.23.0/bin:$PATH

TS="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="/data/app-backups/docker-cutover-${TS}"
LOG_FILE="/var/log/e-report-deploy/docker-cutover-${TS}.log"
CHANGED=0

mkdir -p "$(dirname "$LOG_FILE")"
log()  { echo "[$(date '+%H:%M:%S')] $*" | tee -a "$LOG_FILE"; }
code() { curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$@" || true; }

# wait_code <expected> <tries> <curl args...> — retry tiap 2 detik.
wait_code() {
  local want=$1 tries=$2 got=""
  shift 2
  for ((i = 1; i <= tries; i++)); do
    got="$(code "$@")"
    [[ "$got" == "$want" ]] && return 0
    sleep 2
  done
  log "  dapat ${got}, harusnya ${want}"
  return 1
}

die() {
  trap - ERR
  log "GAGAL: $*"
  if [[ $CHANGED == 1 ]]; then
    log "Menjalankan rollback otomatis..."
    "$STACK_DIR/rollback.sh" "$BACKUP_DIR" || log "Rollback juga gagal — cek manual, backup di $BACKUP_DIR"
  else
    log "Belum ada perubahan di server."
  fi
  exit 1
}
trap 'die "perintah gagal di baris $LINENO"' ERR

compose() { touch "$STACK_ENV"; docker compose -f "$COMPOSE_FILE" --env-file "$STACK_ENV" "$@"; }

set_worker_scale() {
  touch "$STACK_ENV"
  if grep -q '^WORKER_SCALE=' "$STACK_ENV"; then
    sed -i "s/^WORKER_SCALE=.*/WORKER_SCALE=$1/" "$STACK_ENV"
  else
    echo "WORKER_SCALE=$1" >> "$STACK_ENV"
  fi
}

env_get() {
  grep -E "^$1=" "$BE_DIR/.env" | tail -n1 | cut -d= -f2- | sed -E "s/^\"(.*)\"\$/\1/; s/^'(.*)'\$/\1/"
}

# ---------------------------------------------------------------------------
log "== 0. Cek prasyarat"
[[ $EUID -eq 0 ]] || die "harus dijalankan sebagai root"
for f in "$COMPOSE_FILE" "$STACK_DIR/rollback.sh" "$NGX_REWRITE" "$NGX_REVERB" "$CRON_FILE" "$TUNNEL_CFG" "$BE_DIR/.env"; do
  [[ -f "$f" ]] || die "file tidak ada: $f"
done
grep -q '127.0.0.1:8100' "$NGX_REWRITE" && die "rewrite nginx sudah menunjuk ke 8100 — cutover sudah pernah jalan?"
grep -q 'service: http://127.0.0.1:3000' "$TUNNEL_CFG" || die "tunnel putrainterior tidak menunjuk 127.0.0.1:3000 (Fase 0 belum selesai?)"
[[ "$(grep -c 'proxy_pass http://127.0.0.1:8080;' "$NGX_REVERB")" == 2 ]] || die "reverb.conf tidak berisi 2 proxy_pass ke 8080 seperti yang diharapkan"

for c in ereport-be-web-1 ereport-frontend-1; do
  [[ "$(docker inspect -f '{{.State.Health.Status}}' "$c" 2>/dev/null || true)" == healthy ]] || die "$c tidak healthy"
done
[[ "$(docker inspect -f '{{.State.Status}}' ereport-be-php-1 2>/dev/null || true)" == running ]] || die "ereport-be-php-1 tidak running"
wait_code 200 3 "http://127.0.0.1:8100/up" || die "be-web /up tidak 200"
wait_code 204 3 -H 'X-Forwarded-Proto: https' "http://127.0.0.1:8100/sanctum/csrf-cookie" || die "be-web csrf (X-Forwarded-Proto https) tidak 204"
wait_code 200 3 -H 'X-Forwarded-Proto: https' "http://127.0.0.1:3100/login" || die "frontend container /login tidak 200"
wait_code 200 3 "https://${API_HOST}/up" || die "API publik native tidak 200 sebelum cutover"
wait_code 200 3 "$FE_URL" || die "FE publik native tidak 200 sebelum cutover"
ss -tln | grep -c '127.0.0.1:8180 ' >/dev/null && die "port 8180 sudah dipakai"
compose config --quiet || die "compose.yml tidak valid"
log "Prasyarat OK."

if [[ "${1:-}" == "--check" ]]; then
  log "Mode --check: tidak ada yang diubah."
  exit 0
fi

# ---------------------------------------------------------------------------
log "== 1. Backup config + database (sebelum perubahan apa pun)"
mkdir -p "$BACKUP_DIR"
cp -a "$NGX_REWRITE" "$BACKUP_DIR/rewrite.conf"
cp -a "$NGX_REVERB" "$BACKUP_DIR/reverb.conf"
cp -a "$CRON_FILE" "$BACKUP_DIR/ereport-scheduler.cron"
cp -a "$TUNNEL_CFG" "$BACKUP_DIR/putrainterior-config.yml"
cp -a "$STACK_ENV" "$BACKUP_DIR/stack.env" 2>/dev/null || true

db_name="$(env_get DB_DATABASE)"
db_user="$(env_get DB_USERNAME)"
db_host="$(env_get DB_HOST)"
db_port="$(env_get DB_PORT)"
db_dump="/data/db-backups/pre-docker-cutover-${TS}.sql.gz"
MYSQL_PWD="$(env_get DB_PASSWORD)" mysqldump --single-transaction --quick \
  -h "${db_host:-127.0.0.1}" -P "${db_port:-3306}" -u "$db_user" "$db_name" | gzip > "$db_dump"
gzip -t "$db_dump"
zcat "$db_dump" | tail -n 1 | grep -c 'Dump completed' >/dev/null || die "dump DB tidak lengkap: $db_dump"
log "Backup config: $BACKUP_DIR"
log "Backup DB:     $db_dump ($(du -h "$db_dump" | cut -f1))"
echo "$BACKUP_DIR" > "$MARKER"

CHANGED=1

# ---------------------------------------------------------------------------
log "== 2. Matikan worker native (queue + scheduler)"
systemctl disable --now ereport-queue
mv "$CRON_FILE" "${CRON_FILE}.disabled-docker"   # cron mengabaikan nama file bertitik

log "== 3. Nyalakan worker container (queue, scheduler, reverb:8180)"
set_worker_scale 1
compose up -d
for ((i = 1; i <= 30; i++)); do
  ss -tln | grep -c '127.0.0.1:8180 ' >/dev/null && break
  sleep 2
done
ss -tln | grep -c '127.0.0.1:8180 ' >/dev/null || die "reverb container tidak listen di 127.0.0.1:8180"
for s in queue scheduler reverb; do
  compose ps --status running --services | grep -cx "$s" >/dev/null || die "service $s tidak running"
done

log "== 4. Alihkan backend (nginx vhost) ke be-web 127.0.0.1:8100"
{
  echo "# E-Report: dilayani stack Docker \"ereport\" (be-web 127.0.0.1:8100) sejak cutover ${TS}."
  echo "# Versi native ada di ${BACKUP_DIR}/rewrite.conf. Rollback: ${STACK_DIR}/rollback.sh"
  echo "# JANGAN simpan ulang URL rewrite situs ini dari panel aaPanel (menimpa file ini)."
  echo "# Header X-Forwarded-* dari cloudflared SENGAJA diteruskan apa adanya (tidak di-set ulang):"
  echo "# SecurityHeaders redirect 308 kalau X-Forwarded-Proto=http, dan \$scheme di sini selalu http."
  cat <<'NGINX'
location / {
    proxy_pass http://127.0.0.1:8100;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header Connection "";
    proxy_read_timeout 300s;
    proxy_send_timeout 300s;
}
NGINX
} > "$NGX_REWRITE"
sed -i 's#proxy_pass http://127.0.0.1:8080;#proxy_pass http://127.0.0.1:8180;#g' "$NGX_REVERB"
nginx -t 2>&1 | tee -a "$LOG_FILE"
systemctl reload nginx
systemctl disable --now ereport-reverb

probe="cutover=${TS}"
wait_code 200 10 -H "Host: ${API_HOST}" "http://127.0.0.1/up?${probe}-local" || die "/up lewat nginx host tidak 200"
wait_code 200 10 "https://${API_HOST}/up?${probe}" || die "/up publik tidak 200"
wait_code 204 5 "https://${API_HOST}/sanctum/csrf-cookie" || die "csrf publik tidak 204"
sleep 2
docker logs --since 5m ereport-be-web-1 2>&1 | grep -c "${probe}" >/dev/null || die "request publik tidak sampai ke be-web (masih dilayani native?)"
log "Backend publik dilayani container."

# ---------------------------------------------------------------------------
log "== 5. Alihkan frontend (tunnel putrainterior) ke 127.0.0.1:3100"
sed -i 's#service: http://127.0.0.1:3000#service: http://127.0.0.1:3100#' "$TUNNEL_CFG"
cloudflared tunnel --config "$TUNNEL_CFG" ingress validate >> "$LOG_FILE" 2>&1
systemctl restart cloudflared-putrainterior
wait_code 200 20 "$FE_URL" || die "FE publik tidak 200 setelah tunnel dialihkan"
pm2 stop "$PM2_PROCESS" >> "$LOG_FILE" 2>&1
sleep 3
wait_code 200 10 "$FE_URL" || die "FE publik tidak 200 setelah pm2 dihentikan"
wait_code 200 5 "https://www.putrainterior.com/login" || die "www FE publik tidak 200"
log "Frontend publik dilayani container (pm2 stop, tidak di-save: reboot hanya menyalakan FE native di 3000 yang tidak menerima trafik)."

trap - ERR
date '+%F %T' > "$STACK_DIR/CUTOVER_DONE"
log "== CUTOVER SELESAI ${TS}"
log "Rollback kapan pun: ${STACK_DIR}/rollback.sh"
log "Log: ${LOG_FILE}"
compose ps --format 'table {{.Service}}\t{{.Status}}' | tee -a "$LOG_FILE"
