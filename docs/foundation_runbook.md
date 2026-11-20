# Foundation operations and next increments

## Native development

Use PHP 8.2+ with `pdo_pgsql`, a dedicated PostgreSQL 16+ database and environment variables. `.env.example` documents names; native PHP deliberately does not automatically load files. `DB_PASSWORD_FILE` takes precedence over `DB_PASSWORD`. Run from `backend/`:

```powershell
php bin/console.php migrate
php -S 127.0.0.1:8186 -t public public/index.php
```

Composer installation is optional for local bootstrap (no third-party runtime dependencies); `composer install` generates the standard PSR-4 autoloader. The development API has no CORS permission or cookie auth. Use an Authorization bearer header over trusted local access; production requires TLS and an approved SSO adapter.

If Docker reports exhausted address pools, add `-f deploy/compose.network.yml` after `-f deploy/compose.yml` and set `TICKETING_NETWORK_SUBNET` to an unused local subnet. Do not prune other applications' networks. The smoke script uses `10.254.240.0/28`, verified unused on this workstation; pass `-NetworkSubnet` to choose another range. Docker rejects overlapping ranges before starting services.

## Directory import

The trusted console imports a JSON object containing any of these sections. Each supplied section is a JSON list, at most 10000 rows, with every listed field required, including nullable fields. Whole files are capped at 10 MiB.

| Section | Exact row fields |
| --- | --- |
| `provinces` | `id, code, name, active` |
| `stations` | `id, code, name, province_id, is_secretariat_station, active` |
| `departments` | `id, code, name, active` |
| `positions` | `id, code, title, active` |
| `app_users` | `id, external_subject, display_name, email, active` |
| `staff_assignments` | `id, user_id, position_id, department_id, station_id, begins_at, ends_at, is_acting` |

IDs must be stable UUIDs from an approved reconciliation. An existing user UUID cannot be rebound to another external subject. Use real JSON booleans, UTC ISO timestamps, and null for absent email/end date/province. Dependencies may already exist or be included in the same file. A failed row rolls back all changes; missing records are retained. Import is a trusted operator action that can change active states and assignments, so restrict console/database access. No role, scope, password or credential is accepted in this file. An audit event stores the source hash, not its contents.

```powershell
php bin/console.php directory:import C:/private/directory.json --dry-run
php bin/console.php directory:import C:/private/directory.json
```

For Docker, copy an approved input into a private temporary location inside a one-off container or add an explicit read-only bind mount; never bake staff exports into the application image.

## Access and credentials

These commands require trusted console access and a database role allowed to provision identities/grants. The foundation records `actor_system=console` and reason; attribution to a named administrative session and dual-control national approval remain future work. No user is automatically provisioned by migrations.

```powershell
php bin/console.php scope:grant-read USER_UUID station STATION_UUID 'Approved local read access'
php bin/console.php scope:grant-read USER_UUID self - 'Approved own requests'
php bin/console.php scope:grant-read USER_UUID national - 'Approved national oversight'
php bin/console.php token:issue USER_UUID 8
php bin/console.php revoke grant GRANT_UUID 'Assignment ended'
php bin/console.php revoke token TOKEN_UUID 'Credential rotation'
```

Granting read assigns the `ticket_reader` role and creates an explicit `ticket.read` scope. National rights must be explicitly approved under JSC policy. Scope target existence is validated. Revocation preserves records and is idempotent. A token is printed once; protect command output and never paste tokens into issues/logs. Token IDs can be obtained by a trusted operator from `api_tokens` without reading the stored hash. Rotate by issuing a new token, updating the client, then revoking the old ID. Deactivating a user invalidates all their tokens on the next request.

Sensitive tickets additionally require `ticket.read_sensitive` in a current role and a matching current scope grant; the console intentionally has no shortcut granting sensitive clearance. Business approval and a named-admin workflow must be implemented before operational use.

## Migration and recovery

- Migrations run in sorted order with a session advisory lock. Each migration's DDL and ledger entry commit together. Checksums normalize CRLF to LF for Windows/Linux portability. Re-running skips matching checksums; changing an applied file fails closed. Never edit an applied migration; add a new corrective migration.
- The supplied foundation is intended for a fresh dedicated database. A database already initialized directly from the blueprint needs an explicitly reviewed adoption migration; do not rerun against existing tables.
- Stop the API during the first install. Future migrations must be compatible with current/next code. Keep the database volume on application rollback; do not automatically reverse schema or delete data.
- Before any real rollout: perform PostgreSQL `pg_dump` into a protected backup location, encrypt it using approved key management, and restore into a separate database. Verify row counts, schema ledger and representative authorized reads before declaring recoverability. This repository has not yet verified backup/restore or production RPO/RTO.
- Development Docker uses the PostgreSQL owner for convenience; production needs separate migration/provisioning and restricted runtime database roles. Database owners can bypass trigger protections; append-only triggers alone are not a tamper-proof external audit system.

## Response and read contract

Responses use the observed Nite envelope: `status`, numeric HTTP `code`, `message`, `data`, `errors`, `meta`. Pagination uses `meta.next_cursor`, opaque base64 of the last UTC creation timestamp and UUID. Search/filter changes should start a fresh cursor; cursors never grant access. Every page reevaluates current roles/grants and filters before applying the limit. Unknown query keys and invalid filters return 422. Omitted limit is 10; allowed range 1..10. Requests carry a generated `X-Request-ID`; error logs omit tokens, query parameters, SQL and personal data.

Read scopes are independent alternatives: own requester, origin station/province, responsible department, explicit ticket, or national. They do not derive authority from current postings. An expired/future role or expired/future/revoked grant contributes no authority. CC-only and candidate-only visibility have not been enabled. Unauthorized and absent ticket IDs produce identical 404s.

## Remaining work

1. Complete P0/P1: approved SSO/session adapter, named-admin organization/grant workflows, effective assignment/candidate policies and least-privilege database roles.
2. P2: intake now supports versioned department triage, category validation, origin snapshots, idempotency, rate limits and transactional event/outbox; see [intake operations](intake.md). Private attachments, CC and sensitive intake remain open.
3. P3/P4: production graph validator/runner, graph publication, separate parallel tasks, atomic claims/releases with 100-contender test. The simple triage configuration has immutable versions; it does not execute arbitrary graphs. The blueprint Python validator remains a limited reference, and the PHP claim example is not exposed.
4. Angular: runnable workspace based on the observed stack, authenticated pages, dense accessible forms/queues, focusable progress details, responsive layouts and E2E coverage. Current prototype remains demo-only.
5. P5/P6: scoped dashboards, SLA/calendar, notifications, reports/export, operational audit access, backup/restore and staging security/performance tests.

No production deployment, hardware test, email delivery or live nAuth integration has been performed. Hosting and per-app Niaxa branding/protection choices remain unconfirmed.
