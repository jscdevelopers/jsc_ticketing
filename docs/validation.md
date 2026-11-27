# Executed local validation - 27 November 2026

| Check | Verified result |
| --- | --- |
| Backend integration and real HTTP suite | 599 checks passed on Windows PHP and Linux container PHP |
| Concurrent claim race | 100 independent sessions: one winner, 99 conflicts, one claim event |
| Desktop/mobile Chrome E2E | Fourteen checks passed at 1440px and 320px, including visual multi-person stage configuration, point-and-click connections, keyboard layout, full IT escalation/manual dispatch, senior takeover, directed comments, 18-chart analytics and session-filter reset, plus: intake/draft, comments, private file check/download, claim/completion, administration/workflow publication, manager SLA/reassignment/sharing/withdraw/reopen, summary-only observer access, saved report draft/archive, CSV/PDF downloads, dashboard drill-down, keyboard sign-in and no horizontal overflow |
| Angular production build | Passed, approximately 594 kB initial bundle |
| npm audit | Zero reported vulnerabilities |
| PHP syntax and Composer strict validation | Passed |
| Linux Compose smoke | Build, fresh/repeated migrations, 599 checks, readiness and UID 10001 passed |
| Blueprint reference tests | 12 passed |
| Development Compose | Migrated and healthy on localhost:8180, API :8186; worker/private storage enabled |
| Offline Compose | Migrated and healthy on localhost:8187 using cached images; backend isolated |
| Local recovery exercise | Separate restore matched all table counts/content digests, sequences and private-file manifest; authorized intake/claim/completion passed |
| Demo provisioning | Local fictional staff/workflow created; repeated run and production rejection tested |

The backend suite covers fresh/repeated/checksum-guarded migrations and upgrade with retained records; posting/role/scope expiry and revocation; Secretariat versus national access; sensitive clearance; immutable versions/history; idempotent intake; transactional rollback after injected event/outbox failures; bounded graph/condition validation; parallel joins and independence; task ownership and conflicts; business calendar/DST/holiday handling; requester reply/timer advancement; one-time escalation and notifications; credential change/MFA boundary/throttling; organization audit and optimistic updates; reassignment/candidate refresh/SLA pause; withdrawal/reopen runs; approval rejection; attachment quarantine, sensitive metadata, rejected downloads, integrity failure and production refusal of demo scanning.

MFA tests inject a strict dummy provider at the adapter boundary. Local attachment E2E uses the explicitly labelled dummy check, not real antivirus. The expected sanitized request_failed log during the injected outbox exception is part of rollback verification.

Browser tests create a dedicated disposable PostgreSQL/API/worker/storage/web stack and separate desktop/mobile sessions. Test credentials and traces remain ignored. They do not change existing development/offline accounts or passwords. During testing, CSP-blocked critical CSS, changing custom-field identities and decorative icon accessibility names were corrected and rerun successfully.

Linux image smoke checks, all tracked environment configuration, and final runtime checks are recorded with each delivered commit. GitHub CI has been authored but has not been verified running on GitHub. No public deployment, live nAuth/SMTP/ClamAV, hardware test, full accessibility audit, target-scale load/recovery result or complete blueprint sign-off is claimed. See [remaining local acceptance work](local_operations.md#recovery-and-remaining-acceptance-work).

Additional checks cover summary-only department CC/watchers, current posting/role/revocation/privacy filtering, inability to claim/export from summary authority, minimal deduplicated notices, bounded send-back and rollback on exhausted limits, independent manager read/action scope, overdue queues, and publisher-only simulation/review/publication without edit authority.

Saved report tests verify private ownership, idempotent creation, optimistic edits/archive, allowlisted filters, current ACL on each run/export and loss of results after revocation. CSV/PDF share filters and export grants. An independent pypdf parser validated PDF pages, literal escaping and accented text. Local webhook tests verify target allowlisting, rejected URLs/extra config, local-only enablement, no-delivery audit and duplicate suppression.

JSC sample conversion: 10 provinces, seven court/office levels, 19 stations, 14 departments, 18 positions, nine service workflows, 35 fictional role accounts and 37 requests installed locally. Verified legacy password-hash preservation, renamed usernames, explicit national versus station scope, hierarchy-cycle rejection and repeat installation without duplicate requests/events. Offline data backed up before conversion. Source scope and provisional internal reporting links are documented in [JSC sample operating model](jsc_samples.md).

After conversion, the populated offline dashboard showed 37 requests in a real browser. Offline backup/restore was rerun against the JSC dataset: all table/sequence/file manifests matched and the restored requester-to-agent workflow completed. The offline station manager had already changed its password before conversion; its chosen password and credential timestamp were preserved.


IT workflow acceptance (27 November 2026):

- Both local environments have the published Computer faults and IT support flow, 38 additional IT/executive accounts and nine worked requests. Eight examples remain active across station, province, help desk, manual dispatch and four specialist stages; one is resolved. The total local sample dataset is 46 requests.
- Checked automatic station assignment, four category routes, unmatched-category dispatch, provincial isolation, senior takeover/idempotency/stale conflicts, prior-claimant rejection, revoked intervention access, failure rollback, completed-ticket Secretary comments, directed recipient authorization, immutable ticket diagrams, independent national publication and bounded personnel search.
- IT head/deputy analytics each returned 14 authorized department tickets and 18 charts. Secretary/deputy analytics each returned 46 authorized tickets and 18 charts. Six representative logins per environment accepted their private initial credentials, enforced first-password change and were signed out; existing chosen passwords were preserved.
- Successful primary authentication refunds its attempt reservation while retaining prior failures. Regression checks covered 35 successful sign-ins on a shared IP, per-account failed-password limits, 30 failed passwords across different usernames, separately bounded MFA starts and the 15-minute login Retry-After response. The expanded browser suite passes without disabling these limits.
- Repeated IT installation preserved accounts, credentials and samples. The updated offline backup restored separately with matching every-table content/count digests, sequences and private-file hashes; requester intake/claim/completion passed in the restored copy. Source data remained unchanged.
- Final PHP syntax, Composer strict validation, 12 blueprint tests, Angular build, zero-vulnerability npm audit and offline/development/production Compose configuration checks passed. Both local web/API/worker stacks were healthy after the update.

See [IT workflow operating guide](it_workflow.md) for role accounts, private credential locations and configuration steps. These results establish the tested local workflows; public deployment and external services remain unverified.
