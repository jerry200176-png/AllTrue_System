# Minimal Founder Runbook — #1387 DB Password Rotation

> **P0 note:** this in-place procedure is retained as an alternative, but the
> Founder selected the separately named, three-phase principal rotation in
> [`1387-staged-rotation-runbook.md`](1387-staged-rotation-runbook.md) for
> SEC-ALLTRUE-003. Do not mix the two paths; this workflow now refuses to run
> while staged-rotation state exists.

**Purpose**: rotate the production MySQL password after PR #1414's fingerprint
audit confirmed the pre-fix leaked value still matches production's live
credential (`MATCH_ROTATION_REQUIRED`).

**No credential is ever typed by you.** The new password is generated,
applied, and discarded entirely on the production host inside the workflow
run — it never appears in this repo, in chat, in Claude's context, or in any
GitHub Actions log.

## Pre-flight (already done, evidence below — you don't need to redo this)

- Every credential consumer identified: the Laravel app (reads `DB_PASSWORD`
  from `/home/admin/backend/.env`) and CI workflows that read the same `.env`
  fresh at run time (no hardcoded secondary copies exist anywhere).
- Fresh backup-restore verification passed 2026-07-25 (run `30178339123`):
  latest 6-hour backup restored cleanly, all core tables had plausible row
  counts, test DB cleaned up.
- The rotation workflow itself takes one more backup immediately before
  rotating, in addition to the existing 6-hour cron.

## The one action required of you

1. Open this PR (once reviewed) and merge it to `main` — **or** trigger the
   workflow directly from this branch via `gh workflow run` if you want to
   test it before merging (`workflow_dispatch` works from any branch that
   has the file).
2. Run:
   ```
   gh workflow run 1387-db-password-rotation.yml --repo jerry200176-png/AllTrue_System --ref main -f confirm=ROTATE
   ```
   (The `confirm=ROTATE` input is a deliberate typed-confirmation gate —
   the workflow refuses to run without it.)
3. Watch the run complete. It will fail loudly (health check step) if
   anything goes wrong, before declaring success.

## What happens automatically, in order

1. Confirms your `ROTATE` input.
2. SSHes to the Pi (existing pinned host key, same as every other audit
   workflow this session).
3. Takes a fresh `mysqldump` backup to `/home/admin/backups/pre-rotation/`.
4. Generates a new random password with `openssl rand`, entirely inside
   that one remote shell session.
5. Runs `ALTER USER ... IDENTIFIED BY '<new>'; FLUSH PRIVILEGES;` — MySQL's
   native-auth model makes this atomic: the instant it completes, the old
   password stops authenticating for any new connection. There is no dual-
   validity window to separately test.
6. Writes the new value into `.env` (after backing up the old `.env` file
   next to the pre-rotation SQL dump).
7. Verifies the NEW credential authenticates (`mysqladmin ping`) — if this
   fails, the workflow fails here, before touching the app.
8. Runs `php artisan config:cache` (per `docs/OPERATIONS_RUNBOOK.md` §O.2 —
   never `config:clear` for this).
9. Polls `/api/v1/health` until it returns `ok`, and confirms `/version.json`
   responds — if health doesn't recover, the workflow fails loudly.

## If something goes wrong

- The workflow's own health-check gate fails the run before declaring
  success — a red run means investigate, not "silently broken in prod."
- Rollback: restore `.env` from the `env_pre_rotation_*.bak` file the
  workflow wrote next to the SQL backup, restart PHP-FPM, and if the DB
  itself needs restoring, use the fresh `alltrue_pre_rotation_*.sql.gz` this
  same run created (in addition to the existing 6-hour cron backups).

## After a successful run

Tell me (or re-invoke this session) to trigger the existing fingerprint
audit (`db-password-fingerprint-audit.yml`) once more — a `DIFFERENT` result
(not `MATCH_ROTATION_REQUIRED`) is the final, independent confirmation that
rotation actually took effect in production, closing the last open #1387
exit criterion.
