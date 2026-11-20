# AGENTS.md — JSC Ticketing (Codex implementation contract)

## Mission
Build production-quality **JSC Ticketing**, an Angular + PHP/Nite + PostgreSQL + Docker cross-department workflow platform. Start by inspecting the existing Nite instance at `T:\source\work\jsc_creditors\` and deployment patterns at `T:\source\work\hrms\`. Preserve existing software and data. Don't assume either directory exists if running outside Windows; ask for a mount only if necessary in the implementation environment.

## Source of truth order
1. Existing checked-in source + tests + migrations + environment conventions actually found in those two paths.
2. Current workspace tests and security policies.
3. `docs/` and `specs/` in this package (desired behavior).
4. UI concepts and enhanced photos (visual inspiration only).
No unsupported API/method/package assumptions; document discovered Nite bootstrapping, routing, middleware, controllers, dependencies, models, migration patterns and error contracts in `docs/implementation_findings.md`.

## First-pass reconnaissance (read-only)
- Detect roots and branches, do not reset or switch branches without approval.
- Inventory Nite application bootstrap, PHP version/extensions, routing/auth and session/JWT, DI, query builder or PDO usage, exception handler, CSRF/CORS, migrations, testing, composer.lock.
- Inventory HRMS Dockerfiles/Compose, networks, healthchecks, .env.example, database backup and persistence, UID/GID/SELinux, release mechanism, bind volumes, reverse proxy, workers and runtime secrets. Never read real secrets into logs.
- Report actual vs proposed architecture, nonbreaking integration plan, and deliberate deviations from this package.

## Required build phases (deliver each with passing tests)
P0. Setup, skeleton, migrations, auth adapter, staff/org seeding import, API response contract, CI.
P1. Departments, provinces, stations (Secretariat as a station), positions, employment assignments and explicit scope grants; server-side effective authorization.
P2. Ticket intake with category schemas, attachments, watchers/CC, idempotency, rate limits, privacy labels, full activity history.
P3. Versioned workflow wizard + simulator + validation + stage execution; category A auto-route to department X, fallback paths, review/approve/send-back/escalate.
P4. Candidate eligibility by position/location/scope; offer to multiple users, **single atomic claim** and explicit release/reassign; parallel independent tasks when required.
P5. Station, province, user, department and nationwide/Secretariat dashboards with drill-down, SLA clocks, queues, saved reports and export.
P6. Notifications/outbox, scheduled escalations, security hardening, accessibility, observability, staging, rollout and documentation.

## Architecture and permission invariants
- Secretariat **station** and Secretariat **national oversight permission** are distinct data concepts; Secretariat-based users may be restricted and remote-station users may legitimately hold national grants.
- Requester location is captured at creation and may be distinguished from current assignment location; no retroactive access leakage.
- A ticket may touch many departments through tasks, route edges or CC; a department CC does not confer edit privileges or approval rights.
- A user's role is not equivalent to job position: positions determine routing eligibility; roles/grants determine actions and visibility; assignments include effective dates.
- The stage is an immutable step definition snapshot per published workflow version; active instances point to that version. Only authorized migration via explicit audited command may rebase instances.
- Per-instance candidates can be offered simultaneously. The first eligible actor acquires the row lock and claims; one unique winner. Return HTTP 409 when someone else already claims. Persist who was offered, claimant, release/reassign and reason.
- For 'ALL', 'ANY', or quorum approvals, spawn independent parallel task instances with policy; 'ANY' mode must cancel remaining tasks only under policy and write events.
- Ticket subject, category, priority and field values cannot be trusted to control authorization; all cross-department and record-level access enforced in backend.
- Comments support `public_internal`, `participants_only`, and `restricted` sensitivity; avoid exposing secrets through notifications, analytics or searches.
- Idempotency-Key for mutations; optimistic version guards and row locks for state changes. One SQL transaction must include state transition, actor attribution, audit event and notification outbox enqueue; external notifications are sent after commit and retried idempotently.

## Workflow wizard behavior
Creation stages: 1. metadata/category/initiator → 2. form fields & validation → 3. routing conditions & audience resolver → 4. graph steps/branches/parallel blocks → 5. SLA/calendar/CC/permissions/notifications → 6. simulation → 7. review/approve/publish.
Provide node library: start, intake, task, claim pool, decision, route, approval, parallel fork/join, request information, wait/timer, escalation, webhook (admin allowlist only), close/reopen. User-friendly UI with property drawer, errors, draft autosave, version diff, rollback-to-new-version and tests; never execute arbitrary user-supplied code/SQL/JS in workflow conditions. Validate every path terminates or boundedly returns.

## UX contract
Angular responsive app, dark charcoal collapsible sidebar, deep green top navigation, restrained amber action highlights, neutrals for content, high contrast. Pages: personal dashboard, my tickets, my actions, team queues, department queues, all authorized tickets, ticket detail, activity history, SLA monitor, workflows, route-rule editor, approvals, reports, administrative hierarchy, settings. Ticket detail must show a horizontal progress rail with **hover AND keyboard-focus** step popovers (owner, department, eligible actors, start/end, SLA, outcome), plus chronological audit panel. Show branching and parallel blocks as parallel steps rather than an inaccurate single linear progress percent. No direct copying of vendor branding/artwork from source photos.

## Functional test matrix (minimum)
- 100 simultaneous eligible staff claim a task → exactly one winner; 99 deterministic conflict/ineligible outcomes, exactly one CLAIMED event.
- Claimed actor releases; an eligible candidate may claim again; prior claimant and reason remain visible.
- National visibility absent for Secretariat station user with no national grant; remote station staff member with valid national grant can see authorized national data.
- Staff with multiple station/department postings receive permitted union only within time window; no branch leakage.
- Category A and category B route correctly; missing category fails safe and alerts admin rather than silently choosing any department.
- CC department is notified but cannot claim without a task grant.
- Workflow version N remains running after version N+1 is published; invalid graph cannot publish.
- Required independent legal + finance reviews must produce two tasks; join waits for both; timeout/escalation visible.
- Permission failure yields 403 without revealing object existence beyond authorized response contract; ticket ID guessing fails.
- Duplicate submit with same idempotency key creates one ticket, one event and one outbox job.
- Pagination + exports never bypass scope filters; attachment downloads enforce same ACL.
- DST/holiday-safe SLA calendar calculations; Africa/Harare as display default, timestamps UTC.
- Failures after state update roll back state and audit together; retry outbox never duplicates a user-visible notice.

## Required outputs / definition of done
- Tested migrations and reversible roll-forward plans (never destructive data deletion on deploy).
- Angular application with unit + E2E tests, keyboard/accessibility checks.
- PHP/Nite endpoints with integration tests, RBAC/ABAC and optimistic concurrency.
- PostgreSQL backups/restore test, indexes, evidence retention, audit integrity.
- Docker image build/staging Compose/healthchecks, startup/migration/runbook.
- Operator manual, admin wizard guide, user guide, architecture decision records, threat model, release notes.
- Explicit 'known gaps' report; no fake passing tests, no fabricated repository inspections.

## Coding, safety and operations
Use strict PHP types, parameterized SQL, tested transaction boundaries, checked exceptions, structured logging with correlation IDs, linting and formatter. Never hardcode real passwords, tokens, secret keys, domain names, or production URLs. Scan uploads; store privately; use signed/authorized downloads; prevent SSRF/webhook abuse; sanitize rich text; cap payload sizes. Prefer no externally sourced packages until assessed against project constraints. Maintain reproducible builds and semantic release versions. Keep README, migrations, API docs and tests synchronized with implementation.
