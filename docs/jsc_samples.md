# Zimbabwe JSC local operating samples

The local setup uses the public JSC court structure as a reference. It is a representative, configurable operating model, not an approved establishment, exhaustive station directory or assertion of judicial jurisdiction boundaries.

Sources reviewed 23 November 2026:

- [JSC institutional overview](https://www.jsc.org.zw/about/): court system reference.
- [JSC June 2025 publication](https://www.jsc.org.zw/media/uploads/Miranda_June_2025.pdf): Secretariat, Human Resources, Corporate Services and training context.

Verified institutional names and geographic locations are distinguished from application-specific support queues and proposed reporting links. Those links require owner review against an approved internal organogram. No real office-holder identities, employment records, litigants or court cases are imported.

## Install

```powershell
./scripts/run-environment.ps1 -Environment development -Build
./scripts/setup-jsc-samples.ps1 -Environment development
./scripts/run-environment.ps1 -Environment offline
./scripts/setup-jsc-samples.ps1 -Environment offline
```

The dataset contains ten civil geographic provinces, seven court/office levels, nineteen representative stations, fourteen departments, eighteen positions, nine independently published service workflows, thirty-five fictional role accounts and thirty-seven operational requests. Requests include comments and offered, claimed and completed work. Existing real records and historical workflow versions are retained. Earlier generic organization labels are renamed or archived; original immutable identity subjects remain intact.

Court levels distinguish the Secretariat, Constitutional Court, Supreme Court, High Court, Labour Court, Administrative Court and Magistrates Court. Stations link to both court level and geographic province. Departments have editable parent departments; positions have editable reporting positions. Cycles are rejected. Geography is not itself a permission grant. Secretariat location confers no national read rights.

Support workflows cover ICT, HR, Finance, Procurement, Facilities, Legal Services, Training, Sheriff Services and Court Security. They are local administrative service samples, not rules for adjudicating cases. Court officers have fictional role labels rather than invented personal identities.

## Accounts

| Login | Scope |
| --- | --- |
| registry.harare | Own service requests |
| ict.harare | Eligible ICT work |
| station.manager | Harare station management and sensitive clearance |
| workflow.designer | Workflow design |
| workflow.publisher | Workflow publication |
| national.oversight | Explicit national internal read and management; no implicit sensitive clearance |
| configuration.admin | Configuration and security management; no implicit ticket data access |
| registry.<station code> | Own requests from that station |
| officer.<department code> | Eligible service-department work |

Passwords are in ignored `secrets/<environment>/jsc_passwords.json`. Development and offline passwords remain separate. The five previous `*.demo` usernames are renamed in place; their password hashes and password-change state are preserved. Existing initial passwords are copied to the new credential file under the renamed keys, and newly created accounts receive private random temporary passwords. If a user previously changed their password, the file still contains only their initial value; use their chosen password. The existing administrator is unchanged.

The old credential file is preserved as historical local material; its old usernames are no longer valid after conversion. Repeat installation does not reset credentials, duplicate samples, or overwrite subsequent operational changes. A failed sample-request stage can be retried after fixing the reported constraint. Setup is rejected outside explicitly enabled local/test environments. No credentials are committed to Git.
