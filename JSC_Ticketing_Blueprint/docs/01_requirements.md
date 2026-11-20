# 01 — Product requirements and functional scope

## Mission and actors
JSC Ticketing is a single enterprise ticket ledger with configurable, department-specific case journeys. Staff submit issues anywhere they are authorized. Departments can collaboratively process tickets while retained responsibility, history and service targets remain evident. Typical actors: requesters; department agents; supervisors; departmental workflow designers; provincial managers; station managers; national oversight members; Secretariat staff with or without national scope; system administrators; auditors.

## Primary requirements (P0/P1)
| ID | Requirement | Acceptance criteria |
|---|---|---|
| F-01 | Staff file a ticket on a chosen category | Dynamic required fields, assigned reference, acknowledgement and requester's original location |
| F-02 | Department-owned categories | Each department maintains independent category tree, validity and ticket form schema |
| F-03 | Smart routing | Category A can immediately route to Department X at local/provincial/national scope; fallback explicitly required |
| F-04 | Multi-department handoffs | Each workflow can sequentially/parallelly route tasks to different departments; responsibility changes logged |
| F-05 | Multiple eligible recipients | One instance is offered to many matching a position/location policy; any ONE can claim atomically |
| F-06 | Multiple required actors | Separate parallel task instances / join policy for independent approvals; cannot represent as a single claim pool |
| F-07 | Full timeline | Append-only timestamped events with who, what, from/to, note, attachments, route, outcome and system automation |
| F-08 | Visual progress | Stages, statuses, achieved milestones, next steps, SLA and hover/focus detail; branching shown honestly |
| F-09 | Wizard | Authorized admin composes versioned workflow, dynamic forms, conditions, tasks, CC and escalation without coding |
| F-10 | Manual / rule-based CC | Actor can add eligible persons/departments; rules may add automatic subscribers; sensitive visibility enforced |
| F-11 | Multiple reporting scopes | Personal, station, province, department, countrywide/national and Secretariat views, each server-filtered |
| F-12 | Secretariat nuance | A station named Secretariat exists, but place of work **does not automatically grant nationwide scope** |
| F-13 | Ticket lifecycle | Draft → Submitted → Routed → Claimed/In Progress → Awaiting Input/Approval → Resolved → Closed, with reopen |
| F-14 | Reassignment | Release claim, delegate, escalate, transfer; requires reason and log |
| F-15 | SLA | Response, claim, step and resolution timers with business calendars, working hours, pauses and breach notices |
| F-16 | Permissions | Explicit grants + position/department eligibility + case sensitivity; never infer authority from UI |
| F-17 | Files / comments | Private attachments, file scanning, public/internal notes, @mentions, dynamic forms |
| F-18 | Search / filtering | Full text, reference, category, owner, status, requester, province/station, SLA, dates, saved views |
| F-19 | Notifications | In-app first, email/SMS optional, opt-in preferences, reminders, delivery history, outbox retries |
| F-20 | Audit & export | Attributable business audit trail, controlled PDF/CSV exports, immutable history retention and safe redaction |

## Recommended additions identified from product research
1. **Service catalog** of departmental request types with plain-language descriptions and FAQ/knowledge-base articles before ticket creation.
2. **Duplicate detection / merge suggestion**, with safe related-ticket links rather than silent destructive merge.
3. **Parent/child tickets**, sub-tasks, dependencies, incident/problem/change linkage, bulk import for authorized staff.
4. **Impact and urgency matrix**, triage queue, backlog age and effort/throughput measurement.
5. **Dynamic SLA calendars**, public holidays, escalation policies, on-call/delegation and out-of-office coverage.
6. **Rule execution logs** and 'why routed here' explanations visible to administrators.
7. **Workflow sandbox simulation** and impact preview, with approvals before publishing high-risk workflows.
8. **Assignment capacity & work balancing** (optional later stage), skill eligibility, workload dashboards.
9. **Feedback / satisfaction** after close, quality review and resolution code taxonomy.
10. **Recurring tickets**, templates, scheduled preventive tasks, knowledge suggestions and self-service portal.
11. **Anti-stall automation**: unclaimed, blocked, awaiting-info, overdue, handoff ping-pong alerts.
12. **Delegated authority with dates**; temporary acting positions; multi-posted staff; temporary cross-station duties.
13. **Reporting snapshots** with aggregate privacy thresholds and export approvals for sensitive cases.
14. **Integration hooks** through restricted outbound events, authenticated API, approved SSO/2FA and directories.
15. **Accessibility, low-bandwidth and responsive layouts** for station connectivity constraints.

## Ticket header fields
Immutable reference; subject; department/category/type; requester identity and snapshot (position, department, station, province); current owner department; active task assignee; priority/impact/urgency; sensitivity; submission channel; description/structured fields; status; workflow version; due dates; tags; related IDs; created/updated/resolved/closed timestamps; soft-delete/retention status (if approved). Avoid putting sensitive narrative in ticket headers/notifications.

## Ticket actions
Create, save draft, submit, validate, categorize, route, claim, release, delegate, transfer, work, comment, ask for information, reply, approve/reject/request amendments, attach, CC/watch, block, pause/unpause SLA with reason, escalate, resolve, close, reopen, withdraw with policy, download evidence, export with permission. Each action has authorization, idempotency and append-only audit.

## Dashboard lenses
- **User**: assigned-to-me, offered-to-me, raised-by-me, pending actions, at-risk/overdue, recent activity.
- **Station**: tickets originating from station vs handled by staff at station (separate filters), category/department split, resolution time, outstanding approvals.
- **Province**: station comparisons, aging, volume, SLA, reasons for escalation, national referral ratio.
- **Department**: inbound, outbound, actions in queue, workload by position, handoffs, category throughput.
- **National**: province/department leaderboard, hotspots, bottlenecks, SLA compliance, backlog and month-over-month trends, authorization-aware drill-down.
- **Secretariat station**: local operational view; **not** synonym for nationwide dashboard.

## Nonfunctional targets for validation (not performance claims)
Responsive within 2s p95 for typical indexed ticket lists under agreed load; transactional API under 500ms p95 excluding upload/render; job retry with eventual notification delivery; zero unauthorized cross-scope reads; RPO/RTO to be agreed with JSC before deployment; HA/scale only if measured. Use UTC DB timestamps and user-configurable display timezone, default Africa/Harare.
