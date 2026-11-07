# GitHub Environments setup (#875)

> **Not run by CI or by me.** Creating environments and moving secrets are
> repo-admin actions on live GitHub settings and production credentials —
> Founder-only per `portfolio-ops/CLAUDE.md` ("Change secrets, credentials...
> production permissions" requires explicit approval). This is the exact
> command sequence to run yourself; nothing here executes automatically.

## Why (from the issue)

`deploy.yml` currently reads all deploy secrets from repo-level scope — no
GitHub Environments boundary between production and the new staging host
from #868. Environments give you: per-environment secrets, deployment
history in the GitHub UI, and (later, if wanted) required reviewers before
a specific environment can deploy.

## Current repo-level secrets (as of 2026-08-15)

```
PI_HOST, PI_HOST_KEY, PI_SSH_HOST, PI_SSH_KEY, PI_SSH_USER, PI_USER   — production Pi
CI_DB_PASSWORD                                                        — CI only, not deploy
SENTRY_DSN                                                            — shared prod+staging (see #868 doc, deliberate for now)
SMOKE_*                                                                — production smoke test credentials
UPTIMEROBOT_API_KEY                                                   — monitoring, not deploy
```

Staging needs no GitHub secrets: it pulls `main` itself (see [`DAAN_STAGING_V1.md`](../ops/DAAN_STAGING_V1.md)). Ignore any `STAGING_*` repo secrets.

## 1. Create the two environments

```bash
gh api --method PUT repos/jerry200176-png/AllTrue_System/environments/production
gh api --method PUT repos/jerry200176-png/AllTrue_System/environments/staging
```

## 2. Move production deploy secrets into the `production` environment

Environment secrets can't be "moved" via API without knowing the plaintext
value — re-enter each one (same values, new scope):

```bash
gh secret set PI_HOST     --env production --repo jerry200176-png/AllTrue_System
gh secret set PI_HOST_KEY --env production --repo jerry200176-png/AllTrue_System
gh secret set PI_SSH_HOST --env production --repo jerry200176-png/AllTrue_System
gh secret set PI_SSH_KEY  --env production --repo jerry200176-png/AllTrue_System
gh secret set PI_SSH_USER --env production --repo jerry200176-png/AllTrue_System
gh secret set PI_USER     --env production --repo jerry200176-png/AllTrue_System
gh secret set SENTRY_DSN  --env production --repo jerry200176-png/AllTrue_System
gh secret set SMOKE_TEACHER_LOGIN    --env production --repo jerry200176-png/AllTrue_System
gh secret set SMOKE_TEACHER_PASSWORD --env production --repo jerry200176-png/AllTrue_System
gh secret set SMOKE_BASE_URL         --env production --repo jerry200176-png/AllTrue_System
```

(`gh secret set` without `--body` prompts you to paste the value interactively — it does not print existing values, so have them ready from wherever you originally stored them, e.g. your password manager, not from this repo.)

## 3. Staging secrets

None. Staging pulls from public GitHub ([`DAAN_STAGING_V1.md`](../ops/DAAN_STAGING_V1.md)); nothing is pushed to it.

## 4. Wire the workflows to their environment (separate PR, review carefully)

`deploy.yml` and `staging-deploy.yml` need `environment: production` /
`environment: staging` added to their deploy jobs before environment-scoped
secrets actually take effect (until then, the repo-level copies above keep
working as a fallback, so steps 1-3 are safe to do first without an outage).

**Deliberately not done in this PR**: `deploy.yml` is the repo's single
production-execution authority (`CONTROL_PLANE_CONTRACT.md` I1) — a change
to it should be its own small, carefully reviewed PR, not bundled with
docs/setup work. Once you've completed steps 1-3, open that PR separately
and review the diff line by line before merging.

## 5. Once environment secrets are confirmed working, delete the repo-level copies

Only after a successful deploy through the environment-scoped secrets —
don't delete the fallback before confirming the new path works:

```bash
gh secret delete PI_HOST     --repo jerry200176-png/AllTrue_System
gh secret delete PI_HOST_KEY --repo jerry200176-png/AllTrue_System
# ... etc for each secret moved in step 2
```

## Refs

Refs #875.
