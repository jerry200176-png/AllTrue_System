# Scoped Phase-C for in-app 359 2026-10-07

## Exact scope and authority

One per-ID record (359) in the existing single-target writer, bound to merge
`59daf9a39f1b66d6b6063f185f203955ec882a8f` (#3499), first deployed by run
37465434964 and an ancestor of production `1d8b893e`. No allowlist gate,
actor, permission, evidence requirement, writer or environment change.
Mechanically R3/T3 (protected workflow path); needs Founder GO before merge.

## Included

| In-app | Issue | Basis | rev (merge) | Deploy run | Reply claims |
|---|---|---|---|---|---|
| 359 | #3204 | PR #3499: availability and cross-campus collectors in SubstituteService now use ScheduleGuardService live-row rules (paused courses, leave_adjusted/excused/voided, rescheduled-away rows, duplicate schedules rows no longer count); picker shows 他校有課 matching the backend 422. AvailabilityCapacityTest with mutation checks. | `59daf9a39f1b66d6b6063f185f203955ec882a8f` | 37465434964 | Busy state and transfer block now follow the booking rules; no lesson data changed. Screen not operated in production. Acceptance pending. |

## Gates and execution

Exact-head required checks; fresh per-ID state before any write. After merge,
dispatch via the existing `bug_id` request. Reporter acceptance is not
fabricated. This PR dispatches nothing.

## Recovery

Revert through a normal PR if metadata is wrong; no business or schema
mutation. A later reporter failure follows the existing reopen path.
