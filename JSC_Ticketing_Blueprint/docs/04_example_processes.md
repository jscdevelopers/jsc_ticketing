# 04 — Illustrative JSC business processes

These are **examples for stakeholder validation**, not claims about JSC's approved procedures.

## A. General ICT issue (local then provincial)
Any staff member → category `ICT / Computer or network` → station ICT officer pool (all eligible officers) → first claims → diagnosis → [repair locally | request provincial ICT | create procurement/asset subtask] → requester confirms → close. CC station head optional. Escalation after no claim for 4 working hours. The provincial ICT team may be outside Secretariat while nationally authorized ICT reviewers may be at any station.

## B. Category A referral to department X
Any staff member → category = A → automatically route to Department X according to configured level (`station`, `province`, `national`, named station), offer task to all active holders of Position P in that target department/scope → exactly one claims and acts. Default route for other categories to their configured owner; unknown category must fail validation, not invent a destination. Optional CC Department Y for observation only.

## C. Procurement with parallel review
Requester at any station → Facilities intake → Finance budget review **AND** Procurement specifications review (independent task instances) → join `ALL` → Provincial/Secretariat authorized approver selected by amount policy → fulfillment → acknowledgement → close. Payment or procurement actions must be external system integrations with explicit approvals, not automatic just because a ticket reached a node.

## D. HR document request with privacy
Staff submits sensitive request → HR staff pool at permitted level → claimed by HR user → confidential document work → optional Legal approval (separate restricted task) → verified delivery to requester → close. No generic department CC or public PDF download.

## E. Escalated national operational report
Station clerk → station reviewer → provincial coordinator → nationally granted oversight officer (can be posted outside Secretariat) → decision and downstream response → closure. National dashboard shows allowed aggregate; station staff see only their permitted records.

## F. Complaint with delegated actor
Person files ticket → departmental triage → branch by severity → approvers may delegate under validated dates; sensitive evidence restricted; full attribution for delegates and original approver; resolution follows retention/legal-hold policy.

## Acceptance scenario
If 5 ICT Officers at Station S are candidates for the same task, the task has **five offers but one instance**. If Officer 3 claims first, all five see its claimant and only Officer 3 performs protected work unless a proper release/reassign occurs. If Finance and Legal approvals are required, the workflow spawns two instances and `ALL` completion must wait for both.
