# 06 — SLA, notifications, analytics and administration

## SLA engine
Set policy at category, priority, department, step and scope level. Separate **first response**, **claim**, **action due**, **resolution** and **approval** targets; use business hour calendars with holiday exceptions. Pause only for approved statuses and reasons (e.g. requester input), track duration in `sla_events`, never retroactively hide breaches. Calculations use UTC instants and named IANA timezone, default Africa/Harare; use a schedule job for escalation, avoid assuming fixed holiday dates. Show `On track`, `At risk`, `Breached` with elapsed and remaining working time.

## Notifications
Events: created, routed, offered, claimed, comment/@mention, request-more-info, approved/rejected, escalated, SLA approaching/breached, completed, reopened, workflow changed. Channels: in-app/WebSocket or polling; email, SMS and push are optional integrations. User preferences and digest modes; critical breach alerts cannot be silently disabled without policy. Worker reads transactional `notification_outbox`, uses delivery dedupe keys, exponential retry with cap, dead-letter views and redacts sensitive content.

## Analytics and report contracts
Read model should distinguish `origin_station_id`, `current_owner_station_id`, `origin_province_id`, `responsible_dept_id`, `ticket_classification` and `national_scope_tag`. All analytics queries apply authorization server-side **before aggregation**; protect against inference from small groups. Metrics: first-response SLA %, resolution SLA %, median and p95 resolution, queue age, category volume, reopen %, reassignment churn, handoff bottlenecks, agent utilization, approval wait, resolved per week and satisfaction. Drill through only to authorized tickets. Report scheduled outputs must respect current permissions and token expiration at send time.

## Admin controls
- Departments manage categories/forms/templates subject to central publication policy.
- Global admin controls identity sync, org data, role template, scoped grants, classifications and retention.
- Provincial/station leads may get delegated configuration within a whitelisted scope.
- National viewers require explicit grant, not Secretariat station membership.
- Workflow draft approval and published version rollback create fresh versions; never edit historic graphs.
- Audit explorer supports actor, ticket, action, trace_id, date, result and signed/exportable evidence where policy permits.

## Operational anomaly alerts
Ticket unclaimed beyond target, route produces zero candidates, SLA clock stalled, workflow condition fails, notification dead-letter spike, unusual assignment patterns, mass export, repeated forbidden record probes and sudden policy changes. These are monitoring signals—not assertions of wrongdoing.
