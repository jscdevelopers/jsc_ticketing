# JSC Ticketing — implementation blueprint and visual prototype

**Prepared:** 20 November 2026  
**Status:** detailed implementation package / design reference; **not** a production-complete or Nite-integrated application.

## Vision
A configurable, cross-departmental ticket and case-management platform for the Judicial Service Commission (JSC). Any authorized staff member can raise a request. An administrator designs category-specific procedures with a wizard; work is routed automatically to departments and eligible users by **position, station, province, explicit national scope and other conditions**. Several recipients may receive the same actionable task, but one person atomically claims it. Other procedures require genuine parallel independent approvals. Every step, assignment, change, comment, SLA and outcome appears in a traceable timeline.

## Included
- `AGENTS.md`: implementation contract for Codex and other coding agents, with repository inspection and no assumptions about Nite APIs.
- `docs/`: complete product requirements, organizational model, wizard/workflows, audit, UX, security, reporting, tests, deployment and rollout.
- `database/001_foundation.sql`: PostgreSQL foundation migration with constraints and integrity model.
- `specs/`: workflow examples, JSON schema, example scopes and API endpoint contracts.
- `backend/examples/`: PHP/PDO atomic-claim sample; **adapter sample**, not a drop-in Nite controller.
- `frontend/angular-reference/`: Angular source reference for keyboard-accessible progress stages.
- `designs/gallery.html`: **start here to review screen designs and photos**.
- `designs/prototype/index.html`: **open in a browser** for a working, interactive, offline visual demonstration (sample data only).
- `designs/concepts/`: three AI-assisted screen concept boards (design exploration, not factual screenshots).
- `designs/vector/`: editable SVG UI screens and diagrams (generated from this specification).
- `reference_photos/original/` and `enhanced/`: originals plus conservative image improvements. Enhance/zoom does not restore unreadable text.
- `deploy/`: example Docker Compose infrastructure; **does not replicate the user's existing HRMS without inspection**.

## Critical project dependencies (not accessible in this environment)
- Nite backend implementation: `T:\source\work\jsc_creditors\`
- Deployment conventions: `T:\source\work\hrms\`

These Windows paths must be inspected by Codex running on the user's Windows machine or in a mounted workspace. **Do not fabricate Nite method names, Docker conventions, authentication code or file paths.** Document findings before creating adapters. See `docs/12_integration_discovery.md`.

## Recommended order
1. Review `docs/01_requirements.md`, `docs/02_organization_access.md`, and `docs/03_workflow_engine.md`.
2. Inspect the two local repositories, run tests, record confirmed Nite/HRMS patterns.
3. Approve the org hierarchy, permission matrix and example workflows with JSC stakeholders.
4. Implement migrations + authorization + deterministic workflow runner with tests.
5. Build Angular app to the reference designs, integrate nAuth/SSO only after discovery.
6. Deploy to **staging**, performance/security test and obtain sign-off before production.

## Non-negotiable architecture rules
- **Being based at the Secretariat station does not grant national privileges.** National scope is separately, explicitly granted.
- **CC means visibility/notifications, not a task claim or approval.** To action, assign a task.
- **Offer-to-many, claim-once** uses a database transaction; **parallel review** uses separate task instances.
- Publish immutable workflow **versions**. Running tickets retain their bound versions.
- Validate full route graph and permissions before publication; maintain a safe fallback and override audit.
- Never trust Angular-supplied roles, station, province, workflow graph, or authorization decisions.
- Every state change writes business state + event + outbox within the **same transaction**.

## Honest limitations
The source repositories were not provided; this package cannot prove Nite or Docker compatibility or provide a tested final system. The UI prototype is static/local demo data and the example SQL/PHP require integration and automated test coverage before deployment. Reference concept mockups include synthetic labels and figures; some AI exploratory boards even contain foreign geographical placeholders and must not be used as JSC data. Preferred screen designs are `designs/vector/04–06` and `designs/gallery.html`.
