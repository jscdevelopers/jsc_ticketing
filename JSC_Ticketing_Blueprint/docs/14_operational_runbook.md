# 14 — Operational checklist

1. Obtain approved source review and deployment owner; do not use the ZIP's Compose template unmodified in production.
2. Create dedicated Postgres DB/user and private persistent storage; confirm UID/GID, SELinux and rootless engine behavior on target host.
3. Configure TLS proxy, CSP, request-size limits, authenticated /api, per-service firewalls; no public DB port.
4. Inject secrets from approved store. Rotate them; redact logs and backups.
5. Dry-run migration on staging snapshot, take backups, record checksum and restore command; confirm no data loss.
6. Run lints, unit/integration/API tests, 100-claim race test, accessibility audit, route simulation and national-scope negative tests.
7. Roll out pilot departments, confirm SLA calendar and report correctness with domain owners.
8. Monitor API p95, task contention, failed routes, zero-candidate exceptions, outbox retries and overdue tasks; practice alert response.
9. Establish retention/legal hold schedule, tamper-evident audit export, disaster recovery and administrator handover.
10. Use canary/blue-green deployment only after schema compatibility and idempotent worker jobs are verified.
