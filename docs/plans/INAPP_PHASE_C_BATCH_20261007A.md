# Scoped Phase-C batch 2026-10-07 A (in-app 338, 333)

Combined per Founder rule 1A (one paperwork PR per batch). Existing single-target
writer; no gate, actor, permission, evidence or writer change. Mechanically R3/T3.
Both revs are contained in production deploy run 37563232975 (success, head
`1d8b893e`, `git merge-base --is-ancestor`). Evidence level: engineering tests
plus production version check; no production UI check; reporter acceptance pending.

| In-app | Issue | Basis | rev (merge) | Deploy run |
|---|---|---|---|---|
| 338 | #3148 | #3499 occupancy uses ScheduleGuardService live-row rules + #3424 used-up count courses free template seats (AvailabilityCapacityTest). Replaces #3710 (closed on conflict). | `59daf9a39f1b66d6b6063f185f203955ec882a8f` | 37563232975 |
| 333 | #3138 | #3500 per-teacher slot groups (EnrollmentService filters by subject and teacher; feature test). Existing data checked by read-only probe run 37571711459 (`inapp_333_multi_teacher_templates`): 1 contaminated contract, stopped, 3 past sessions all completed/attended and deducted → no repair. | `f3aa9efca6b235d0ddf049a29a0fd396eea1bd97` | 37563232975 |

Dispatch one ID at a time via `bug_id` after merge; this PR dispatches nothing.
Recovery: revert; a reporter failure follows the reopen path.
