# 13 — REST API outline (prefix `/api/v1`)

## Standards
JSON, stable id UUID and human reference; bearer/session auth per discovered Nite support. API responses include `request_id`, `data` and `error` (when applicable), pagination cursor, consistent `409` for claim races, `422` for invalid workflow fields, `403` for forbidden. `Idempotency-Key` required for creates/actions; `If-Match: "version"` for competing writes; server authorizes all record-level resource scopes. Store UTC instants as ISO 8601.

| Method | Route | Purpose |
|---|---|---|
| GET | `/me` | Profile, effective permissions, authorized scope choices (not raw secret grants) |
| GET | `/directory/departments`, `/directory/stations`, `/directory/provinces`, `/directory/positions` | Scope-filtered choices |
| GET | `/categories?department_id=` | Ticket categories, dynamic field schema and applicable wizard |
| POST | `/tickets` | Create and route, idempotent; requester context derived from server identity |
| GET | `/tickets` | Filter/paginate only authorized records; server-side visibility |
| GET | `/tickets/{uuid}` | Ticket overview; constrained fields and permissions |
| GET | `/tickets/{uuid}/timeline` | Events visible to actor, chronological; before/after sensitive fields redacted |
| GET | `/tickets/{uuid}/progress` | Steps/branches, owner and SLA hover/focus summaries |
| POST | `/tickets/{uuid}/participants` | Add person/department CC under policy; auditable |
| GET/POST | `/tickets/{uuid}/attachments` | Authorized list/create staging; scan and quota |
| POST | `/tasks/{uuid}/claim` | Atomic one-winner claim, 409 conflict if claimed elsewhere |
| POST | `/tasks/{uuid}/release` | Return to pool with mandatory reason; authorize actor/supervisor |
| POST | `/tasks/{uuid}/actions` | Complete/approve/reject/request info/route; version checked |
| GET/POST | `/workflows` | Browse/draft workflow definitions, permission-aware |
| POST | `/workflows/{uuid}/simulate` | Sandbox trace and zero-recipient diagnostics |
| POST | `/workflows/{uuid}/versions/{n}/publish` | Immutable validated publication with policy approval |
| GET | `/dashboards?scope_type=&scope_id=&period=` | Authorization-filtered metrics and drill-down tokens |
| GET | `/audit/events` | Restricted event explorer, export controlled |
| GET | `/notifications` | Current user only, read states |

### Example create
`POST /api/v1/tickets` with `Idempotency-Key: ...`:
```json
{
  "category_id": "category-uuid",
  "subject": "Printer not working",
  "description": "Station printer unavailable since 08:00",
  "priority": "normal",
  "fields": {"asset_tag":"JSC-PR-018"},
  "cc_departments": ["approved-dept-uuid"]
}
```
Expected: 201 with ticket + workflow version + initial task recipients summary; replay same key/identical payload yields same response and no duplicate event. Category/routing ACL and requester scope are server-side, not client-declared.

### Claim
`POST /api/v1/tasks/{task_id}/claim` with `Idempotency-Key` and optional expected version. Body `{}`; success `200 {task_id, claimed_by, status:"claimed", version}`; conflicting claimant 409 `{code:"TASK_ALREADY_CLAIMED", claimed_by_visible_name?}`. Unauthorized actor 403; inactive task 409/422. No `SKIP LOCKED` on claim; the row is waited on and rechecked under lock.

### Events
Minimum event payload: ticket_uuid, task_uuid optional, action, actor identity or system, utc timestamp, scope, from/to states, workflow version, reason, request_id, visibility classification. Public timeline is filtered and may be redacted; security audit includes attempted forbidden access separately.
