# JSC Ticketing

Local Angular + PHP/Nite + PostgreSQL ticket and workflow application. The supplied blueprint is preserved in `JSC_Ticketing_Blueprint/`; the executable application is in `frontend/` and `backend/`.

## Run locally

```powershell
./scripts/run-environment.ps1 -Environment development -Build
./scripts/setup-local-demo.ps1 -Environment development
```

Open **http://127.0.0.1:8180**. The API is also available on loopback port 8186. Demo setup adds fictional organization records and five scoped accounts without changing existing users:

| Username | Purpose |
| --- | --- |
| requester.demo | Submit requests from Demo Station |
| agent.demo | Claim and complete Demo ICT work |
| manager.demo | Station oversight, sharing/privacy, reassignment, SLA controls, withdrawal/reopen and CSV |
| designer.demo | Design department workflows |
| publisher.demo | Independently approve department workflows |

Initial passwords are generated privately in `secrets/development/demo_passwords.json` and must be changed at first sign-in. Setup can be repeated safely and never resets passwords. The initial `administrator` account, if already provisioned, remains configuration-only; its password is unchanged. No default credential is committed.

For a new installation without an administrator, provision one through `backend/bin/setup.php USERNAME` with the password on standard input; see the [operator guide](docs/local_operations.md).

```powershell
# Uses cached images; no image build or pull during offline startup.
./scripts/run-environment.ps1 -Environment offline
./scripts/setup-local-demo.ps1 -Environment offline
```

Offline opens at **http://127.0.0.1:8187** with a separate database, private files and credentials. Its backend network blocks external routing. Both local environments deliberately use a labelled dummy file check, which is **not antivirus**. Real nAuth, email, approved staff import and hosting are outside the current local-only scope. Production has a tracked configuration template, not a running deployment.

## Working features

- Password sign-in, forced temporary-password change, session revocation, login throttling and operator tokens.
- Effective postings, explicit role/scope grants, separate sensitive clearance and audited organization administration.
- Versioned intake forms, saved drafts, private quarantined attachments, comments and requester information replies.
- Guided workflow drafts, validation/simulation, independent publication, immutable versions, conditional routing, task pools, parallel ALL/ANY/quorum joins and timers.
- Atomic claims, release/reassignment, candidate refresh, SLA pause/resume, rejection, withdrawal and historical reopen runs.
- Summary-only CC/watchers, privacy classification, scoped dashboard counts, manager queues/SLA monitor, bounded CSV exports and an idempotent background worker.
- Calendar/default CC policy editor, bounded send-back, publisher-only review and version-change summaries.
- Consistent local database/private-file backup and isolated restore verification.
- Responsive operations UI with keyboard labels, Bootstrap icons, confirmations, toasts and focusable workflow stages.

See [local operations and limitations](docs/local_operations.md), [environment profiles](docs/environments.md), [architecture findings](docs/implementation_findings.md), [intake](docs/intake.md) and [executed validation](docs/validation.md). The full blueprint acceptance gate is not yet complete; outstanding items are recorded explicitly.

## Validate

```powershell
./scripts/test-backend.ps1
./scripts/test-e2e.ps1
./scripts/smoke-compose.ps1
python -m unittest discover -s JSC_Ticketing_Blueprint/tests -v
```

Backend tests use a disposable `ticketing_test` database and random schema. Browser tests provision their own API, PostgreSQL, worker, private storage and desktop/mobile Chrome sessions. They do not change the local development database. Failure traces and credentials remain ignored. `frontend/` also supports `npm ci`, `npm run build` and `npm audit`.
