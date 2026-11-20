# Ticket intake and department triage

Authenticated staff can submit an internal ticket from an effective posting into the category department's manual triage queue. Tickets remain `submitted`; claim tasks, approvals, graph continuation, SLA timers and notification delivery are future work. Unsupported graph engines fail closed.

## Configure and authorize

Import the approved directory first. From `backend/`, run:

```powershell
php bin/console.php catalog:configure C:/private/category.json 'Approved service catalog configuration'
php bin/console.php scope:grant-submit USER_UUID 'Approved staff submission access'
php bin/console.php token:issue USER_UUID 8
```

Configuration example uses illustrative UUIDs: replace both with approved identifiers. The department must exist and be active. These are the exact accepted top-level keys:

```json
{
  "category_id": "d157dbf0-95dd-4ad2-8775-9dfd0f540f60",
  "department_id": "a3fafdf7-e49b-473f-a567-126d266b09f1",
  "code": "EQUIPMENT",
  "name": "Equipment request",
  "form_schema": {
    "type": "object",
    "additionalProperties": false,
    "required": ["asset_tag"],
    "properties": {
      "asset_tag": {"type": "string", "title": "Asset tag", "minLength": 2, "maxLength": 30},
      "seats": {"type": "integer", "minimum": 1, "maximum": 20},
      "service": {"type": "string", "enum": ["repair", "replace"]},
      "urgent": {"type": "boolean", "title": "Work stopped"}
    }
  }
}
```

- Configuration atomically updates the category and activates an immutable version with engine `department-triage-v1`. The definition snapshots category code/name, department and form schema. Matching retries reuse the version. Changes publish a new version; existing tickets retain their original one. Existing graph templates cannot be overwritten through this command. Multiple templates require explicit reconciliation. Reconfiguration preserves an inactive category's state.
- Schema support is deliberately bounded: at most 30 flat fields; string/integer/number/boolean types; optional short titles; string/numeric enums with up to 50 values; numeric minimum/maximum; string minLength/maxLength up to 4000 Unicode characters. Required IDs must exist. Unknown keywords, nested schemas, arbitrary regex/code and remote references are rejected. `additionalProperties` must be false.
- Submit provisioning atomically adds a requester role and independent `ticket.create/self` and `ticket.read/self` grants. Retries are idempotent. It grants no national, sensitive, administrative, approval or claim rights. Revoke individual grant IDs through `revoke grant`. Create authority without current self-read authority is insufficient.
- These are trusted console operations, audited as `actor_system=console`; named administrator sessions and approval workflows remain future work.

## Discover and submit

Use `Authorization: Bearer TOKEN`. `GET /api/v1/me/assignments?q=&after=` searches your own current postings by station/department/position. `GET /api/v1/ticket-categories?q=&after=` searches supported, active categories by code/name and returns the current form schema. Both require submission/self-read authority, return at most ten rows, and expose `meta.next_after`. Reset pagination when changing search. Alternate actor IDs or unbounded limits are rejected.

```http
POST /api/v1/tickets
Content-Type: application/json
Authorization: Bearer TOKEN
Idempotency-Key: workstation-2026-0001

{
  "category_id": "d157dbf0-95dd-4ad2-8775-9dfd0f540f60",
  "assignment_id": "0dd3cd87-d779-4ddc-a2d0-ff88d8eb3311",
  "subject": "Replace station printer cartridge",
  "description": "The station printer needs a replacement cartridge.",
  "priority": "normal",
  "fields": {"asset_tag": "PR-42", "service": "replace", "seats": 2, "urgent": false}
}
```

- The backend obtains requester identity from the token, verifies the posting belongs to that requester and is effective with active organizational records, and snapshots assignment/position/station/province/department IDs plus labels and requester display name. Posting changes never rewrite the ticket's origin. The category determines responsible department and active published version. Caller identity, origin, department, workflow, confidentiality and CC overrides are rejected.
- Subject: 3..255 characters; description: 1..10000, after trimming. Priority: low/normal/high/critical, default normal. Fields must be a JSON object with correctly typed values; numeric/boolean strings are not coerced. Whole HTTP body: at most 64 KiB; encoded custom fields: 32000 bytes. Uploads, CC, drafts and sensitive labels are not supported yet; this profile creates `internal` tickets.
- Fresh submissions and committed replays return HTTP 201 and `Location`, with stable ticket ID/reference, responsible department, workflow version, `submitted` status and version 1. `meta.replayed` distinguishes retries. Retain the same key and payload after a timeout. Object key order is ignored. Changed payload under the same actor/key returns 409. Keys are 1..128 letters/digits/dots/colons/underscores/hyphens, scoped to actor and `ticket.submit`. Failures reserve no key.
- Ticket, `TICKET_SUBMITTED` event, minimal notification outbox job and saved idempotency response commit together. Concurrent retries return one ticket. The event carries `X-Request-ID`; outbox payloads contain identifiers, without subject/narrative. Delivery remains unimplemented.
- At most ten new tickets per actor per rolling hour; an actor row lock makes this atomic. Replays do not consume allowance and work at the limit. HTTP 429 includes conservative `Retry-After: 3600`. Replays require current submission/self-read authority and ticket visibility, but not an effective historical posting.

## Read, recover and upgrade

`GET /api/v1/tickets/{id}` includes origin IDs and `requester_snapshot`. The timeline endpoint returns up to ten chronological internal events, accepts a numeric event ID in `after`, and exposes `meta.next_after`. Restricted/unsupported event visibility is omitted. Both detail and timeline enforce the same ticket ACL and return identical 404s for absent and unauthorized IDs.

| Status | Meaning |
| --- | --- |
| 401 / 403 | Invalid credential / missing authority or unavailable posting |
| 409 | Key reused for different content |
| 415 / 422 | JSON required / invalid submission, filter or unavailable category configuration |
| 429 | Hourly new-ticket allowance exhausted |
| 503 | Temporary service failure or inconsistent/unsupported intake route |

Inconsistent/unsupported routes record `INTAKE_CONFIGURATION_INVALID` in the security audit stream after rolling back submission. Operators must inspect this stream; no administrator email/push delivery is claimed. A missing active published configuration returns 422 with no ticket. Never choose an arbitrary department as fallback.

Apply additive migration `003_intake.sql` through the normal migration command. It adds nullable origin references, an empty snapshot default and requester permissions/role; it grants no existing user access. The rate check uses the existing requester/creation-time index. Old tickets retain null new origin IDs and `{}` snapshots, without fabricated history. An application rollback can retain this schema; do not delete ticket data. Readiness checks the intake columns and identity table.

Remaining: Angular screens, approved SSO, named administrator workflows, privacy/CC/attachments, candidate resolution and atomic claims, graph execution, approvals, notification delivery and staging deployment. This version profile is a department triage configuration, not completion of the workflow wizard/engine.
