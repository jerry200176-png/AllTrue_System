# Bug reporter-verify timeout (manual operational)

> 2026-10-08 (Founder 2A): `bug-reporter-timeout.yml` also runs daily with `--auto` (resolved queue, 14 days, max 20 per run, plain public reply). The manual dry-run/apply path below is unchanged.

**Status:** Manual operational capability — **not** fully automated.  
**Owner:** Founder / CTO Agent  
**Cadence:** Weekly dry-run; apply only after reviewing output  
**Command:** `php artisan bugs:close-stale-resolved [--dry-run] [--days=7] [--actor=USER_ID] [--reviewed-ids=ID,ID]`
**Policy:** [`docs/governance/EVIDENCE_CONTRACT.md`](../governance/EVIDENCE_CONTRACT.md)

## Semantics

| Event | Result |
|-------|--------|
| Reporter confirms | `closed` via `reporter-verify` (`closed_by_reporter` in product UX) |
| 7 days after verified resolve and public staff ask-to-retest, no reporter reply since resolve, no regression signal | Eligible for individually reviewed `closed_by_timeout` |
| Reporter replied after resolve, including same-second timestamp | **Excluded** from timeout; investigate the reply |
| No recent public staff ask-to-retest, or ask newer than 7 days | **Excluded** from timeout |
| `triaged`, latest public comment is staff's and >= 14 days old, no reporter reply after it (internal notes ignored) | Eligible (same `--reviewed-ids` gate, listed as `[awaiting_reporter]`): public comment `超過 14 天沒收到回覆，先結案。直接在這裡回覆就會重開。` then `closed` with note `closed_by_timeout — awaiting reporter reply 14 days` |
| Reporter adds a public comment on a `closed_by_timeout` bug | Reopened to `triaged` (note `reopened_by_reporter_reply`); staff/super_admin comments and reporter-verified closes never reopen |
| Reporter says still broken | `in_progress` via reporter-verify |
| Resolved without `[resolution_evidence]` **or** valid append-only production evidence | **Excluded** from timeout (do not auto-close) |

## Dry-run / apply

```bash
cd backend
php artisan bugs:close-stale-resolved --dry-run
php artisan bugs:close-stale-resolved --actor=<super_admin_user_id> --reviewed-ids=<individually_reviewed_ids>
```

The dry-run is a **candidate list, not approval to close**. For each ID, read the
public thread and related issues, check for later regression signals, and only
then include it in `--reviewed-ids`. The apply command refuses missing, duplicate,
or ineligible IDs before changing any status; unlisted candidates stay resolved.
The command and service enforce a minimum of seven calendar days even if a caller
passes a lower `--days` value; the command rejects malformed or shorter values.
The machine recognizes a public staff retest request near the latest resolve;
ambiguous free text is excluded rather than assumed to be a request. Neither
the machine check nor a seven-day wait proves reporter acceptance.

Idempotent: a service re-run yields `already_closed_by_timeout`. Does not delete comments or rewrite history.

## Scheduler

Not enabled in `Kernel` by default. Enabling requires Founder approval + failure alert path.

## Failure / retry

- Command exits non-zero if no actor user.
- Per-bug failures are skipped and logged (`bug_closed_by_timeout` / `bugs_close_stale_resolved`).
- Safe to retry (idempotent).
