# Local operations

## Identity and administration

Local development/offline use password authentication. Passwords are bcrypt hashes; bearer sessions remain in browser memory, expire within 24 hours and can be revoked. A temporary-password session can only change the password or sign out. Changing/resetting a password invalidates sessions and pending MFA challenges. Login is throttled by account and network address.

For initial administrator setup, pass a private password to `php bin/setup.php administrator` on standard input inside the selected environment. The command creates a configuration-only administrator once and refuses to modify an installation with existing credentials. The existing workstation administrator's initial secret is in `secrets/development/admin_password.txt`; do not commit or print it.

Administration maintains provinces, stations, departments, positions, users, effective postings, categories, roles and explicit scope grants. Record changes require a reason and current version. Archive records rather than delete them; revoke grants rather than edit their scope. Roles authorize actions; matching explicit scopes authorize records. Secretariat station never grants national visibility. Sensitive records require additional clearance. Configuration administrators receive no automatic national ticket read access.

The console supports directory import/dry-run, submit/read grants, token issue/revoke and temporary-password provisioning. Run `php bin/console.php help`. Existing identity subjects cannot be rebound. Production requires separate reviewed database/runtime roles; local Compose currently uses a database owner for convenience.

## Requests and work

1. Sign in as a requester and change the initial password.
2. Select an effective posting and category, complete its fields and submit. The origin and immutable workflow version are captured. Closing an intake/admin/workflow modal preserves a browser draft scoped to the user. Clear sensitive drafts on shared computers through explicit reset/browser storage cleanup.
3. Eligible staff see offers under My actions. One eligible user claims a task; competing claims return conflict. Complete work or record approval/rejection with a reason. Release returns responsibility to the pool.
4. Managers with both record read authority and the relevant action scope can refresh candidates, reassign, pause/resume SLAs and withdraw/reopen. A reopened ticket starts a new run using its original workflow; prior executions and audit events remain.
5. Comments have authorized-viewer, participant-only or restricted visibility. Only the requester can answer a waiting information request. Comments are plain text and displayed without HTML interpretation. CC/watchers receive a separate summary containing reference, category, department, status, priority and creation time. They receive no subject, description, fields, requester identity, discussion, files or action rights through sharing. Department membership is evaluated from current active postings; revoke/expiry/privacy changes remove summary and notification visibility immediately. Sharing and privacy edits require separate explicit scopes; privacy changes additionally require sensitive clearance.

The ticket list currently contains all tickets authorized for the signed-in user, even though the navigation label is My tickets. Dashboard personal view limits counts to the requester's own tickets; other counts remain ACL-filtered. The API also supports station/province/department views. Work queues show active tasks only within both full ticket-read and task-management scopes; SLA monitor shows the overdue subset. CSV and PDF require a separate export scope and are limited to 1000 authorized rows. CSV escapes spreadsheet formulas; PDF emits paginated text with escaped literal strings. PDF uses a standard Western font and transliterates unsupported characters. Reports save private filter definitions, not data copies; each run/export reevaluates current access. Saved definitions support edit, draft recovery and reversible archive. Filters include search, state, priority, origin station/province, owner department and my requests. Scope selectors reveal only locations/departments containing currently readable tickets, and dashboard drill-downs preserve their scope as removable filter chips.

## Workflow publication

The seven-step wizard configures category/form, connections, stages, policies, simulation and review. Search directory choices and explicitly load more. Save a draft, simulate it, then publish with an approval reason. A different authorized publisher is required by default and always for national task nodes. New submissions use the new version; existing work retains its recorded version. Opening a published definition creates a new draft rather than mutating it.

The graph validator rejects cycles, unknown nodes/operators, missing fallback routes, unreachable stages, invalid parallel joins and empty eligible task targets. ALL/ANY/quorum branches have independent execution/task records. Separation-of-duties checks prevent one actor completing independent approval branches. A rejected approval terminates with rejected resolution unless the definition explicitly routes rejection. Approved linear stages can send work back to a preceding work/information stage at most 1-10 times per run; prior executions remain immutable history, and parallel stages cannot be rewound. Timers and business SLAs use UTC storage and a timezone-aware working calendar; default Africa/Harare weekdays 08:00-16:30. The policies page edits timezone, workdays, opening/closing times, holidays and default CC departments. Review shows changes from the active version. A publisher-only account can inspect/simulate/approve a stored draft without receiving design authority.

## Private files and background work

Upload PDF, PNG, JPEG, UTF-8 TXT or CSV up to 5 MiB. Type/content checks occur before storage. Files have random private keys, never public URLs. New files remain quarantined; each download rechecks current ticket/sensitive access, scan state and SHA-256 integrity. Refresh files to see the latest check result. Unsupported formats and suspicious files remain unavailable.

The local profile uses `MALWARE_SCANNER_MODE=demo` plus `ALLOW_DEMO_INTEGRATIONS=true`. This checks the standard test marker only and visibly labels the result as a dummy check. It is not malware protection. Production refuses dummy verdicts and requires ClamAV; the INSTREAM adapter fails closed if unavailable. Live antivirus has not been exercised.

The worker processes timers, one-time SLA breaches, private file checks and an in-app notification outbox. Database transactions commit business changes, history and outbox together. In-app notification delivery is deduplicated and rechecks access. Failed notifications retry and ultimately enter dead-letter state. Worker health uses its database connection and a recent heartbeat. SMTP delivery is a placeholder; no external email is sent.

## Recovery and remaining acceptance work

Migrations have a checksum ledger and advisory lock. Never edit an applied migration; add a forward correction. Retain database/files volumes on application rollback. Back up PostgreSQL, the private file volume and the corresponding private credentials as one consistent recovery set. Do not delete volumes to fix startup or replace a password file without rotating the stored database credential.

Use `./scripts/backup-local.ps1 -Environment development` to pause local writes briefly and capture a consistent PostgreSQL custom dump, private-file archive and content/count/sequence manifest into ignored `backups/`. Services restart afterward. Protect this directory as sensitive data; it is not encrypted by this local helper. Retain original credentials separately, and retain the exact API image recorded by immutable image ID in backup metadata.

Run `./scripts/test-recovery.ps1 -BackupPath backups/<backup-directory>` to verify archive checksums, restore into a separately named disposable database/storage/network, compare every table digest/count, sequence and private-file hash, then submit/claim/complete a ticket in the restored copy. The operational probe requires the fictional local demo workflow and makes no changes to the source database. No command restores over a running source database. The current workstation exercise restored the demo dataset; it does not establish production RPO/RTO or target-scale recovery timing.

External integration placeholders are documented in `config/integrations.example.json`. The workflow webhook option accepts only the fixed `local-audit` dummy identifier, performs no network I/O and records `external_delivery=false`; arbitrary URLs/payload configuration are rejected. Simulation/publication/execution require explicitly enabled development/offline/test mode; production rejects this dummy. Real webhook delivery remains deferred with the other external systems.

Outstanding blueprint acceptance work: richer policy/version diff presentation, live push updates and measured load/a11y audit. Real identity enrollment/MFA, email/antivirus infrastructure, production HTTP/TLS/least-privilege roles and business sign-off remain outside verified local integration. Current browser checks are focused functional/keyboard/mobile checks, not a full WCAG certification.

Do not claim the full blueprint acceptance gate, real integrations or production deployment are complete based on the local test suite. Production-branch pushes store reviewed source only.
