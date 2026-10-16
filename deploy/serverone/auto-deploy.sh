#!/usr/bin/env bash
#
# auto-deploy.sh — deploy otomatis E-Report di serverone (pull-based, build di server).
#
# Dijalankan systemd timer ereport-auto-deploy.timer tiap 5 menit sebagai root.
# Alur: fetch master BE/FE dari GitHub (repo publik, tanpa credential) → kalau SHA
# beda dari yang sedang jalan: build image dari `git archive` commit itu → backup DB
# + migrate (hanya kalau BE berubah) → `compose up -d` → health check → kalau gagal,
# kembalikan tag image sebelumnya. Migration TIDAK di-rollback (pakai pola
# expand-and-contract; DB produksi tidak boleh di-reset, aturan user 2026-10-17).
#
# Jeda sementara : touch /opt/ereport/AUTO_DEPLOY_PAUSED
# Jalankan manual: /opt/ereport/auto-deploy.sh            (sama dengan timer)
#                  /opt/ereport/auto-deploy.sh --dry-run  (cek SHA saja, tanpa build/deploy)
#                  /opt/ereport/auto-deploy.sh --force    (coba lagi pasangan SHA yang pernah gagal)
# Pasangan SHA yang gagal dicatat di deployed.env (FAILED_PAIR) dan dilewati timer
# sampai ada commit baru, supaya build gagal tidak diulang tiap 5 menit.

set -Eeuo pipefail

STACK_DIR=/opt/ereport
COMPOSE_FILE="$STACK_DIR/compose.yml"
STACK_ENV="$STACK_DIR/.env"
STATE_FILE="$STACK_DIR/deployed.env"
FE_BUILD_ENV="$STACK_DIR/frontend.build.env"
SRC_DIR="$STACK_DIR/src"
BUILD_DIR="$STACK_DIR/build"
BRANCH=master
BE_REPO=https://github.com/xamonxx/api_e-report_v0.2.git
FE_REPO=https://github.com/xamonxx/frontend_e-report_v02-NextJs.git
BE_DIR=/www/wwwroot/api-ereport.interiorcustom.id
API_HOST=api-ereport.interiorcustom.id
LOCK_FILE=/var/lock/ereport-auto-deploy.lock
LOG_DIR=/var/log/e-report-deploy
DRY_RUN=0; FORCE=0
[[ "${1:-}" == "--dry-run" ]] && DRY_RUN=1
[[ "${1:-}" == "--force" ]] && FORCE=1

exec 9> "$LOCK_FILE"
flock -n 9 || { echo "auto-deploy lain sedang berjalan, lewati."; exit 0; }

[[ -f "$STACK_DIR/AUTO_DEPLOY_PAUSED" ]] && { echo "AUTO_DEPLOY_PAUSED ada, lewati."; exit 0; }
[[ -f "$STACK_DIR/CUTOVER_DONE" ]] || { echo "CUTOVER_DONE tidak ada (trafik di native/rollback), lewati."; exit 0; }

TS="$(date +%Y%m%d-%H%M%S)"
LOG_FILE="$LOG_DIR/auto-deploy-${TS}.log"
mkdir -p "$LOG_DIR" "$SRC_DIR" "$BUILD_DIR"

