# Bug reporter-verify timeout (manual operational)

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
