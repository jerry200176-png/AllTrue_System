# Repair Manifest — TD-076 Phase 3 occurrence identity backfill

**Class:** R3 schema backfill (nullable columns already deployed)  
**Status:** Command shipped; **production execute not run**  
**Must not change:** `ClassSession`, Invoice, Payment, attendance, `FEATURE_SCHEDULE_OCCURRENCE_V2`

## Scope

Stamp `schedules.original_schedule_date` / `original_start_time` on **live heads** only
(`scheduled` or `leave`, `type != extra`, not superseded by a later `rescheduled` sibling
on the same slot). Walk `original_schedule_id` then same-slot prior destination to freeze
the first slot.

Skip: extras, chain ghosts, collision groups (two live heads, same identity), drift
(already stamped to a different tuple).

## Execute path (later GO)

Committed artisan only. No Pi SSH from a laptop.

1. Workflow `td076-occurrence-identity-backfill-dry-run.yml` (this PR) — no mutation
2. mysqldump `schedules` (+ `schedule_change_log` unused by this command)
3. `php artisan schedules:backfill-occurrence-identity --execute --force --snapshot=...`
   with `ALLOW_PROD_REPAIR=1` and `I_APPROVE_TD076_OCCURRENCE_BACKFILL=1`
4. Verify live heads have identity; ghosts remain unstamped; billing tables untouched
5. Rollback: same command `--rollback --execute --force --snapshot=...`

## Recovery

Restore `original_*` to null from the snapshot, or restore the gzip dump of `schedules`.
