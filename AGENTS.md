# Project working agreement

- Preserve existing work and configuration. Explain changes and validation in concise bullets.
- Implement complete useful slices using the established PHP/Nite, PostgreSQL and Angular design.
- After relevant checks, commit and push completed changes to both `origin/main` and `origin/production`, as requested by the owner. Use fast-forward updates; never force-push or discard divergent branch work.
- Track `.env.offline`, `.env.development` and `.env.production` as configuration. Store actual passwords/tokens in ignored secret files; do not commit private credentials or overwrite existing secret files.
- Keep local runtime/deployment claims precise. Pushing the production branch does not mean a live deployment has been verified.
