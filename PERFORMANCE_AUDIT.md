# Performance Audit — E-REPORT (Backend API + Frontend)

Date: 2026-05-30
Stack: Laravel 11 (PHP 8.2+) API · Next.js 15 / React 19 / TanStack Query frontend

The codebase is already in good shape: server-side pagination, eager loading
(`withProductRelations`), compound DB indexes, dashboard/notification caching,
and per-query React Query keys. The audit focused on data-retrieval latency.
Findings below are grouped by **Applied** (done in this pass) and
**Recommended** (deployment / infra changes that should not be silently forced).

---

## ✅ Applied in this pass

### 1. Removed a 945 KB config file from the per-request bootstrap path  *(highest impact)*
**Problem:** `config/wilayah_kecamatan.php` (~945 KB, 7 215 districts) and
`config/wilayah_kota.php` (~23 KB) lived in `config/`. Laravel parses **every**
file in `config/` on **every request** during bootstrap — so this ~970 KB of
PHP arrays was being built on requests that have nothing to do with geography
(dashboard, consultation lists, and the notification poll that fires every
10–15 s per logged-in user). `bootstrap/cache/config.php` was absent, so config
caching was not masking the cost.

**Fix:**
- Moved both datasets to `resources/data/` so they are no longer auto-loaded.
- Added `App\Support\Wilayah` — a lazy loader that `require`s a dataset only
  when actually needed and memoizes it for the rest of the request.
- Updated all 4 readers (`WilayahController`, `ConsultationRequest`,
  `DebugController`, `LeadsExcelExporter`) and the `wilayah:sync` writer command.
- Verified at runtime: 514 cities / 7 215 districts / 34 provinces load
  correctly; `config('wilayah_kota')` now returns `null` (no longer in the
  bootstrap path).

**Effect:** every non-geography API request now skips building ~970 KB of arrays.

### 2. Server-side memoization of the Wilayah filter results
`WilayahController@cities` / `@districts` previously re-ran an `array_filter`
(districts: a regex-based fuzzy match over ~7 215 rows) on **every** call.
Results are now `Cache::rememberForever`-cached, keyed by a dataset-mtime
version token (auto-invalidates when `wilayah:sync` rewrites the files) plus the
filter params. Cold call computes once; every subsequent call is a cache read.
Verified output identical (Aceh → 23 cities; Kota Banda Aceh → 9 districts).

### 3. Cached the hot master-data dropdown endpoints
`master-data/needs-categories` and `master-data/status-categories` are hit by
every consultation form / list. Wrapped both in `Cache::remember` (6 h) with
explicit `Cache::forget` invalidation in all six category/status
create/update/delete handlers — so DB is only touched on a cold cache or an edit.

### 4. Frontend: stopped polling the heavy notification endpoint when it isn't visible
`NotificationCenter` polled `/notifications/summary` (5 notes + 5 reminders with
joins + formatting) every 10 s **whether or not the dropdown was open**, while
the lightweight `/notifications` count endpoint was unused.
- Badge count now uses the cheap `useNotificationCount()` (2 integers, 15 s poll).
- The heavy `useNotificationSummary(open)` only fetches/polls while the popover
  is open, and refreshes on open.

This removes a continuous heavy query for every logged-in user who isn't
actively looking at notifications. (Type-checked: `tsc --noEmit` clean.)

---

## 🔧 Recommended (deployment / infra — not changed here)

These are the largest remaining wins and belong in deploy config, not code.

1. **Enable the Laravel optimization caches in production** (run on deploy):
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan event:cache
   php artisan view:cache
   ```
   `config:cache` collapses all config into one OPcache-friendly file and skips
   per-request `.env` parsing. Re-run after every deploy. (Requires that
   `env()` is only called inside `config/` files — verify before enabling.)

2. **Ensure OPcache is on** in production `php.ini`:
   `opcache.enable=1`, `opcache.validate_timestamps=0` (deploy-time cache clear),
   `opcache.memory_consumption=256`, `opcache.max_accelerated_files=20000`.
   Single biggest PHP throughput lever.

3. **Use a faster cache store than `file`** if available.
   `predis/predis` is already a dependency. If the host offers Redis, set
   `CACHE_STORE=redis` + `SESSION_DRIVER=redis`. On shared hosting without Redis,
   `database` cache (`php artisan cache:table && migrate`) is still generally
   better than `file` for concurrent access. Dashboard/notification/master-data
   caching all benefit directly.

4. **Keep the queue asynchronous.** Local `.env` uses `QUEUE_CONNECTION=sync`,
   which makes CSV import run inline and block the request. Production already
   uses `database`; ensure a worker runs (`php artisan queue:work` /
   Supervisor / scheduled restart).

5. **`APP_DEBUG=false` in production** (the production example is already
   correct). Debug mode adds overhead and leaks internals.

---

## 👍 Verified already-good (no action needed)
- Server-side pagination with `per_page` capped at 100.
- Eager loading via `Consultation::withProductRelations()` — no N+1 on the list.
- Comprehensive compound indexes (`idx_consult_acct_status_del`,
  `idx_consult_acct_updated_del`, `idx_consult_acct_date_del`, notes/reminders
  lookup indexes, etc.).
- Dashboard cached (super-admin 15 m, admin 5 m) with targeted flush on writes.
- Notification summary/counts cached 2 m per user with invalidation on read/write.
- React Query: sensible global `staleTime` (5 m), `placeholderData` for smooth
  pagination, 1-hour client cache on master data, 24-hour on geography.
