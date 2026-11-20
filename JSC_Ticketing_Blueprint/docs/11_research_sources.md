# 11 — External best-practice research (20 November 2026)

The following are **external feature inspirations**, not JSC-approved requirements and not proof of integration. Please validate licensing, institution-specific procedure and security before implementation.

| Reference | Feature observed and design consequence |
|---|---|
| Atlassian, Jira Service Management main features | Service catalog/request types, dedicated queues, automation, SLA policies; supports departmental ticket type catalog and metrics. https://support.atlassian.com/jira-service-management-cloud/docs/get-to-know-the-main-jira-service-management-features/ |
| Atlassian, Requests and work items | Request types separated from issue/workflow types; supports a category/requester-facing form mapping to an internal process. https://support.atlassian.com/jira-service-management-cloud/docs/whats-the-difference-between-request-types-and-issue-types/ |
| Freshservice, Workflow Automator | Drag-and-drop assignment and approval automation; informs admin wizard and test simulation. https://support.freshservice.com/support/solutions/articles/50000011845-manage-workflows-for-service-desk |
| Freshservice, Workflow execution logs | Rule execution diagnostics; informs 'why routed to X' trail and admin simulator. https://support.freshservice.com/support/solutions/articles/232139-workflow-automator-an-overview |
| Freshservice, ticket assignment | Policy-based group assignment; informs candidate-pool assignment and queue policy. https://support.freshservice.com/support/solutions/articles/50000011671-current-ticket-assignment-and-ticket-assignment-with-policy-framework |
| ServiceNow, HR Playbook | Single-page view of steps/tasks for human case work; informs milestone panel/ticket detail. https://www.servicenow.com/docs/r/employee-service-management/agent-workspace-for-hr-case-management/playbook-hr.html |
| ServiceNow, Advanced Work Assignment | Skill, workload, availability-aware assignment; candidate enhancement for later release. https://www.servicenow.com/docs/r/employee-service-management/hr-service-delivery/hr-awa.html |
| Camunda 8 user tasks | Candidate groups, task lifecycle, claim semantics, parallel process concepts; informs but does **not** require Camunda dependency. https://docs.camunda.io/docs/8.9/components/tasklist/userguide/using-tasklist/ |
| PostgreSQL SELECT documentation | Row-level locks and SKIP LOCKED for queue workers; on user claim use row lock + predicate re-evaluation (NOT SKIP LOCKED). https://www.postgresql.org/docs/current/sql-select.html |
| OWASP API Security Top 10 (2023) | Broken object- and function-level authorization threats; enforce auth at all scoped resources. https://api-security.owasp.org/editions/2023/en/0x11-t10/ |
| W3C WCAG 2.2 | Keyboard/focus/hover/status message requirements; informs stage popover and wizard accessibility. https://www.w3.org/TR/wcag/ |
| OpenTelemetry | Request-level traces and service observability; informs run diagnostics. https://opentelemetry.io/docs/concepts/observability-primer/ |

## Distinguishing JSC requirements from benchmarks
The fundamental Secretariat/location nuance, position-based candidate pools, Department X category-A referral, multi-department CC, and four levels of reporting originated from the JSC Ticketing brief. Service catalog, knowledge base, rule logs, SLA sophistication, task joins, privacy/record integrity and operational quality controls are extensions from the research and implementation practice.
