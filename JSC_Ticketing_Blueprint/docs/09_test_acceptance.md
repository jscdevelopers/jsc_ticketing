# 09 — Test and acceptance plan

## Required E2E stories
A. Staff at Station A files ICT issue, sees accurate route preview, receives ticket ref; station ICT pool sees offer; one claims; a different eligible user gets 409; all timeline details are correct.
B. Category A automatically goes to Department X with default CC Y; CC can view allowed summary but cannot claim task.
C. Branch for Category B takes alternative path; unknown category cannot publish with missing fallback.
D. Finance and Legal must approve independently; join waits for both, refuses same actor if separation-of-duties configured.
E. User based at Secretariat lacks national grant: national dashboard 403 and no national data; user at another station with national grant sees only approved national data.
F. Multiple roles/postings and acting appointment with expiry, leave/transfer and department rename correctly resolve candidates and audit.
G. Step overdue triggers one escalation event and deduplicated notifications, following working calendar.
H. A published workflow is immutable; running ticket uses old workflow when admin publishes newer one.
I. Wizard simulation traces a route and identifies a zero-recipient pool; publish is blocked until fixed.
J. Requester cannot read restricted internal note or attachment; CSV/PDF export and websocket use same ACL.
K. Accidental double-click on Submit returns same created ticket via idempotency.
L. Failure during event/outbox insert rolls back task update and claim.
M. Screen-reader and keyboard users can navigate menus, progress rail, wizard and action flows; hover-only information has focus alternative.
N. 320px mobile, 200% zoom, slow network and reconnect preserve safe forms and communicate retry outcomes.

## Unit and integration
- Rule evaluator property-based tests: deterministic outcome, bounded graph, correct fallback precedence, malicious operators rejected.
- SQL constraints and FK tests; `READ COMMITTED` claim concurrency; optimistic version conflict test.
- API contract/JSON schema tests; HTTP status 400/401/403/404/409/422/429 properly mapped.
- Postgres migration on blank database and upgrade from staging snapshot; rollback recovery exercise.
- Notification outbox delivered-at-most-once logically with retries, dead-letter behavior.
- SLA business-hours, leap years, Zimbabwe timezone, exception holidays, due dates and paused clocks.
- Visibility and analytics queries under permission mutation and revoked grants.
- Load test at expected ticket/department/user volume agreed by JSC; record p50/p95/p99, DB query plans, locks and failed claims.

## Acceptance gate
Security, data migration, race tests, accessibility, business process workflow sign-off, station/province/national access approval, SLA accuracy, backup/restore and documented production runbook are all required. Prototype screenshots and example SQL are **not** evidence of an operating application.
