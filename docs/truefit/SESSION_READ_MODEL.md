# TrueFit session read model (Slice 0)

## Product correctness finding

**There is no production invariant** that every legal today session for a teacher
already exists as a `ClassSession` row before any UI/API read.

Evidence:

- `ClassSessionController::authorizeAndMaterializeClassSessionIndex()` documents
  that the teacher today list relies on `ClassSession` rows and **materializes on
  read** for same-day queries (monthly contracts, count-mode contracts, and
  schedule-exception repair).
- Nightly `sessions:generate-forward` covers a forward horizon; it does not
  guarantee that **today** is fully materialized before the first teacher login.
- `GET /api/v1/class-sessions` returns materialized rows in `data` and projected
  slots separately in `projected.by_class`; legacy top-level `data` is materialized
  only.

Therefore a pure `ClassSession` SELECT **can miss** today sessions that are still
schedule-only or contract-projected.

## TrueFit pure-read fix (Slice 0)

`GET /api/v1/truefit/today-sessions` merges three read-only sources:

| Source | Service | Covers |
|--------|---------|--------|
| Materialized rows | `ClassSessionIndexReadService` | Existing `ClassSession` rows |
| Contract projection | `ClassSessionIndexProjectionService` | Monthly (`ScheduleMode=date`) and count-mode recurring slots |
| Schedule exceptions | `ClassSessionScheduleExceptionReadService` | `schedules` rows for today without a matching `ClassSession` |

Response meta:

- `read_mode: pure_read` — no auto-materialization or operational writes
- `completeness: materialized_plus_projected` — merged calendar view for teachers

Projected rows use `class_session_id: null` and `session_kind: projected`.
Materialized rows keep a positive `class_session_id` and `session_kind: materialized`.

## Next user-visible outcome

Slice 1 is **AI Teacher Brief**, not a manual textarea prep flow.
