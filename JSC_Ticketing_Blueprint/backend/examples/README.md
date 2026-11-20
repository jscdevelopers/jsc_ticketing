# PHP/Nite integration examples

`TicketClaimService.php` demonstrates transaction boundaries for the important 'multiple candidates, one claim' use case. It relies on **PDO** rather than inventing Nite method names. It is intentionally not an HTTP controller; connect it to the verified Nite router, auth middleware, exception mapper and database adapter after source inspection. Its `authorize` Closure must check live actor grants, assignment validity and ticket confidentiality; simply passing `fn()=>true` is insecure.

Suggested HTTP error mapping: `TicketAlreadyClaimed` / `TicketClaimConflict` → 409, `TicketClaimForbidden` → 403, validation error → 422, unknown task return generic 404/409 according to the approved anti-enumeration policy.

Production additions: authenticated request identity, payload limit, request audit log, claim cancel/release flow, serializable-adverse tests and DB timeouts; transaction retry on PostgreSQL 40P01/40001 only when the entire action remains idempotent. Ensure background outbox worker marks delivery and retries without repeating business state changes.
