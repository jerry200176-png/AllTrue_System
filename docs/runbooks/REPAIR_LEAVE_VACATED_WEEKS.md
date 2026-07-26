# Runbook — Repair legacy leave vacated weeks

**Command:** `php artisan repair:leave-vacated-weeks`  
**Policy:** Founder Decision 2026-07-26 / AI_REGRESSION §R82  
**Default:** dry-run (no writes)

## Purpose

Find count-based courses where ordinary leave used the legacy **SHIFT** cascade
(silent vacated next recurrence) and classify:

| Bucket | Meaning | Apply? |
|--------|---------|--------|
| `A_future_safe` | Vacated date still in future, append provenance present, no collision / manual exception | Yes with `--apply` |
| `B_historical` | Vacated date already past, or slot occupied | Review only |
| `C_ambiguous` | Missing append provenance or manual/reschedule/exception noise | Review only |

## Usage

```bash
# Scan (safe)
php artisan repair:leave-vacated-weeks --dry-run --limit=500

# Filter
php artisan repair:leave-vacated-weeks --dry-run --campus-id=16 --from=2026-07-01 --to=2026-07-31
php artisan repair:leave-vacated-weeks --dry-run --course-id=1953

# Apply future-safe only (production)
ALLOW_PROD_REPAIR=1 php artisan repair:leave-vacated-weeks --apply --force --actor='ops@example'
```

## Invariants

- Never rewrite historical attendance / attended sessions.
- Never delete appends that are unsafe (`isSafeToRemoveAutoAppend`).
- Collision on restored vacated slot → bucket B, no apply.
- Idempotent: re-scan after apply should drop repaired courses from A.

## Evidence

Log: `repair.leave_vacated_weeks.applied` (redact names before share).
