# Environment profiles and branch publishing

Tracked `.env.development`, `.env.offline` and `.env.production` contain nonsecret settings and secret-file paths. Private passwords/tokens stay in ignored `secrets/`. Existing owner changes to `.env.example` are preserved separately.

| Profile | Browser | API | State |
| --- | --- | --- | --- |
| development | localhost:8180 | localhost:8186 | Built and verified locally |
| offline | localhost:8187 | Internal only | Cached images; isolated backend; verified locally |
| production | localhost:8188 placeholder | localhost:8189 placeholder | Configuration only; approved runtime/integrations required |

All published ports bind to loopback. PostgreSQL has no host port. Each environment has separate database and private-file volumes. Distinct explicit subnets avoid exhausted Docker default pools. The runner safely replaces the earlier project's offline gateway/network; unrelated resources are retained.

```powershell
./scripts/run-environment.ps1 -Environment development -ValidateOnly
./scripts/run-environment.ps1 -Environment offline -ValidateOnly
./scripts/run-environment.ps1 -Environment production -ValidateOnly
./scripts/run-environment.ps1 -Environment development -Build
./scripts/setup-local-demo.ps1 -Environment development
./scripts/run-environment.ps1 -Environment offline
./scripts/setup-local-demo.ps1 -Environment offline
./scripts/run-environment.ps1 -Environment development -Stop
```

Development builds the API and Angular/Nginx images. Offline startup builds/pulls nothing and requires those images plus PostgreSQL cached beforehand. The API/database/worker use an internal network; the fixed-upstream non-root web gateway exposes the UI/API on localhost through a separate network. It is not a forward proxy. Compose 2.24.4+ is required for the offline port reset.

Generated database/demo secrets are never printed, overwritten or committed. Preserve them with the matching database. Replacing a file alone does not rotate the stored database password. Demo setup is explicitly limited to development/offline/test and never creates national ticket-read grants. Password sign-in works locally without a real identity server.

Local file checking is an explicitly labelled dummy integration, enabled only in development/offline. ClamAV configuration and real nAuth are production placeholders. The production runner rejects building the development PHP HTTP server as a production image and does not generate production secrets. Production requires reviewed runtime images, TLS, database least privilege, approved integration registration and recovery validation. No production server is deployed in the current local-only scope.

Verified source is pushed to both origin/main and origin/production under the owner's standing instruction, using fast-forward updates. A production-branch push does not mean a live deployment occurred.
