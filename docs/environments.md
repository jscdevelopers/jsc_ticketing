# Environment profiles and branch publishing

The repository tracks `.env.offline`, `.env.development` and `.env.production`. They contain environment settings and private secret-file paths. Passwords/tokens remain in ignored `secrets/` files; no actual credential is needed in Git. Existing `.env.example` local edits are preserved separately.

| Profile | Local API port | Data isolation | Runtime |
| --- | --- | --- | --- |
| development | 8186 | `jsc-ticketing-development` volume/network | Local development image |
| offline | 8187 | `jsc-ticketing-offline` volume/network | Cached API and Nginx images; API/database network blocks external routing |
| production | 8188 | `jsc-ticketing-production` volume/network | Requires a separately approved production image and provisioned secret |

All published ports bind to loopback. PostgreSQL has no published port. Networks use distinct explicit subnets to avoid the workstation's exhausted default Docker pools; choose approved unused ranges on other machines. Configuration does not establish a production domain or authorize deployment to a server.

```powershell
# Validate any profile without creating a password, container or volume.
./scripts/run-environment.ps1 -Environment development -ValidateOnly
./scripts/run-environment.ps1 -Environment offline -ValidateOnly
./scripts/run-environment.ps1 -Environment production -ValidateOnly

# Build, migrate, start and verify development. Leaves services running.
./scripts/run-environment.ps1 -Environment development -Build

# Cache the fixed HTTP gateway while online, then start without build/pull operations.
docker pull nginx:1.28-alpine
./scripts/run-environment.ps1 -Environment offline

# Stop one environment, retaining its database volume and private secret.
./scripts/run-environment.ps1 -Environment development -Stop
./scripts/run-environment.ps1 -Environment offline -Stop
```

New development/offline secrets are generated locally with a cryptographic RNG, never printed, and never overwritten. Preserve the secret with its database volume; replacing only the file does not rotate PostgreSQL's stored password. Rotation requires an approved database role password change and coordinated application update. Protect and back up private files separately from Git. Compose mounts each password as a secret file into PostgreSQL and PHP; PHP already supports `DB_PASSWORD_FILE`.

Offline means the API and database run on an internal Docker network without external routing. A non-root Nginx gateway on a separate network exposes only a fixed HTTP upstream to the API on localhost; it is not a forward proxy. This avoids Docker Desktop's inability to expose published ports directly from internal networks. An uncached machine still needs online image preparation first. No image build or pull runs during offline startup. SSO, message delivery and other live integrations remain unimplemented. Development/offline continue to use console-provisioned bearer tokens. The offline override uses Compose's `!reset` syntax; use Docker Compose 2.24.4 or newer.

The production profile is configuration preparation, not a production deployment. The current development Dockerfile/server must not be relabeled as a production release. Prepare an approved image with the production HTTP runtime, compatible migration command/health route, TLS/proxy, least-privilege database access, secret provisioning and backup/restore checks before using that profile. The runner rejects production builds from the development Dockerfile and does not generate production credentials.

Completed changes are committed and pushed to both `origin/main` and `origin/production` under the owner's standing instruction. Pushes use fast-forward updates and preserve remote work. A production-branch push does not itself verify deployment.
