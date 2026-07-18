# Bug reporter-verify timeout (manual operational)

**Status:** Manual operational capability — **not** fully automated.  
**Owner:** Founder / CTO Agent  
**Cadence:** Weekly dry-run; apply only after reviewing output  
**Command:** `php artisan bugs:close-stale-resolved [--dry-run] [--days=7] [--actor=USER_ID]`  
**Policy:** [`docs/governance/EVIDENCE_CONTRACT.md`](../governance/EVIDENCE_CONTRACT.md)

## Semantics

| Event | Result |
|-------|--------|
| Reporter confirms | `closed` via `reporter-verify` (`closed_by_reporter` in product UX) |
| 7 days, no reply, has `[resolution_evidence]` | `closed` + note `closed_by_timeout` |
| Reporter says still broken | `in_progress` via reporter-verify |
| Resolved **without** `[resolution_evidence]` | **Excluded** from timeout (do not auto-close) |

## Dry-run / apply

```bash
cd backend
php artisan bugs:close-stale-resolved --dry-run
php artisan bugs:close-stale-resolved --actor=<super_admin_user_id>
```

Idempotent: re-run yields `already_closed_by_timeout`. Does not delete comments or rewrite history.

## Scheduler

Not enabled in `Kernel` by default. Enabling requires Founder approval + failure alert path.

## Failure / retry

- Command exits non-zero if no actor user.
- Per-bug failures are skipped and logged (`bug_closed_by_timeout` / `bugs_close_stale_resolved`).
- Safe to retry (idempotent).
