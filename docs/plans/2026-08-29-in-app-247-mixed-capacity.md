---
owner: jerry (CEO)
status: Triaged — current payload healthy; regression prevention merged separately
last_reviewed: 2026-08-30
---

# Bug Fix Plan — in-app #247 mixed-capacity substitute picker

GitHub tracking issue: [#2179](https://github.com/jerry200176-png/AllTrue_System/issues/2179)
In-app report: #247; attachment #186; campus #9; calendar page; severity high.

## Current disposition

The historical report said that teacher #30 was marked full for 2026-08-29
13:00–15:00 while an incoming one-on-three session still appeared to have
space. Read-only production evidence for the reported tuple returned one
occupied one-on-three student and `remaining_capacity=2`; the read-only manual
booking check returned `can_add=true` and `conflict_type=none`. The deployed
bundle already contained the mixed-capacity logic from R116/#1889.

The original failure is therefore not currently reproducible and no root cause
is confirmed. The issue remains triaged, not resolved. Do not change capacity
semantics or production data on the basis of the screenshot alone.

## Expected invariant

For a teacher and overlapping slot:

- unique occupied students are counted once;
- remaining capacity is calculated against the class type being added or
  covered, not the strictest class type present in another row;
- a one-on-three row with one student and two seats remaining is selectable;
- a true one-on-one conflict and the absolute teacher limit remain blocked;
- same-campus occupancy is not described as another-campus occupancy.

## Scope of this regression slice

This slice only pins the exact production payload in
`SubstituteTeacherPickerModal` tests. It does not modify runtime scheduling
logic, API responses, backend capacity aggregation, auth, permissions,
attendance, billing, database rows, or deployment configuration.

## Evidence and hypotheses

- Availability for teacher #30, campus #9, 2026-08-29 13:00–15:00: one-on-three,
  `student_count=1`, `remaining_capacity=2`.
- Excluding student #271 returned the same capacity.
- Student class #2081 / existing session #32570 passed a read-only booking check
  with `can_add=true` and no conflict.
- The historical discrepancy could still be a stale open tab, a different
  response payload, a caller supplying a different `class_type`, or a timing
  issue. These must be captured before any runtime fix is proposed.

## Validation matrix

| Case | Expected |
| --- | --- |
| one-on-three, one student, remaining 2 | selectable; capacity warning |
| mixed one-on-two full + one-on-three with remaining 1, covering one-on-three | selectable |
| same mixed slot, covering one-on-two | blocked at one-on-two capacity |
| overlapping one-on-one row | blocked as exclusive |
| three unique occupied students | blocked at absolute teacher limit |
| same student excluded from availability | exclusion does not create false full |

## Acceptance and follow-up

- The exact #247 production payload has a focused, revert-proof test.
- `npm run test:unit`, `npm run lint:no-undef`, and `npm run build` pass.
- A future runtime change requires a captured failing reproduction, a focused
  test that fails before the fix, and the full R116/R114 regression matrix.
- The in-app report and GitHub issue remain open until reporter verification or
  stronger runtime evidence exists; no “fixed” claim is made by this test-only
  slice.
