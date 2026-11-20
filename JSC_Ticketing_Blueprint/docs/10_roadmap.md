# 10 — Delivery roadmap and prioritization

| Increment | Outcome | Critical exit gate |
|---|---|---|
| Discovery (first) | Confirm JSC org, Nite architecture, HRMS Docker, auth, templates | Approved architecture decision + observed repo inventory |
| MVP-A | Identity/assignments/scopes; categories; submit and view tickets; chronological events | Cross-scope security tests and DB transactions |
| MVP-B | Claim pools, actioning, multi-department routing, progress rail | 100-way race test and workflow state tests |
| MVP-C | Wizard draft/validate/simulate/publish and branches, parallel approvals | Business-user route sign-off, immutable versions |
| MVP-D | My/Station/Province/Department/National dashboards and SLA clocks | Scope filtering and SLA calibration |
| Hardening | Attachments, CC, notifications, escalation, records, observability | End-to-end security + backup/restore |
| Expansion | Self-service knowledge base, mobile PWA, scheduled tickets, email intake, capacity-based assignment, trend forecasting | Pilot adoption and measured operational value |

Prioritize correctness and confidentiality before feature breadth. Never deploy all departments at once without category/rule governance. Pilot one or two departments and a handful of station/provincial contexts; test Secretariat staff with and without national rights, then phased national rollout.

## Business decisions still requiring JSC sign-off
Official departments, positions, stations/provinces and naming; national grant authority; categories and department owner map; initiation policies; confidentiality levels; retention/legal hold; SLA targets/holiday calendars; notification channels; production domain/SSO; which departments need two-person publication approvals; true process completion conditions; required reporting fields and data migration.
