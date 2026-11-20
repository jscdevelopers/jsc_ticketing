# 02 — Organization, duty positions, scope and permission model

## Separate four dimensions
1. **Location:** Province → Station (Secretariat is a station; any chosen province entry must be validated with JSC HR). Staff can be at a station or hold multiple postings.
2. **Department:** Each user may be in one or more departments and units, including cross-location department service teams.
3. **Job position:** Human-facing title (ICT Officer, Registrar, Finance Officer, Procurement Officer). Positions determine task-candidate matching but not administrative privileges.
4. **Authority:** Explicit role + `scope_grants` + policy conditions (actor is active, approved department clearance, record sensitivity, task state); this is independent of location.

A stationed Secretariat employee may have only local authority. An employee at Mutare may hold a properly approved `national` grant. Do not auto-elevate either user. National oversight becomes true only from valid `scope_grants`/delegations.

## Recommended model
- `users`: identity from approved auth/directory source, active status and identity lifecycle.
- `provinces`: JSC organization-maintained dictionary (do not seed political/geographic hierarchy as permission truth).
- `stations`: active station, province_id and `is_secretariat_station` display flag (not ACL).
- `departments`: top-level department taxonomy; `department_units` optional per station/province.
- `positions`: canonical position definitions, aliases mapped from HR, duty eligibility.
- `staff_assignments`: user, position, department, station, effective_from/to, acting/delegated flag.
- `roles` / `permissions` / `role_permissions`: operator actions, e.g. `ticket.create`, `ticket.read`, `task.claim`, `workflow.publish`, `report.national`.
- `scope_grants`: actor, allowed resource/action, scope kind (`self`, `station`, `province`, `department`, `national`, `ticket_explicit`), scope ID nullable by kind, valid-from/to, issued_by, reason, revoked_at.
- `ticket_participants`: requester, collaborator, watcher, cc-person, cc-department, auditor, explicit owner; visibility policy only.
- `task_candidates`: snapshot of users or assignment/position matching at task activation, with reasons and version.

## Authority rule
`allow(action, actor, ticket or task) = authenticated AND active AND permission(action) AND in_scope(actor, resource) AND record_sensitivity_allows AND workflow_state_allows AND (if task: candidate_or_explicit_delegate)`.
Compute **on every read/write**, not just sidebar menu. Department CC confers no claim rights, and a task assignment confers only relevant ticket read/action scope for that task.

## Sample role permissions (illustrative — validate at JSC)
| Persona | View own | Claim candidate | Add CC | Publish workflows | Nationwide analytics |
|---|---:|---:|---:|---:|---:|
| Requester | Yes | Only if task candidate | Policy-controlled | No | No |
| Agent | Scoped | Yes | Policy-controlled | No | No |
| Station supervisor | Station | If eligible | Scoped | No | No |
| Provincial manager | Province | If eligible | Scoped | Approval only | Only province |
| Department designer | Department | If eligible | Scoped | Draft; publish via approval | Department metrics |
| Secretariat employee (local) | Own/local | If eligible | Scoped | No | No |
| National oversight grant holder | Explicit scope | If eligible | Scoped | Only with permission | Yes |
| System admin | By privilege + data-policy | Not automatically | Config | With dual control | Only if data-access granted |
| Auditor | Approved read-only | No | No | No | Approved aggregate/read scope |

## Sensitive data
Tag ticket and attachment `internal`, `restricted`, `confidential`; support restricted commenters and field-level secrecy. Reporting counts may include redacted aggregates but no unauthorized names or narrative. Restrict file URLs. Station leaders must not assume cross-department data rights merely by location.

## Cross-station/department edge cases
- Staff temporarily acting as Registrar at another station: assignment effective dates and approval.
- Transfer during an open ticket: preserve requester snapshot and re-evaluate ongoing candidate access through reassign procedure.
- Department reorganized: old tickets retain historical identity; remap future workflow versions, keep audit.
- Multiple active postings: union allowed only with explicit business purpose and scopes; do not combine separate scopes to escalate (e.g. a station grant + a department grant does not imply all departments in all stations).
- External contractors: restricted portal role and NDA/expiration; not in nationwide staff views unless explicitly licensed.

## Preventable errors
Never determine national status with `station.is_secretariat = true`; never trust `role` fields from JWT without server verification; never authorize via frontend filters; never remove candidate/offered history when a task is claimed; do not leak arbitrary ticket IDs across scopes.
