# Repair Manifest — TD-076 Phase 3 occurrence identity backfill

**Class:** R3 backfill of nullable identity columns already deployed  
**Status:** Command shipped; **production execute not run**  
**Must not change:** ClassSession, Invoice, Payment, `FEATURE_SCHEDULE_OCCURRENCE_V2`

Stamp `original_schedule_date` / `original_start_time` on live heads only. Skip extras, ghosts, collisions, drift.

1. Dry-run artisan (no mutation)
2. mysqldump `schedules`
3. `--execute --force --snapshot=...` with `ALLOW_PROD_REPAIR=1` and `I_APPROVE_TD076_OCCURRENCE_BACKFILL=1`
4. Rollback: `--rollback --execute --force --snapshot=...`
