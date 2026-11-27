# IT service workflow

- Local IT structure and example accounts follow the owner's operational model. They are training records, not an approved JSC staff import or a claim about current official staffing.
- New **Computer faults and IT support** tickets use the published IT workflow. Existing tickets retain their immutable workflow version.

## Normal handling

1. Any authorized requester chooses their current posting, reports the fault, identifies the asset and selects the issue category.
2. The system assigns one eligible primary IT officer at the origin station, choosing the officer with the fewest active claims. The rest of the configured team can inspect the operation. If nobody is eligible, the work stays visible for authorized triage; it is never assigned to an unauthorized person.
3. The officer resolves the issue or uses **Escalate**, giving a reason, to send it to the provincial Principal IT Officer.
4. The principal claims it and resolves it or escalates to the Principal Help Desk.
5. The help desk claims it and chooses **Route to specialist**. The reported issue category routes it to Systems Engineer, Systems Analyst, Hardware Specialist or Database Specialist.
6. **Other** reaches a help-desk dispatch stage. Its claimant uses **Choose destination** to select an approved specialist. Dispatch cannot be completed as a resolved repair.
7. The specialist claims the operation and resolves it. Every assignment, escalation, takeover, redirect and completion remains in the audit history.

## Intervention and oversight

- IT staff and executives can also create and read their own requests through explicit self-service grants.
- At the station stage, the same-province principal may take over. The department head, deputy, help desk and four specialists may intervene across the IT department.
- At provincial/help-desk stages, configured department leaders and specialists may take over. At specialist stages the head and deputy may take over.
- **Take over** requires a reason, a current posting in the stage department, matching intervention geography, and both `task.claim` and `task.intervene` authority. It replaces the prior claimant atomically. Version checks reject stale actions; retries do not duplicate events.
- Position reporting lines describe the organization. The published intervention groups and explicit grants control access. Removing a grant or ending a posting invalidates contextual intervention access immediately.
- Secretary and Deputy Secretary example accounts have explicit national read, sensitive-read, comment, completed-ticket comment, analytics and export grants. They can review and comment at any stage, including closure; this does not give them an IT repair claim.
- Comments can notify up to ten selected personnel. Recipient search includes people with current ticket detail access; restricted and participant-only comments keep their additional checks. Selecting a recipient never grants access.

## Configure visually

1. Sign in as `workflow.designer`; open **Workflows**, search **Computer faults**, then **New version**.
2. Click a flowchart stage to open its inspector. Drag it or use **Alt + arrow keys** to arrange the canvas; **Arrange stages** resets its layout.
3. Choose the primary position, additional positions and/or selected personnel. Search directories before loading more choices. Selected entries remain available when searches change and can be removed with their chips.
4. Choose **Automatically assign** for station work or **Team claim pool** for pooled work. People-only audiences still require current postings and task grants.
5. Add senior intervention groups. Each group can contain several positions and personnel with station, province, department or national geography.
6. Set escalation and manual redirect destinations, or select a stage, click **Connect selected stage**, and click its destination. Escalation/redirect choices create visible connections. For a general conditional route, finish the rule in **Routing**. Multiple exits require conditions and exactly one default. Decision conditions use the captured intake fields.
7. Configure deadlines and business calendar, save and simulate. Sign in as `workflow.publisher` to review and publish. National stages or national intervention groups always require an independent publisher.
- Publication checks directory records, currently authorized primary candidates, route destinations, bounded return paths, cycles and parallel-branch boundaries. A draft can be saved while incomplete; simulation/publication provide the validation errors.
- A ticket's **Full route and current stages** diagram shows its own published version, including possible future stages and the executed stages' current states.

## Analytics

- Head/deputy IT example accounts see department-scoped charts. Secretary and Deputy Secretary see their explicitly granted national scope.
- **Analytics** contains 18 charts: status, priority, department, station, province, category, backlog age, resolution outcomes, current SLA exposure, escalation incidence, interventions, reopened work, monthly arrivals, average completion hours, current primary owners, current primary stages, monthly closures and weekly arrivals.
- Filter by status, priority, department, province or station. Status and priority bars open matching tickets. Every query applies both current read and analytics grants; filtering cannot widen authority.
- Group charts show at most ten groups, as labelled. Current owner/stage charts count a parallel ticket once using its earliest active task. Completion times use calendar hours for the current completed run. Monthly/weekly charts show periods with data; they are not an all-period historical SLA reconstruction.

## Local setup and accounts

```powershell
./scripts/run-environment.ps1 -Environment development -Build
./scripts/setup-it-workflow.ps1 -Environment development
./scripts/run-environment.ps1 -Environment offline
./scripts/setup-it-workflow.ps1 -Environment offline
```

- Prerequisite: the JSC sample structure from `scripts/setup-jsc-samples.ps1`.
- The IT installer creates nine worked tickets across the station, province, help desk, four specialists, manual dispatch and resolved stages, plus 38 accounts: 19 station officers, 10 provincial principals, seven department personnel and two executives. Setup is guarded against production and serialized; re-running it preserves credentials and does not republish or duplicate accounts.
- Example logins: `it.officer.mag_harare`, `it.principal.harare`, `it.helpdesk`, `it.hardware_specialist`, `it.head`, `it.deputy`, `secretary`, `deputy.secretary`. Other specialist names are `it.systems_engineer`, `it.systems_analyst` and `it.database_specialist`.
- Initial passwords are stored privately in `secrets/offline/it_passwords.json` or `secrets/development/it_passwords.json`; change is required at first sign-in. These files stay outside Git. Existing accounts and chosen passwords are preserved.
- Offline UI: `http://localhost:8187`. Development UI: `http://localhost:8180`. A push to the production branch is source delivery, not a verified live deployment.

- Password throttling counts failed primary attempts: valid sign-ins refund their reservations, so shared workstation/proxy traffic does not accumulate a lockout from successful staff logins. Existing failures remain counted. Account failure, IP failure, MFA start and factor verification budgets remain enforced. Login rate-limit responses advertise a 15-minute retry window.
