# PB-07 — Migration & dual-write

| Field | Value |
|-------|-------|
| Phase | 2 |
| Risk class | T3 |
| Dependencies | PB-04, PB-05 |
| Blocks | PB-08, PB-09 |

## Scope

- Backfill：each verified `student_line_bindings` → ParentIdentity + active GuardianStudentRelationship（method=`contact_phone_legacy`）.
- Dual-write：new pairing/approval/legacy success writes relationship **and** SLB projection.
- Orphan cleanup：delete/graduate playbooks； FK or purge hook for student delete.
- Audit command：count SLB verified missing relationship（must be 0 after backfill）.
- Phone-change / transfer hooks：document + minimal suspend/reconfirm triggers.

## Non-scope

- Dropping `student_line_bindings`； rewriting historical ParentSession rows beyond revoke rules.

## Acceptance criteria

1. Post-backfill audit = 0 orphans among verified bindings.
2. New binds appear in both relationship and SLB while dual-write on.
3. Student delete does not leave usable bindings/relationships.
4. Rollback plan documented and tested on staging/CI schema.

## Tests

- Feature：backfill idempotent； dual-write； delete cascade/purge； transfer suspend.
- Command test for audit counters（no PII）.

## Rollback

- Stop dual-write flag； auth fallback to SLB； keep backfilled rows.
