# Staging environment (issue #868)

## What this is

A second copy of the app on a **dedicated staging host** — so `main` gets
tested against something before real users see it.

Deliberately **not** the production Pi: `CONTROL_PLANE_CONTRACT.md` I1 says
only `deploy.yml` (or POP Executor) may execute changes on the production
box. An earlier attempt at a second SSH-based deploy path on the same Pi was
archived (`docs/archive/control-plane-shadow-v1/`) precisely because two
execution paths onto one box caused confusion. Reopening that would need a
formal `[contract-change]` PR against I1–I5. A separate host sidesteps the
boundary entirely instead of reopening it.

| | Production | Staging |
|---|---|---|
| Host | Pi (`/home/admin`) | dedicated staging host (`/home/staging/AllTrue_System`) |
| SSH key | `PI_SSH_KEY` | `STAGING_SSH_KEY` (separate key, separate host) |
| DB | `AllTrue` | `AllTrue_staging` |
| Deploy trigger | CI green on `main` | CI green on `main` (same commit, independent workflow) |
| Auto-rollback | Yes | No — a broken staging doesn't hurt real users, left broken for inspection |

## One-time setup (do this once, manually)

1. Provision a small Ubuntu/Debian VPS (or a second Pi). Install PHP 8.2,
   nginx, MySQL 8, composer, node/npm — match the Pi's stack
   (`docs/OPERATIONS_RUNBOOK.md`).
2. SSH in and run `bash scripts/infra/setup-staging-env.sh <repo-url>` —
   creates the staging checkout, MySQL database + scoped user, and the
   nginx vhost.
3. Follow the script's printed next steps: generate a dedicated
   `STAGING_SSH_KEY` (never reuse `PI_SSH_KEY`), add the five
   `STAGING_*` secrets to the repo, and hand-write
   `/home/staging/AllTrue_System/backend/.env`.

After that, `.github/workflows/staging-deploy.yml` deploys every green
`main` automatically.

## Not done yet (deliberately out of scope for the first pass)

- **Prod deploy is not gated on staging passing.** They run independently
  off the same CI-green signal. Wiring `deploy.yml` to require staging
  health first would itself touch I1's execution-authority boundary and
  needs its own `[contract-change]` review — do that once staging has run
  clean for a couple of weeks, not in the same PR that stands staging up.
- **No authenticated smoke test on staging** — production's
  `post-merge-smoke.sh` needs real test credentials tied to production
  data. Health-check only for now.
- **Same Sentry DSN as production** for now; separate later if noise
  becomes a problem.
