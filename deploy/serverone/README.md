# Stack Docker E-Report di serverone (Portainer)

Plan lengkap: `E-REPORT-APP/PLAN (INI ADALAH IDE ATAU UPDATE)/PLAN_DOCKER_HYBRID_SERVERONE.md`.

## Aturan

- Database produksi = MariaDB native `sql_api_ereport_interiorcustom_id`. Stack ini tidak punya
  service DB dan tidak boleh diberi. Dilarang `migrate:fresh|refresh|reset|rollback`, `db:wipe`,
  `db:seed`, import dump penimpa, `DROP DATABASE`, `down -v`.
- Semua service `network_mode: host`, bind `127.0.0.1` saja (UFW mengizinkan LAN/tailnet ke port apa pun).
- Data persisten tetap di path native: `.env` (read-only) dan `storage/`.

## Port

| Service | Bind | Native padanannya |
|---|---|---|
| be-web (nginx sidecar) | 127.0.0.1:8100 | vhost nginx `api-ereport` (port 80) |
| be-php (PHP-FPM) | 127.0.0.1:9100 | `/tmp/php-cgi-83.sock` |
| frontend | 127.0.0.1:3100 | pm2 `e_report_frontend` 127.0.0.1:3000 |
| reverb (scale 0) | 127.0.0.1:8180 | `ereport-reverb` 127.0.0.1:8080 |
| queue, scheduler (scale 0) | - | `ereport-queue`, cron `ereport-scheduler` |

## Image

Dibangun di daemon serverone dari **commit produksi** + file Docker saja (bukan working tree lokal):

```bash
git -C E-REPORT-BACKEND_API archive <sha> | tar -x -C ctx-be   # lalu overlay Dockerfile.prod, .dockerignore, docker/
DOCKER_HOST=ssh://serverone docker build -f ctx-be/Dockerfile.prod --target php -t ereport/backend-php:<sha> ctx-be
DOCKER_HOST=ssh://serverone docker build -f ctx-be/Dockerfile.prod --target web -t ereport/backend-web:<sha> ctx-be
DOCKER_HOST=ssh://serverone docker build -f ctx-fe/Dockerfile.prod <build-arg NEXT_PUBLIC_* dari .env.production server> -t ereport/frontend:<sha> ctx-fe
```

Build arg FE wajib sama dengan `/www/wwwroot/e-report-frontend/.env.production` (nilai publik):
`API_PROXY_TARGET`, `NEXT_PUBLIC_API_URL`, `NEXT_PUBLIC_SAME_ORIGIN_API=true`, `NEXT_PUBLIC_REVERB_HOST`,
`NEXT_PUBLIC_REVERB_PORT=443`, `NEXT_PUBLIC_REVERB_SCHEME=https`, `NEXT_PUBLIC_REVERB_APP_KEY`.

## Buat stack di Portainer

1. Buka `https://100.76.23.12:9443` (hanya dari tailnet), environment `local`.
2. **Stacks → Add stack**, name: `ereport`, build method **Web editor**.
3. Tempel isi `compose.yml`. Environment variables tidak wajib; untuk rilis baru cukup isi
   `BE_PHP_IMAGE`, `BE_WEB_IMAGE`, `FE_IMAGE` dengan tag baru.
4. Matikan opsi pull image (image lokal, tidak ada di registry). **Deploy the stack**.
5. Cek: be-web dan frontend `healthy`, be-php `running`, queue/scheduler/reverb tidak punya container (scale 0).

## Smoke test (tanpa trafik publik)

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8100/up                                   # 200
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8100/sanctum/csrf-cookie                  # 204
curl -s -o /dev/null -w '%{http_code}\n' -H 'X-Forwarded-Proto: https' http://127.0.0.1:3100/login  # 200
ss -tln | grep -E ':(3100|8100|9100|8180) '                                                         # semua 127.0.0.1
```

## Deploy otomatis (rilis rutin)

Cukup **push/merge ke `master`** di repo BE atau FE. Timer `ereport-auto-deploy.timer` di serverone
menjalankan `/opt/ereport/auto-deploy.sh` tiap 5 menit:

1. fetch `master` kedua repo (publik) ke mirror `/opt/ereport/src/{be,fe}.git`
2. kalau SHA beda dari `/opt/ereport/deployed.env`: build image dari `git archive` commit itu
   (tag = 7 karakter SHA; build arg FE dari `/opt/ereport/frontend.build.env`)
3. BE berubah: dump DB penuh ke `/data/db-backups/pre-release-*.sql.gz`, lalu
   `migrate --force` dengan image baru (container lama masih melayani)
4. tulis tag baru ke `/opt/ereport/.env`, `compose up -d`, health check lokal + publik
5. gagal di build/migrate/health: tag lama dipasang lagi, pasangan SHA dicatat `FAILED_PAIR`
   dan tidak dicoba ulang sampai ada commit baru (atau `--force`)

Log rilis: `/var/log/e-report-deploy/auto-deploy-*.log`; cek rutin: `journalctl -u ereport-auto-deploy`.

```bash
/opt/ereport/auto-deploy.sh --dry-run          # lihat apakah ada rilis tertunda
/opt/ereport/auto-deploy.sh                    # deploy sekarang tanpa menunggu timer
touch /opt/ereport/AUTO_DEPLOY_PAUSED          # jeda auto-deploy (hapus file untuk lanjut)
```

Rollback rilis: set `BE_PHP_IMAGE`/`BE_WEB_IMAGE`/`FE_IMAGE` di `/opt/ereport/.env` ke tag lama,
`touch AUTO_DEPLOY_PAUSED`, lalu `docker compose -f /opt/ereport/compose.yml --env-file /opt/ereport/.env up -d`.
Migration tidak di-rollback: wajib pola expand-and-contract.

Image lama dan build cache tidak dihapus otomatis. Pembersihan lewat skill
`docker-destructive-guardrails` + konfirmasi user.

## Cutover & rollback

File stack di server: `/opt/ereport/` (`compose.yml`, `cutover.sh`, `rollback.sh`, `.env` berisi
`WORKER_SCALE`). Stack dikelola dengan `docker compose -f /opt/ereport/compose.yml --env-file /opt/ereport/.env`;
di Portainer tampil sebagai stack external (limited).

```bash
/opt/ereport/cutover.sh --check   # prasyarat saja, tidak mengubah apa pun
/opt/ereport/cutover.sh           # cutover, auto-rollback kalau ada langkah gagal
/opt/ereport/rollback.sh          # kembali ke native kapan pun
```

`cutover.sh`: backup config + dump DB penuh ke `/data/db-backups/pre-docker-cutover-*.sql.gz` →
`disable --now ereport-queue`, cron scheduler di-rename `.disabled-docker` → `WORKER_SCALE=1` (queue,
scheduler, reverb 8180) → rewrite vhost `location /` jadi `proxy_pass 127.0.0.1:8100`, reverb.conf
8080→8180, `disable --now ereport-reverb` → bukti request publik masuk log be-web → tunnel
putrainterior ke 3100, `pm2 stop` FE. Jalankan saat tidak ada import Excel berjalan (stop queue native
menunggu maksimal 90 detik).

`rollback.sh`: FE native + tunnel 3000 → reverb native + restore nginx → `WORKER_SCALE=0` lalu
queue native + cron → verifikasi. DB tidak pernah disentuh.

Setelah cutover, **jangan simpan URL rewrite situs api-ereport dari panel aaPanel**: panel akan menimpa
`proxy_pass` di file rewrite.
