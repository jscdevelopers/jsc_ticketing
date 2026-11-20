# Verified integration findings

Inspected read-only on 20 November 2026. No reference application, real environment file, production data or shared server configuration was changed. This repository began on `main`, without commits; the supplied blueprint was untracked. No branch was switched.

## Actual sources

| Source | Observed state |
| --- | --- |
| `T:/source/work/jsc_creditors` | `main`, HEAD `56870ee6899d8a0c1146e157a1c624fa388ba745`; existing working-tree changes were present and left untouched |
| `T:/source/work/hrms/jsc_hr_backend` | `main`, HEAD `e843d2a5b46aace72aeca51aeebe5330de1ff82d`; tracked working tree clean at inspection |
| `T:/source/work/hrms/jsc_hr_frontend` | Angular package inspected, branch `main` |

The working-tree source, rather than HEAD alone, supplied these findings. The original blueprint remains a reference; current executable code is in root `backend/`.

## Nite conventions actually found

- `backend/composer.json`: proprietary application, PHP `^8.2`, PSR-4 `App\\` to `src/`; no separately required Nite package/version. `nite` / `nite.php` boot a local console application. The installed application must not be represented as a versioned public Nite distribution.
- `bootstrap/autoload.php` loads Composer; `public/index.php` loads environment configuration, instantiates `App\Http\Router('/api')`, registers module routes and dispatches `Request::fromGlobals()`.
- `src/Modules/Identity/routes.php` returns a registration closure taking `Router`, a security wrapper and a permissions wrapper. Router supports `get/post/put/patch/delete`, `{id}` parameters and callable middleware via `Route::middleware(callable)`. Controller signatures are `method(Request): Response`; array handlers construct controllers without a DI container. No external DI system was established by this inspection.
- `Request` exposes `query`, `body`, `params`, `attributes`, `user`, and case-insensitive `header()`. `Response::json()` uses a six-field `status/code/message/data/errors/meta` envelope through `ApiResponse`.
- `Config/Database.php` uses native PostgreSQL PDO, exceptions, prepared statements and UTC sessions. The inspected creditors database assets are SQL snapshots under `database/postgres/`; a reusable ordered/checksummed migration runner was not found in the inspected files.
- Authentication middleware calls `CreditorsAuthService::actorFromBearer(BrowserSession::authorization($request))`; it is coupled to imported creditors users and its own permission records. `BrowserSession` supports the HttpOnly `creditors_session` cookie with SameSite Strict and production Secure. The nAuth client is a compatibility wrapper around `nauth-lib/compat.php`. Neither source establishes an approved ticketing SSO audience/session contract.
- The front controller uses explicit CORS origins and private/no-store responses, suppresses display errors and logs failures. Ticketing currently accepts bearer headers only, has no cookie authentication and exposes no HTTP mutations; a browser session/CSRF flow remains future work.
- Creditors Composer tests invoke individual PHP scripts for security, hardening, authentication, reports, lists, modules and notifications. Its lock includes PDF libraries; ticketing does not need or inherit them. Reference-application tests were not run or changed.

## HRMS deployment actually found

- The backend is Python/Django, not PHP/Nite. Its development Dockerfile uses `python:3.13.17-slim`, native MySQL build dependencies and `manage.py runserver`.
- Production Compose uses environment files outside the source tree, Gunicorn, migration/security startup checks, healthchecks, `cap_drop`, read-only runtime, tmpfs and `no-new-privileges`. Infrastructure includes MySQL 8.4, Redis, ClamAV and phpMyAdmin; these are not compatible defaults for this PostgreSQL service.
- Production service configuration uses persistent host paths and a root bootstrap service for ownership. `deployment/backup.sh` checks paths under `docker-data`, uses `mysqldump`, encrypts DB/media with `age` and writes checksums. Its database backup command must not be reused for PostgreSQL.
- HRMS and creditors have deployment workflow files. No remote deployment workflow was executed. Rootless-engine operation, SELinux labeling, effective production proxy routes, server permissions and actual backup restorability were not verified.

## Versions observed, rather than assumed

- Workstation: PHP 8.2.35 with `PDO`, `pdo_pgsql`, `pgsql`, OpenSSL and Sodium; Composer 2.8.12; Node 22.23.4; npm 11.21.1; Python 3.13.17.
- Docker client/server 29.2.0; Docker Desktop 4.60.1, Linux engine available.
- Both inspected Angular manifests use framework 20.3.34 and CLI/build 20.3.40, TypeScript `~5.8.2`. These are source observations; no ticketing Angular workspace is implemented in this increment.
- Ticketing integration tests use PostgreSQL `16.15-alpine`. Ticketing has no external Composer runtime dependencies.

## Implemented integration decisions and deliberate deviations

1. Build an isolated application/database. Copy the verified, dependency-free Nite `Router` and `Request` sources; retain their method contracts and add strict types/body bounds. Remove implicit PATCH-to-PUT routing. Use a smaller compatible response envelope without the creditors debug path. A small ticketing bootstrap avoids requiring the reference application's vendor directory or secret files.
2. Provide short-lived, hashed, revocable console-provisioned bearer tokens as a foundation adapter. This is a development/operator credential path, not completed nAuth/SSO. No imported password, shared cookie, implicit admin, auto-national privilege or frontend-supplied identity is accepted.
3. Require both a current role permission and a current explicit scope grant on every ticket query. List/detail share one SQL authorization predicate. Sensitive reads additionally require their own permission and scope. Scope comparisons use ticket origin snapshots; current staff postings never rewrite historical visibility. Assignment eligibility for future tasks remains unimplemented.
4. Preserve `001_foundation.sql` byte-for-byte from the blueprint; execute it with an ordered advisory-locked migration runner and atomic checksum ledger. Identity credentials/security audit use additive migration `002_identity.sql`. Existing approved databases must be assessed separately; this runner does not adopt an existing untracked schema.
5. Import only explicitly supplied organization/staff records by UUID, inside one transaction. No real organization hierarchy is invented. Existing rows absent from an import remain intact; explicit active flags and assignment effective dates control lifecycle.
6. Use a private PostgreSQL service and non-root/read-only PHP development container on loopback API port 8186 (configurable). Use a dedicated named volume per Compose project. Do not inherit shared networks, proxy routes, HRMS DB volumes, MySQL configuration or Redis without need.
7. The executable Compose file is development-only. The PHP base digest is pinned; production image/package approval, a production PHP server, least-privilege runtime DB account, encrypted backup/restore, proxy/TLS, production secret mounts, SSO and approved rollout remain release gates. No hosting destination has been established, so Niaxa credit/protection choices have not been inferred or implemented.

## Current exit boundary

This is the initial backend foundation and read-authorization slice of P0/P1, not completion of every P0/P1 item. Angular, production SSO, ticket intake, candidate resolution/claims, workflow execution/publication, attachments, SLA, notifications and production deployment remain open. The blueprint claim example is not registered as an API endpoint. Its 100-contender acceptance test remains unverified.