# Log ke file hanya kalau benar-benar ada rilis; cek rutin tiap 5 menit cukup ke journal.
log()  { echo "[$(date '+%H:%M:%S')] $*" | tee -a "$LOG_FILE"; }
code() { curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$@" || true; }
wait_code() {
  local want=$1 tries=$2
  shift 2
  for ((i = 1; i <= tries; i++)); do
    [[ "$(code "$@")" == "$want" ]] && return 0
    sleep 3
  done
  return 1
}
compose() { docker compose -f "$COMPOSE_FILE" --env-file "$STACK_ENV" "$@"; }

env_set() { # env_set FILE KEY VALUE
  touch "$1"
  if grep -q "^$2=" "$1"; then sed -i "s|^$2=.*|$2=$3|" "$1"; else echo "$2=$3" >> "$1"; fi
}
env_get_file() { grep -E "^$2=" "$1" 2>/dev/null | tail -n1 | cut -d= -f2- || true; }

fetch_sha() { # fetch_sha NAME URL -> SHA origin/master (mirror bare di $SRC_DIR/NAME.git)
  local repo="$SRC_DIR/$1.git"
  [[ -d "$repo" ]] || git clone --quiet --bare "$2" "$repo"
  git -C "$repo" fetch --quiet --prune origin "+refs/heads/${BRANCH}:refs/heads/${BRANCH}"
  git -C "$repo" rev-parse "refs/heads/${BRANCH}"
}

export_tree() { # export_tree NAME SHA DEST
  rm -rf "$3"; mkdir -p "$3"
  git -C "$SRC_DIR/$1.git" archive "$2" | tar -x -C "$3"
}

# ---------------------------------------------------------------------------
BE_NEW="$(fetch_sha be "$BE_REPO")"
FE_NEW="$(fetch_sha fe "$FE_REPO")"
BE_CUR="$(env_get_file "$STATE_FILE" BE_SHA)"
FE_CUR="$(env_get_file "$STATE_FILE" FE_SHA)"

BE_CHANGED=0; FE_CHANGED=0
[[ "$BE_NEW" != "$BE_CUR" ]] && BE_CHANGED=1
[[ "$FE_NEW" != "$FE_CUR" ]] && FE_CHANGED=1

if [[ $BE_CHANGED == 0 && $FE_CHANGED == 0 ]]; then
  echo "Tidak ada perubahan (BE ${BE_CUR:0:7}, FE ${FE_CUR:0:7})."
  exit 0
fi

PAIR="${BE_NEW}:${FE_NEW}"
if [[ $FORCE == 0 && "$(env_get_file "$STATE_FILE" FAILED_PAIR)" == "$PAIR" ]]; then
  echo "BE ${BE_NEW:0:7} / FE ${FE_NEW:0:7} pernah gagal, lewati (pakai --force untuk coba lagi)."
  exit 0
fi

if [[ $DRY_RUN == 1 ]]; then
  echo "DRY RUN — BE ${BE_CUR:0:7} -> ${BE_NEW:0:7} (berubah=$BE_CHANGED), FE ${FE_CUR:0:7} -> ${FE_NEW:0:7} (berubah=$FE_CHANGED)"
  exit 0
fi

log "== Rilis baru: BE ${BE_CUR:0:7} -> ${BE_NEW:0:7} (berubah=$BE_CHANGED), FE ${FE_CUR:0:7} -> ${FE_NEW:0:7} (berubah=$FE_CHANGED)"

PREV_BE_PHP="$(env_get_file "$STACK_ENV" BE_PHP_IMAGE)"
PREV_BE_WEB="$(env_get_file "$STACK_ENV" BE_WEB_IMAGE)"
PREV_FE="$(env_get_file "$STACK_ENV" FE_IMAGE)"
DEPLOYED=0

restore_previous() {
  log "Mengembalikan image sebelumnya..."
  env_set "$STACK_ENV" BE_PHP_IMAGE "$PREV_BE_PHP"
  env_set "$STACK_ENV" BE_WEB_IMAGE "$PREV_BE_WEB"
  env_set "$STACK_ENV" FE_IMAGE "$PREV_FE"
  compose up -d >> "$LOG_FILE" 2>&1 || log "compose up rollback GAGAL — cek manual"
}
fail() {
  trap - ERR
  log "GAGAL: $*"
  if [[ $DEPLOYED == 1 ]]; then restore_previous; fi
  env_set "$STATE_FILE" FAILED_PAIR "$PAIR"
  log "Log: $LOG_FILE"
  exit 1
}
# Mulai di sini kegagalan apa pun (build, migrate, health) dicatat sebagai FAILED_PAIR.
trap 'fail "perintah gagal di baris $LINENO"' ERR

# 1. Build semua image dulu. Kalau ada yang gagal, tidak ada yang berubah di produksi.
NEW_BE_PHP="$PREV_BE_PHP"; NEW_BE_WEB="$PREV_BE_WEB"; NEW_FE="$PREV_FE"
if [[ $BE_CHANGED == 1 ]]; then
  ctx="$BUILD_DIR/be"
  export_tree be "$BE_NEW" "$ctx"
  [[ -f "$ctx/Dockerfile.prod" && -f "$ctx/docker/nginx/serverone.conf" ]] \
    || fail "BE ${BE_NEW:0:7} belum punya Dockerfile.prod multi-target"
  tag="${BE_NEW:0:7}"
  log "Build ereport/backend-php:$tag + backend-web:$tag"
  docker build --quiet -f "$ctx/Dockerfile.prod" --target php -t "ereport/backend-php:$tag" "$ctx" >> "$LOG_FILE" 2>&1
  docker build --quiet -f "$ctx/Dockerfile.prod" --target web -t "ereport/backend-web:$tag" "$ctx" >> "$LOG_FILE" 2>&1
  NEW_BE_PHP="ereport/backend-php:$tag"; NEW_BE_WEB="ereport/backend-web:$tag"
fi
if [[ $FE_CHANGED == 1 ]]; then
  ctx="$BUILD_DIR/fe"
  export_tree fe "$FE_NEW" "$ctx"
  [[ -f "$ctx/Dockerfile.prod" ]] || fail "FE ${FE_NEW:0:7} belum punya Dockerfile.prod"
  [[ -f "$FE_BUILD_ENV" ]] || fail "$FE_BUILD_ENV tidak ada"
  build_args=()
  while IFS= read -r line; do
    [[ -z "$line" || "$line" == \#* ]] && continue
    build_args+=(--build-arg "$line")
  done < "$FE_BUILD_ENV"
  tag="${FE_NEW:0:7}"
  log "Build ereport/frontend:$tag"
  docker build --quiet -f "$ctx/Dockerfile.prod" "${build_args[@]}" -t "ereport/frontend:$tag" "$ctx" >> "$LOG_FILE" 2>&1
  NEW_FE="ereport/frontend:$tag"
fi

# 2. Backend berubah: backup DB lalu migrate pakai image baru (sebelum container diganti).
if [[ $BE_CHANGED == 1 ]]; then
  env_db() { grep -E "^$1=" "$BE_DIR/.env" | tail -n1 | cut -d= -f2- | sed -E "s/^\"(.*)\"\$/\1/; s/^'(.*)'\$/\1/"; }
  dump="/data/db-backups/pre-release-${BE_NEW:0:7}-${TS}.sql.gz"
  MYSQL_PWD="$(env_db DB_PASSWORD)" mysqldump --single-transaction --quick \
    -h "$(env_db DB_HOST)" -P "$(env_db DB_PORT)" -u "$(env_db DB_USERNAME)" "$(env_db DB_DATABASE)" | gzip > "$dump"
  zcat "$dump" | tail -n 1 | grep -c 'Dump completed' > /dev/null || fail "backup DB tidak lengkap"
  log "Backup DB: $dump"
  log "Migrate --force dengan $NEW_BE_PHP"
  BE_PHP_IMAGE="$NEW_BE_PHP" compose run --rm --no-deps -e RUN_OPTIMIZE=false be-php \
    php artisan migrate --force --no-interaction >> "$LOG_FILE" 2>&1 || fail "migrate gagal (container lama tetap jalan)"
fi

# 3. Ganti container.
DEPLOYED=1
env_set "$STACK_ENV" BE_PHP_IMAGE "$NEW_BE_PHP"
env_set "$STACK_ENV" BE_WEB_IMAGE "$NEW_BE_WEB"
env_set "$STACK_ENV" FE_IMAGE "$NEW_FE"
log "compose up -d"
compose up -d >> "$LOG_FILE" 2>&1

# 4. Health check (header seperti trafik asli: X-Forwarded-Proto https, lihat Lessons-Log).
wait_code 200 30 "http://127.0.0.1:8100/up" || fail "be-web /up tidak 200"
wait_code 204 10 -H 'X-Forwarded-Proto: https' "http://127.0.0.1:8100/sanctum/csrf-cookie" || fail "be-web csrf tidak 204"
wait_code 200 30 -H 'X-Forwarded-Proto: https' "http://127.0.0.1:3100/login" || fail "frontend /login tidak 200"
wait_code 200 10 "https://${API_HOST}/up" || fail "API publik /up tidak 200"
wait_code 200 10 "https://putrainterior.com/login" || fail "FE publik tidak 200"
for s in queue scheduler reverb; do
  compose ps --status running --services | grep -cx "$s" > /dev/null || fail "service $s tidak running"
done

trap - ERR
env_set "$STATE_FILE" BE_SHA "$BE_NEW"
env_set "$STATE_FILE" FE_SHA "$FE_NEW"
env_set "$STATE_FILE" DEPLOYED_AT "$TS"
sed -i '/^FAILED_PAIR=/d' "$STATE_FILE"
log "== Rilis sukses: BE ${BE_NEW:0:7}, FE ${FE_NEW:0:7}"
