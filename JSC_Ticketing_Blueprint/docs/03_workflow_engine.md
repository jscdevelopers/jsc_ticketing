# 03 — Workflow designer, engine and progress representation

## Core distinction: offer once vs many independent actions
**Pool task:** one task instance, candidate set of many eligible users (same position and contextual scope). One actor clicks **Claim**, locks the row, and becomes assignee; all others see 'Claimed by X'. May release or transfer with authorization and recorded reason.
**Parallel tasks:** create separate instances for Finance and Legal, e.g. both must approve. Join policy `all`, `any` or `quorum(n)` is explicit; actions are independently attributable. Do not implement multiple approvals by letting one actor claim one shared task.

## Wizard steps
1. **Basics:** workflow title, owner department, ticket category, initiator selector (`everyone`, role, position, station, province, explicit national grant), effective date, description, and sensitivity.
2. **Intake form:** typed custom fields, required/conditional rules, options, help text, attachments, validation, multi-language/accessible labels.
3. **Routing rules:** deterministic conditions from safe JSON DSL, ordered precedence, outputs to department/position and station/province/national strategy, default route and diagnostic preview.
4. **Process canvas:** add nodes and arrows; decide/split/join, pool tasks, parallel approval, review, request-info, SLA timer, escalate, handoff, webhook (allowlisted), resolve/close.
5. **Policies:** participants & CC defaults; permissions; comment visibility; SLA; assignment timeout; escalation; pause reasons; notifications; holiday calendars.
6. **Simulate:** choose example request context; trace conditions, target recipients, time budgets and route steps; error on zero recipients unless intentional fallback.
7. **Review and publish:** validate, show graph/diff, require approval where configured, immutable new version and optional activation schedule; preserve old instances.

## Safe condition DSL
Allow whitelisted `and/or/not`, `eq/neq`, `in/not_in`, `gt/gte/lt/lte`, `exists`, field paths from an allowlist (`ticket.category`, `ticket.priority`, `requester.province_id`, custom field IDs). No `eval`, no arbitrary PHP/JavaScript/SQL, no network calls in rule predicates. Type-check field paths, cap tree depth and fan-out. Conditional branches must include a fallback or explicit rejection. Rules should be deterministic given a captured request snapshot.

## Node types
`start`, `intake`, `task`, `claim_pool`, `approval`, `decision`, `route`, `parallel_fork`, `parallel_join`, `request_info`, `timer`, `escalation`, `notification`, `webhook`, `resolve`, `close`, `end`. `route` may change accountable department, while `cc`/notification does not. Keep task state separate from ticket state.

## Workflow lifecycle
`draft → validated → awaiting_publish_approval → published → active → deprecated → archived`. Draft may be edited; published versions are immutable and referenced by `tickets.workflow_version_id`. Each activation creates step instances and candidate recipient snapshots; transitions issue deterministic events. An explicitly authorized, auditable migration may move old instances to a new version with safety checks and written reasons.

## Core runtime algorithm
1. Begin PostgreSQL transaction and lock ticket/workflow execution (and relevant step) rows.
2. Authorize actor and `If-Match`/version; ensure idempotency record or reserve key.
3. Validate task is active and action is on allowed edge. For task action, enforce claimant or eligible approver. Update task state atomically.
4. Append ticket event with actor/system identity, metadata and before/after.
5. Evaluate edges from the immutable workflow definition; spawn next step instances or parallel branches; resolve candidate users from approved assignments + scoped context; fail safely when none.
6. Update overall ticket state + progress projection (transactional or versioned recompute); enqueue notifications in outbox in same transaction.
7. Commit, then worker publishes emails/SMS/webhooks idempotently. Client receives authoritative new version and timeline.

## Stage progress and hover/focus
Don't show a fabricated 60% when flow includes branches or loops. Represent path as milestones: completed green; active amber; future neutral; blocked red; parallel branch children shown under a parent marker. Optional percentage is a **weighted projection** with documented formula and clear 'current stage'. Popover includes node name, department/position, candidate pool/assignee (subject to data access), started/completed dates, expected/breached SLA, action and last comment. Same information must be available with keyboard focus and in a permanent details panel.

## Versioned graph publication checks
- Unique node IDs and edges only to existing nodes.
- Start exists exactly once; at least one terminal; all reachable tasks can eventually terminate.
- No unconditional infinite cycles; optional bounded rework loop with `max_iterations` and explicit escalation exit.
- No join without matching fork; consistent branch IDs and join policy.
- Every decision has deterministic precedence and an `otherwise` path.
- No orphaned nodes; valid task categories and position scope selectors.
- Recipient resolution test with representative staff datasets; error/alert when no eligible worker.
- All SLA/calendar refs resolve, deadlines positive, escalation targets authorized.
- Sensitive data policy compatible with CC and notifications.
- Only approved administrators publish; second approver for national/high-risk templates.

## Exception patterns
On user transfer/absence, allow privileged *recompute candidates* with reason. On no candidate, place task in exception queue, alert supervisor, never auto-grant national rights. On SLA violation, escalate according to config (notify; optionally create supervisor subtask). On send-back, preserve every original completion event and mark stage as 'returned' rather than deleting history. Handle canceled branches with explicit canceled events.
