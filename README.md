# JSC Ticketing

Executable backend foundation and ticket intake for the supplied Angular + PHP/Nite + PostgreSQL design. The full application is under development; the UI in `JSC_Ticketing_Blueprint/designs/` is a demonstration with fictional data.

- `backend/`: runnable PHP 8.2+ API, PostgreSQL migrations and integration tests.
- `deploy/compose.yml`: isolated local development services, not a production release.
- [Verified discovery and architecture decisions](docs/implementation_findings.md).
- [Foundation runbook and known gaps](docs/foundation_runbook.md).
- [Executed validation and limitations](docs/validation.md).
- [Configure and use ticket intake](docs/intake.md).
- `JSC_Ticketing_Blueprint/`: preserved requirements, workflow specs and design assets.

## Run locally

In PowerShell, choose a dedicated development database password without committing it:

```powershell
$env:POSTGRES_PASSWORD = Read-Host 'Development database password'
docker compose -f deploy/compose.yml build
docker compose -f deploy/compose.yml up -d postgres
docker compose -f deploy/compose.yml --profile tools run --rm migrate
docker compose -f deploy/compose.yml up -d api
Invoke-RestMethod http://127.0.0.1:8186/api/v1/health/ready
```

The database has no published host port. API access is loopback-only. Stop with `docker compose -f deploy/compose.yml down`; the named database volume is retained. Never use this development server for public traffic.

## Validate

```powershell
./scripts/test-backend.ps1
python -m unittest discover -s JSC_Ticketing_Blueprint/tests -v
./scripts/smoke-compose.ps1
```

The backend script creates its own disposable PostgreSQL container, random credential and loopback port; it removes only that test container. Tests refuse databases not named `ticketing_test` and create/drop only their own randomly named schema. CI runs the same integration checks plus PHP syntax, Composer validation and a Docker build.

## API implemented in this increment

| Route | Behavior |
| --- | --- |
| `GET /api/v1/health/live` | Process liveness; no database required |
| `GET /api/v1/health/ready` | Database, identity and intake-schema readiness |
| `GET /api/v1/me` | Current bearer-authenticated identity |
| `GET /api/v1/tickets?q=&status=&limit=10&cursor=` | Server-filtered authorized tickets, at most ten rows per page |
| `GET /api/v1/tickets/{id}` | Authorized detail; unavailable and unauthorized IDs return the same 404 |
| `GET /api/v1/tickets/{id}/timeline?after=` | Authorized internal events, at most ten per page |
| `GET /api/v1/me/assignments?q=&after=` | Own effective postings for submission, at most ten per page |
| `GET /api/v1/ticket-categories?q=&after=` | Configured triage categories and versioned forms, at most ten per page |
| `POST /api/v1/tickets` | Idempotent submission with origin snapshot, department routing, event and outbox |

Tokens are console-provisioned, expire within 24 hours, and are stored only as SHA-256 hashes. Login/SSO is not implemented yet. An authenticated user without permissions and grants sees no tickets. Secretariat location and CC do not grant access. National read access does not imply clearance for confidential tickets. Submission requires separate create and self-read permissions; it grants no approval or claim rights.
