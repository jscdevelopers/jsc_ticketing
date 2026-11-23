# Executed local validation - 23 November 2026

| Check | Verified result |
| --- | --- |
| Backend integration and real HTTP suite | 433 checks passed on Windows PHP and Linux container PHP |
| Concurrent claim race | 100 independent sessions: one winner, 99 conflicts, one claim event |
| Desktop/mobile Chrome E2E | Six checks passed: intake/draft, comments, private file check/download, claim/completion, administration/workflow publication, keyboard sign-in and no horizontal overflow |
| Angular production build | Passed, approximately 520 kB initial bundle |
| npm audit | Zero reported vulnerabilities |
| PHP syntax and Composer strict validation | Passed |
| Linux Compose smoke | Build, fresh/repeated migrations, 433 checks, readiness and UID 10001 passed |
| Blueprint reference tests | 12 passed |
| Development Compose | Migrated and healthy on localhost:8180, API :8186; worker/private storage enabled |
| Offline Compose | Migrated and healthy on localhost:8187 using cached images; backend isolated |
| Demo provisioning | Local fictional staff/workflow created; repeated run and production rejection tested |

The backend suite covers fresh/repeated/checksum-guarded migrations and upgrade with retained records; posting/role/scope expiry and revocation; Secretariat versus national access; sensitive clearance; immutable versions/history; idempotent intake; transactional rollback after injected event/outbox failures; bounded graph/condition validation; parallel joins and independence; task ownership and conflicts; business calendar/DST/holiday handling; requester reply/timer advancement; one-time escalation and notifications; credential change/MFA boundary/throttling; organization audit and optimistic updates; reassignment/candidate refresh/SLA pause; withdrawal/reopen runs; approval rejection; attachment quarantine, sensitive metadata, rejected downloads, integrity failure and production refusal of demo scanning.

MFA tests inject a strict dummy provider at the adapter boundary. Local attachment E2E uses the explicitly labelled dummy check, not real antivirus. The expected sanitized request_failed log during the injected outbox exception is part of rollback verification.

Browser tests create a dedicated disposable PostgreSQL/API/worker/storage/web stack and separate desktop/mobile sessions. Test credentials and traces remain ignored. They do not change existing development/offline accounts or passwords. During testing, CSP-blocked critical CSS, changing custom-field identities and decorative icon accessibility names were corrected and rerun successfully.

Linux image smoke checks, all tracked environment configuration, and final runtime checks are recorded with each delivered commit. GitHub CI has been authored but has not been verified running on GitHub. No public deployment, live nAuth/SMTP/ClamAV, hardware test, full accessibility audit, target-scale load result or complete blueprint sign-off is claimed. See [remaining local acceptance work](local_operations.md#recovery-and-remaining-acceptance-work).
