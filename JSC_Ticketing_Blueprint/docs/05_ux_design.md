# 05 — Angular interaction and design specification

## Inspiration captured from seven user-supplied photos
- Left-hand dark collapsible sidebar with hierarchical sections and a visibly highlighted active menu item.
- Green horizontal top banner, compact breadcrumb, dense yet readable case lists.
- Ticket/case detail tabs for overview, information and operational activity.
- Horizontal staged progress rail with marked past, current and next states.
- 'My actions' dashboard tiles/queues and case list table.

These photos show a third-party-style case interface; the new design should be **original** rather than a pixel-identical copy. Reference photos may contain blurred text and real system data: treat as confidential and do not publish.

## Information architecture
Global top bar: JSC Ticketing title, site search, scope indicator, alerts, account menu. Sidebar groups: Home/Dashboard; Tickets > New, My Tickets, Offered to Me, My Actions, Team/Department Queues, All Authorized, SLA Monitor; Workflows > Wizard, Templates, Drafts, Versions, Rule Diagnostics; Reports > Personal, Station, Province, Department, National; Administration > Users, Positions, Departments, Provinces, Stations, Grants, Calendars, Audit; Help/Knowledge Base; Settings. Hide links based on privileges but **always** enforce server checks.

## Key pages
1. **Dashboard:** selector for authorized scope, date, department, station, province. KPI tiles: open, claimed, pending, resolved, at risk, breached. Queue tables, rate/aging trends, drilldowns.
2. **Ticket board:** type-ahead, pinned filters, sortable columns, SLA chips, bulk actions only where safe. Separate 'offered', 'claimed by me' and 'CC/watching'.
3. **New ticket:** choose department/category, dynamic form and location-aware routing explanation, attachments, priority and CC. Show 'where it goes next' preview before submission.
4. **Ticket detail:** sticky summary; accessible seven-step progress rail (actual node count varies); step popover on hover/focus/click; tabs Timeline, Notes, Attachments, Related, Workflow, Audit; right-hand contextual action panel; sensitivity banner.
5. **Workflow wizard:** 7-step setup, canvas/step list fallback for keyboard users, node palette, side inspector, simulation trace, publish validation/errors.
6. **Org admin:** province/station/dept/position directory, assignment history and grant explorer with explicit Secretariat separation.
7. **Reports:** scope-driven counts and drilldowns, CSV/PDF export, schedule and audit.

## Interaction rules
- Click stage → permanent details pane; hover/focus → short popover that stays visible and keyboard reachable; Escape closes, focus restored.
- Action panel requires confirmation for rejection/escalation; forced justification on reassignment and sensitive escalation.
- Optimistic updates only for safe UI state; server returns authoritative result. Claim race → announce 'Already claimed by Jane' and refresh, never silently overwrite.
- Distinguish `awaiting requester`, `awaiting another department`, `claimable`, `claimed`, `in review`, `resolved`, `closed`.
- Show event timestamps in local timezone with canonical UTC details on demand.
- Provide print-ready ticket summary and controlled export; do not expose confidential notes in screenshots or previews.

## Palette and typography
Primary deep green `#064B36`, sidebar charcoal `#172123`, accent amber `#F3AB23`, light background `#F5F7F5`, foreground `#17302A`, border `#DCE5DF`, success `#168554`, danger `#BE3C3C`, info `#16658F`. Color is never the only status signal: pair labels and icons. Modern system sans typography with 14–16px body on desktop and comfortable touch sizes; support WCAG 2.2 AA contrast, responsive zoom, 320px screens and reduced motion.

## Data realism
All concept boards and the HTML prototype use **fictional names/numbers and neutral locations**. Screens are illustrative, not a deployment screenshot. The Angular implementation must fetch role/scoped data from backend; no hardcoded sample statistics.

## Prototype
Open `designs/prototype/index.html`. It demonstrates sidebar navigation, multiple dashboard lenses, clickable ticket rows, ticket progress stage popovers and wizard tabs with simulated routing. It stores no data and makes no real API requests.
