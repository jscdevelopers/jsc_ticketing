# JSC Ticketing

Local Angular + PHP/Nite + PostgreSQL ticket and workflow application. The supplied blueprint is preserved in `JSC_Ticketing_Blueprint/`; the executable application is in `frontend/` and `backend/`.

## Run locally

```powershell
./scripts/run-environment.ps1 -Environment development -Build
./scripts/setup-jsc-samples.ps1 -Environment development
./scripts/setup-it-workflow.ps1 -Environment development
```

Open **http://127.0.0.1:8180**. The API is also available on loopback port 8186. JSC sample setup installs representative Zimbabwe court offices, departments, reporting positions and operational requests. See [structure, sources and account scopes](docs/jsc_samples.md).

| Username | Purpose |
| --- | --- |
| registry.harare | Submit requests from Harare Magistrates Court |
| ict.harare | Claim and complete ICT work |
| station.manager | Station oversight, sharing/privacy, reassignment, SLA controls, withdrawal/reopen and CSV |
| workflow.designer | Design department workflows |
| workflow.publisher | Independently approve department workflows |
| it.officer.mag_harare | Automatically assigned station IT work |
| it.principal.harare | Provincial IT escalation and lower-stage intervention |
| it.helpdesk | Help-desk escalation and specialist dispatch |
| it.head / it.deputy | Department intervention and analytics |
| secretary / deputy.secretary | Explicit national oversight, comments and analytics |

Initial passwords are generated privately in `secrets/development/jsc_passwords.json` and must be changed at first sign-in. Setup can be repeated safely and never resets passwords; the five legacy sample logins are renamed while retaining their existing passwords. The initial `administrator` account, if already provisioned, remains configuration-only; its password is unchanged. No default credential is committed.

IT setup adds 38 role accounts and nine worked requests across the complete support route. Its initial passwords are private in `secrets/development/it_passwords.json` or `secrets/offline/it_passwords.json`. See the [IT workflow operating guide](docs/it_workflow.md) for all specialist accounts and visual configuration.

For a new installation without an administrator, provision one through `backend/bin/setup.php USERNAME` with the password on standard input; see the [operator guide](docs/local_operations.md).

```powershell
# Uses cached images; no image build or pull during offline startup.
./scripts/run-environment.ps1 -Environment offline
./scripts/setup-jsc-samples.ps1 -Environment offline
./scripts/setup-it-workflow.ps1 -Environment offline
```

Offline opens at **http://127.0.0.1:8187** with a separate database, private files and credentials. Its backend network blocks external routing. Both local environments deliberately use a labelled dummy file check, which is **not antivirus**. Real nAuth, email, approved staff import and hosting are outside the current local-only scope; nonsecret placeholders are recorded in `config/integrations.example.json`. The local webhook node records an audit event without external delivery. Production has a tracked configuration template, not a running deployment.

## Working features

- Password sign-in, forced temporary-password change, session revocation, login throttling and operator tokens.
- Effective postings, explicit role/scope grants, separate sensitive clearance and audited organization administration.
- Versioned intake forms, saved drafts, private quarantined attachments, comments and requester information replies.
- Visual flowchart editing with multi-person stage audiences, automatic station assignment, provincial escalation, category-based specialist dispatch and audited senior takeover.
- Eighteen scoped leadership analytics charts, location/status/priority filters, ticket drill-downs and directed comment notifications, including Secretary commentary after closure.
- Guided workflow drafts, validation/simulation, independent publication, immutable versions, conditional routing, task pools, parallel ALL/ANY/quorum joins and timers.
- Atomic claims, release/reassignment, candidate refresh, SLA pause/resume, rejection, withdrawal and historical reopen runs.
- Summary-only CC/watchers, privacy classification, scoped dashboard counts, manager queues/SLA monitor, saved reports and bounded CSV/PDF exports and an idempotent background worker.
- Calendar/default CC policy editor, bounded send-back, publisher-only review, version-change summaries and scope dashboard drill-downs.
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
