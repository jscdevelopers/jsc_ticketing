# 08 — Security, privacy, records and audit requirements

The JSC environment can contain sensitive operational/employee information. This is a threat model baseline, not an assertion of JSC-specific regulatory classification or retention law; validate with its information-security/records officers.

## Controls
- Identity: trusted login, MFA/SSO if approved, session expiration, device/session revocation, secure cookies and CSRF for cookie auth; lockout/rate-limit; no client-supplied national scopes.
- Backend authorization: enforce RBAC+ABAC for **every ticket, task, CC, comment, attachment, report/export and workflow admin endpoint**. OWASP API1 broken object-level authorization and API5 broken function-level authorization are key risks.
- Content classification: internal/restricted/confidential tickets; field-level restriction for personnel/legal content; redact emails/notifications; optional separate case vault for highly sensitive types.
- Data isolation: department, station, province, explicit national grants; 'admin' role not necessarily a blanket right to read confidential bodies.
- Audit: append-only events with actor and system identity, UTC timestamp, action, previous/new state, network/request trace, reason, success/failure (security log) and workflow version. Separate high-integrity security log from normal activity stream. Audit immutability via restricted DB grants/append-only tables, exported tamper evidence if required, back-ups and retention policy.
- Upload safety: MIME/extension/size allowlist, antivirus quarantine, randomized storage key, content-disposition attachment, no public webroot, controlled presigned URL or authenticated streaming, XXE safeguards for office formats, optional OCR only within secure boundary.
- Input: validation and field whitelist, length caps, prepared SQL, HTML sanitize, no workflow `eval`; rate limit search/exports; avoid formula injection in CSV exports.
- Webhook connectors: registered destinations, internal IP/DNS protections, egress allowlist, signed messages, bounded retries, secret rotation.
- Backup/recovery: encrypted backups, regular restoration drill, segregated staging data, secure key storage, legal holds, tested retention/purge paths.
- Admin changes: two-person review for national workflow publish and grant of nationwide access, approval expiry, full history.

## Security test categories
BOLA (query/guess IDs), staff cross-province access, field-level confidentiality, unauthenticated attachment downloads, privilege escalation by modifying role/station/position in request, workflow graph injection, JSONB query abuse, simultaneous claim race, CSRF, XSS, SSRF, report export leakage, assignment roster expiration, outbox replay and log tampering.

## Privacy by design
Analytics default to counts/aggregates; masking/pseudonymization for test data; limit CC subjects/notes; consent/notification settings; evidence confidentiality; deletion/retention only under approved records policy. 'Who did what' is visible to authorized users and must not expose unrelated staff personal information.
