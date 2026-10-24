# Read-only Integration API

Machine-to-machine feed for external systems (e.g. NACIRA Sales AI CRM). Integration clients are **not users**: their tokens never satisfy `auth:sanctum`, so no existing write route is reachable with them.

## Manage clients
```bash
php artisan integration:client create "Nacira CRM" --accounts=1,9 [--expires=2027-12-31]   # token printed once
php artisan integration:client list
php artisan integration:client revoke 3
```
Tokens are stored as SHA-256 hashes (`integration_clients.token_hash`). Revoke and re-issue if a token leaks.

## Endpoints (GET only, `Authorization: Bearer erpi_…`, throttle 60/min)
| Endpoint | Description |
|---|---|
| `/api/v1/integration/accounts` | Accounts on the client's allowlist |
| `/api/v1/integration/consultations` | Paginated (`per_page` ≤ 100), filters: `account`, `updated_since` (ISO 8601), `include_deleted=1`, `search`. Ordered by `updated_at, id` ascending for cursor-style sync |
| `/api/v1/integration/consultations/{consultation_id}` | Single consultation (404 outside allowlist) |
| `/api/v1/integration/master-data/statuses` | Status categories |
| `/api/v1/integration/master-data/needs-categories` | Needs categories |

Any non-GET method returns **405**. Accounts outside the allowlist are never returned (an explicit `account=` outside the list yields an empty page). Responses use an explicit field list (no creator/audit columns).

Tests: `php artisan test --filter=IntegrationApiTest`.
