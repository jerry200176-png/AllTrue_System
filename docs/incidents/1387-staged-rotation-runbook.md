# Founder Runbook — #1387 Staged MySQL Principal Rotation

**Purpose:** contain SEC-ALLTRUE-003 by moving production to a separately named MySQL principal, observing both principals in parallel, and only then disabling new logins for the compromised principal.

This is now the only supported P0 rotation path. The legacy in-place `rotate` mode in `1387-db-password-rotation.yml` is retired after the 2026-08-22 outage and fails closed without touching production. The same workflow retains an explicitly gated `repair-principal` mode (`REPAIR_DB_PRINCIPAL`) for an already-known mismatch. Never mix the paths.

## 2026-08-22 incident record

Production returned HTTP 500 because the application credential and the actual
MySQL grant identity were out of sync. The error named `admin@localhost`, but a
root read-only inventory showed the only row was `admin@%`; attempting to alter
the nonexistent `admin@localhost` row returned MySQL error 1396. An authorized
operator aligned the existing `admin@%` row to the protected `.env` value, then
verified a fresh TCP `SELECT 1`, rebuilt Laravel config, reloaded PHP-FPM, and
verified `/api/v1/branches` returned HTTP 200. No password value belongs in this
runbook, logs, tickets, or chat.

The permanent rule is: resolve and verify the exact `User@Host` row before any
credential mutation. A health endpoint alone is insufficient; every rotation
must prove a fresh Laravel DB connection and a DB-dependent HTTP read.

No credential is entered in GitHub or printed. Both generated credentials remain in MySQL and the Pi-only, mode-`600` state file:

```text
/home/admin/backups/pre-rotation/1387-staged/rotation-state.env
```

Treat that file and every `.env` backup as secret material. Do not display, download, attach, or paste them. Keep the state until the old account is retired after the rollback window.

## Actual application topology covered

- Laravel has one configuration choke point, `backend/config/database.php`; no other PHP file reads `DB_PASSWORD` directly.
- There is no Laravel Horizon package, `config/queue.php`, persistent queue worker, or queue daemon to drain/restart (the separate ADR-006 “session horizon” commands are ordinary Artisan commands, not a worker runtime).
- Cron invokes `schedule:run` as a fresh PHP process. Phase 2 still rebuilds Laravel's shared config cache before checking the scheduler graph.
- PHP-FPM is the only evidenced long-running app process; reload failure is a hard cutover failure.
- Seven workflows and three scripts previously paired the fresh `.env` password with hard-coded `admin`; this change makes them read `DB_USERNAME` from the same file.

Current inventory: `grep -rl DB_PASSWORD --include="*.yml" .github/workflows/` (15 files) and `grep -rl DB_PASSWORD --include="*.sh" scripts/` (10 files). `ci.yml`, `migration-dryrun.yml`, `local-dev-setup.sh`, `phpunit-isolated.sh` use isolated fixtures; the fingerprint-audit and secret-rotation-reminder workflows only inspect/report. The remaining production-oriented consumers read the Pi `.env` at execution time — 7 of them previously paired the fresh password with a hard-coded `admin` username, now fixed to read `DB_USERNAME`/`DB_PASSWORD` as one tuple (`1387-db-password-rotation.yml`, `173-lr-merge-repair.yml`, `173-supersede-repair.yml`, `classsession-duplicate-diagnose-push.yml`, `ops-leave-cascade-repair.yml`, `slow-query-report.yml`, `teacher-signin-recovery.yml`, plus scripts `diagnose-classsession-duplicates.sh`, `diagnose-student-session.sh`, `post-merge-smoke.sh`).

Repository inspection cannot prove the absence of manual Pi jobs or external integrations. Before Phase 2, confirm no out-of-repository consumer stores the username separately.

## Workflow and phase gates

Open **Actions → Deploy to Pi**, choose `Run workflow`, and run exactly one phase per dispatch. The staged jobs live inside `.github/workflows/deploy.yml`; they are not a standalone workflow. Type the phase-specific phrase:

| Phase | Selection | Confirmation phrase |
|---|---|---|
| 1 | `phase1-create` | `CREATE_NEW_PRINCIPAL` |
| 2 | `phase2-cutover` | `CUTOVER_NEW_PRINCIPAL` |
| 3 | `phase3-lock` | `LOCK_OLD_PRINCIPAL` |

All phases share the non-cancelling `production-deploy` concurrency group with automatic deploys and the guarded repair workflow. A dispatch run never enters `detect-deployable` or `deploy`; Phase 3 is never chained from Phase 2.

## Phase 1 — create and verify; old principal and app untouched

The remote job:

