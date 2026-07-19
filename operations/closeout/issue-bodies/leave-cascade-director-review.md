## Evidence
- Production dry-run `repair:leave-cascade-slot-times` (Actions `29680051696`): **96** candidates; **high_confidence=19** / medium=57 / needs_review=20.
- Teacher Wed17/Sat10 contract `sc=2301` has correct clocks now; `includes_wed17_sat10=false`.
- Closeout: `docs/incidents/leave-cascade-slot-times-closeout-2026-07-19.md`
- Domain leave probe OK on production after #1335/#1338/#1339.

## User impact
Directors/teachers may still see historically misaligned weekday clocks after past leave cascades. Manual DB triage is not acceptable; need a director-readable review pack.

## Root-cause confidence
High for leave foreign-clock + sibling scheduled rows (19). Medium/needs_review must not be auto-selected.

## Scope
- Export director CSV (`--export-csv`, `selected=0` default) with student, date, current vs contract slot, classify reason, leave/exception evidence.
- Preview + apply **only** approved `--session-ids` (no re-scan batch write).
- Audit log + idempotent apply + rollback from snapshot.
- CSV-first / existing director surface — **no large UI** in first slice.

## Non-goals
- Batch `--execute --force` on all 96 (Founder veto).
- Large Vue repair console.
- TD-059 package minutes.

## Acceptance criteria
- [ ] High-confidence 19 exported as director review pack + runbook
- [ ] Apply path rejects missing `--session-ids`
- [ ] Audit + rollback documented/tested
- [ ] Medium/needs_review not default-selected

## Verification plan
Dry-run export on Pi; director preview; apply one approved session id in controlled window; verify before/after + rollback snapshot.

## Priority rationale
P1 data-correctness follow-up; bounded 19-row pack; unblocks historical cleanup without Founder DB review.
