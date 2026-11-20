# Validation status — what was and was not tested

## Checked in the packaging environment
- Example workflow validates against JSON Schema Draft 2020-12.
- Twelve unit tests of example graph structure, route branch, duplicate IDs, cycle detection, missing fallback, injection rejection, and the Secretariat/national sample distinction pass.
- PHP example passes `php -l` syntax validation.
- HTML prototype's inline JavaScript passes Node.js `node --check` syntax validation.
- SVG files are well-formed XML and rendered to viewable PNG.
- All assets and documents were bundled to ZIP with readable central directory.

## NOT checked / NOT production-ready
- Windows `T:\source\work\jsc_creditors\` and `T:\source\work\hrms\` were not accessible or inspected.
- No PostgreSQL instance was available here; SQL migration was reviewed but not executed on a live PostgreSQL server.
- No real Nite application, Angular workspace, CI pipeline, identity provider, Docker image or runtime tests were available.
- Playwright browser navigation was blocked by the execution environment's browser policy; HTML UI interaction was statically checked, not browser-E2E verified here.
- The `deploy/compose.example.yml` intentionally references an absent API Dockerfile pending repository inspection.
- Ticket claim service requires real Nite auth adapter, database migrations, and transaction/concurrency integration tests before release.
- All UI counts/staff/stations are fictional demonstration content, not actual JSC operational data.

This package is a detailed **blueprint, design and code reference**, not a deployable finished system.
