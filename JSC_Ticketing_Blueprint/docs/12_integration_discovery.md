# 12 — Local source integration reconnaissance checklist

## IMPORTANT: not inspected here
The source folders are local Windows drives not included with the uploaded ZIP. Actual Nite release/version and HRMS Docker conventions cannot be asserted. Codex must run discovery **in the user workstation or a mounted copy** before writing adapters. Do not copy production .env, credentials or database files into tickets or ChatGPT.

### Nite application (`T:\source\work\jsc_creditors\`)
Record: repo root, README, composer.json/lock, framework folder, autoloader, route declaration signatures, controller methods, request/response wrappers, PDO/ORM transaction APIs, middleware/auth, migrations/seeds, error handling, tests, PHP minimum and code style. Discover updated Nite framework from this source only; do not substitute web-public `nite-install` or assume APIs match.

### HRMS (`T:\source\work\hrms\`)
Record: Compose structure, services, PHP container source/image, volumes, MySQL/PostgreSQL differences, build tools, worker pattern, reverse proxy, env layout, rootless Docker, data directories, SELinux flags, health checks, startup scripts, restart policies, release build. We require **PostgreSQL** for JSC Ticketing, so do not accidentally inherit an incompatible MySQL-specific HRMS data/config pattern.

### Architecture decisions to write into `docs/implementation_findings.md`
- Nite compatibility matrix and adapter method signatures verified from actual code.
- Authentication mechanism + independent national authorization model.
- Angular workspace/build script verified; framework upgrade compatibility and package pinning.
- Docker volumes, ownership/SELinux, backup paths and network ports chosen to avoid collisions.
- Existing JSC directory integration vs stand-alone new repo; whether shared identity tables may be used under approved API boundaries.
- Deployment procedure and staging tests; nonbreaking migration and rollback.

### Minimum initial terminal commands (read-only, adjust to platform)
```powershell
Get-ChildItem 'T:\source\work\jsc_creditors' -Force
Get-ChildItem 'T:\source\work\hrms' -Force
Get-ChildItem 'T:\source\work\jsc_creditors' -Recurse -File -Include composer.json,composer.lock,AGENTS.md,README.md | Select-Object FullName
Get-ChildItem 'T:\source\work\hrms' -Recurse -File -Include 'compose*.yml','docker-compose*.yml','Dockerfile*','.env.example' | Select-Object FullName
```
Do not run destructive commands or print `.env` values. Clone or work on a feature branch and request explicit permission before modifying other apps.
