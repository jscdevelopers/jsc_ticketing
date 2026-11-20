# Foundation validation — 20 November 2026

Executed locally on the supplied Windows workstation:

| Check | Result |
| --- | --- |
| PHP syntax across executable backend files | Passed |
| `composer validate --strict --working-dir=backend` | Passed |
| `./scripts/test-backend.ps1` | 61 PostgreSQL integration and real HTTP checks passed |
| `python -m unittest discover -s JSC_Ticketing_Blueprint/tests -v` | 12 existing blueprint reference tests passed |
| `./scripts/smoke-compose.ps1` | Image build, first migration, repeated migration, database readiness, HTTP readiness and UID 10001 passed |
| `git diff --cached --check` | Passed before commit |

The integration suite verifies fresh/no-op/failed/checksum-mismatched migrations, directory dry-run/reimport/transaction rollback, immutable identity binding, hashed/expired/revoked credentials, audit-failure rollback, grant idempotency/revocation, role validity, independent scope unions, Secretariat vs explicit national access, province and self scopes, historical origin access, separate sensitive clearance, authorization before pagination/search, malformed queries/cursors, immutable history and the real PHP HTTP entrypoint.

The first Compose startup hit this workstation's exhausted default Docker address pools. The optional network override was then tested successfully with an unused `10.254.240.0/28` subnet. Existing networks were retained. The successful smoke test removed its own containers, network and database volume afterward; development images remain available in Docker's cache.

The GitHub Actions workflow has been authored but has not been run on GitHub. No Angular build/browser E2E, real SSO, concurrent task claims, backup/restore, staging deployment, live notification delivery or production integration is claimed. See the runbook's remaining-work list.
