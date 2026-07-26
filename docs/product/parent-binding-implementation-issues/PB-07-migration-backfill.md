# PB-07 — Migration & dual-write

| Field | Value |
|-------|-------|
| Phase | 2 |
| Risk class | T3 |
| Dependencies | PB-04, PB-05 |
| Blocks | PB-08, PB-09 |
| ADR | https://github.com/jerry200176-png/AllTrue_System/pull/1434 |
| Status | backlog / blocked on PB-04/PB-05 |

## Scope

- Backfill：verified `student_line_bindings` → ParentIdentity + active GuardianStudentRelationship（method=`contact_phone_legacy`）.
- Dual-write：new pairing/approval/legacy success writes relationship **and** SLB projection.
- Orphan cleanup：delete/graduate playbooks； purge hook for student delete.
- Audit command：verified SLB missing relationship = 0 after backfill.
- **Revoke invalidates ParentSession immediately**（wire + verify in migration/service layer； Founder）.
- Phone-change / transfer hooks：reconfirm / suspend as designed.

## Non-scope

- Dropping `student_line_bindings`； OTP； forcing sunset.

## Acceptance criteria

1. Post-backfill audit = 0 orphans among verified bindings.
2. New binds appear in both relationship and SLB while dual-write on.
3. Student delete does not leave usable bindings/relationships.
4. Revoke path expires ParentSessions in same transaction／immediate follow-up（tested）.
5. Rollback plan documented and tested on CI schema.

## Tests

- Feature：backfill idempotent； dual-write； delete purge； transfer suspend； **session gone after revoke**.
- Command test for audit counters（no PII）.

## Rollback

- Stop dual-write flag； auth fallback to SLB； keep backfilled rows.
