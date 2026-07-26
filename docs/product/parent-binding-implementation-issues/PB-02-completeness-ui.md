# PB-02 — Data completeness UI

| Field | Value |
|-------|-------|
| Phase | 1 |
| Risk class | T1 |
| Dependencies | PB-00 |
| Blocks | PB-03 |

## Scope

- StudentsList（and student detail）：show contact completeness； filter missing `parent_phone` / empty contact； show active LINE guardian count.
- Student create/edit / Wizard：collect `parent_phone`（and keep legacy `Phone` behavior explicit）.
- Import：map 家長手機 → `parent_phone`（not only `Phone`）； document header mapping.
- Completeness summary API for campus（read-only）.

## Non-scope

- Pairing issue UI（PB-05）； Inbox creation logic（PB-03）； auth changes.

## Acceptance criteria

1. Director can list students with missing parent contact in ≤2 clicks.
2. New enrollments can save `parent_phone`.
3. CSV import with 家長手機 writes `parent_phone`.
4. Summary counts match DB for a fixture campus.

## Tests

- Feature：import parent_phone； student update parent_phone； completeness endpoint campus isolation.
- Frontend component／e2e smoke optional.

## Rollback

- Flag `parent_binding_completeness_ui=off` hides filters； columns remain nullable.
