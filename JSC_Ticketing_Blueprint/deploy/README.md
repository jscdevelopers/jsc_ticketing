# Docker deployment design — NOT RUNNABLE YET

The `api` service intentionally points to an **absent Dockerfile**. The user's specific Nite framework and JSC HRMS Docker implementation must be inspected before creating it. Do not try `docker compose up` expecting a working application. This prevents accidentally pretending a production-grade system exists. Do not bind PGDATA to a root-squashed directory before testing permissions. No exposed database port. Use a dedicated, protected volume, secret file, backups, health checks, and adopt verified project conventions.