1. Authenticates from `.env` and obtains the exact identity with `CURRENT_USER()`; no host suffix is assumed.
2. Runs `SHOW GRANTS FOR CURRENT_USER()` and rewrites only the target; privileges are not guessed.
3. Creates the new account and replays every `GRANT` or partial `REVOKE`; authentication material or an unrecognized target fails closed.
4. Opens a fresh connection as the new account and runs `SELECT 1`.
5. Writes the protected state file only after verification succeeds.

MySQL documents `SHOW GRANTS` as executable statements for duplicating privilege/role assignments. Non-privilege account properties are not copied; the new account receives a host-generated credential.

**Pass:** green run; exact grants replayed, new connection passed, `.env` untouched.

**Rollback:** failure after creation attempts `DROP USER` and removes incomplete state. After a green run, an authorized operator can drop the staged account using protected state, then remove that file. Stop if automatic drop fails.

## Phase 2 — cut over while both principals remain valid

Before dispatching, confirm Phase 1 was reviewed, this change's deploy/version check is green (so Pi scripts contain the username fixes), and no external consumer hard-codes the old username.

The remote job:

1. Re-authenticates as the staged account before changing anything.
2. Takes a fresh `mysqldump` and mode-`600` `.env` backup.
3. Replaces both `DB_USERNAME` and `DB_PASSWORD` in `.env` in one file move.
4. Runs `php artisan config:cache` and reloads `php8.2-fpm`.
5. A fresh Artisan process purges the connection, runs `SELECT CURRENT_USER(), 1`, and requires the staged username—distinct from HTTP health and stale FPM connections.
6. Runs `schedule:list` to prove scheduler boot under new config; it avoids `schedule:run`, which could execute due writes.
7. Polls health/version and records `cutover_verified`; the old account is untouched.

**Pass:** green including `db-read-new-principal=OK`, `scheduler-bootstrap=OK`, FPM reload, health, and version.

**Automatic rollback:** restores that attempt's `.env`, rebuilds cache, and reloads FPM. Rollback failure is an incident; do not proceed.

**Manual rollback after green:** while old login is enabled, restore `OLD_ENV_BACKUP`, run `config:cache`, reload FPM, then verify fresh DB read/health/version. Never copy a value out. Phase 2 is retryable after correction.

## Observation window — do not skip

Keep both accounts valid until the Founder has observed:

- normal web traffic after the PHP-FPM reload;
- at least one real cron-driven `schedule:run` with no DB auth failure;
- a scheduled backup or restore-verification job that reads the current `.env`;
- any expected repair, diagnose, slow-query, or acceptance workflow;
- no correlated application or MySQL authentication errors.

`schedule:list` proves bootability only, not that cron exists or a due task completed; observation must supply that fact.

## Phase 3 — contain old login capability

Dispatch only after a human confirms the observation window is complete.

The job proves `.env` still names the staged principal, then runs `ALTER USER ... ACCOUNT LOCK` for Phase 1's exact `user@host`. Locking blocks new connections but not existing sessions. Only an unsupported-syntax error activates the Pi-only quarantine-password fallback; other errors fail without downgrade.

The job must prove all three facts before success:

- the pre-rotation credential can no longer open a new connection;
- the staged principal can still open a fresh direct connection; and
- a fresh Laravel connection still reports the staged principal and reads the DB.

The old-credential probe is accepted only when the MySQL client returns a known
authentication rejection (`ERROR 1045` for invalid credentials or `ERROR 3118`
for an account-lock response). A network error, server outage, TLS/host error,
permission error, or any unknown client error is **not** evidence of containment;
the phase fails closed. If the phase used `ACCOUNT LOCK`, it unlocks that account
again before failing. If it used password quarantine, it leaves the quarantine
state for Founder-led recovery instead of restoring the compromised password.

**Rollback after `ACCOUNT LOCK`:** an authorized operator can run `ACCOUNT UNLOCK` through the staged connection, then use Phase 2 rollback if needed. This restores the compromised credential, so it is emergency and time-bounded.

**Fallback rollback:** the quarantined account is unlocked but the leaked credential is invalid. If cutback is unavoidable, rebuild `.env` directly from the protected quarantine value; never restore/reveal the leak.

A red Phase 3 after containment records `containment_pending`; inspect its non-secret `LOCK_METHOD` and use the matching rollback above before retrying.

Do not drop the old account here. Grant revocation, deletion, old-secret retirement, and state removal are a separate Founder-approved action after retention.

## Evidence to retain without secrets

- workflow URL, phase, timestamps, conclusion, and non-secret markers;
- backup filename/size only;
- health/version results;
- timestamp/name/status of the observed cron and backup job;
- lock method (`account_lock` or `password_quarantine`), never its credential;
- a note that end-to-end execution was Founder-only and not tested in review.

Never retain grants output, password/hash/fingerprint, `.env`/state content, DB rows, or PII in logs/notes.
