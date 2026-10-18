# Renewal shift review closeout

Repository AllTrue_System; task chore/task-renewal-shift-backend-review-20261018; fresh main0ea127d48. Existing Draft #4215 (c235257a58) implements the #4210/#410 successor-shift plan, flags OFF. Risk R3/T3, existing entitlement/scheduling rules only. No production action, migration, changed billing basis or flag activation.

Goal: address independent-review findings4306054350/355/358 in the existing PR without duplicating it. Keep ClassSession authoritative and preserve the atomic leave/replan transaction and campus scope.

1. Integrate the existing PR into this clean task worktree; inspect current service, tests and schedule helper. Preserve current main changes.
2. Add focused RED regressions for a successor mode changed before locked re-read, mutually overlapping proposed destinations, and legacy missing scheduling durations. Add an adjacent-slot control. Reuse real scheduling helpers/fixtures; derive scheduling fallback from existing occurrence, never billing lesson minutes.
3. Correct the root guards and plan construction with the smallest service change; run the focused successor-shift suite in an isolated local schema. Review every removed line and keep the PR below700 changed lines; preserve assertions.
4. Push the existing #4215 branch normally, retain Draft/flags OFF, request exact-head independent review and record hosted checks. No merge until its delivery dependencies and complete gates are satisfied.

Verification: RED->GREEN focused scenarios, complete existing LeaveSuccessorShiftTest, exact-head full hosted CI and independent review. Rollback: remove the default-off code through a normal PR; no production state changed. Stop for new entitlement semantics, production release/enablement or real data changes.

Existing c235 already contains the stale-mode and proposed-overlap regressions, so reused them rather than duplicating tests. Added legacy NULL-duration75-minute occurrence versus90-minute billing-basis regression. RED8tests/57assertions reproduced all3findings (overlap preview eligible, legacy preview422, stale apply200). GREEN8tests/70assertions in12.536seconds. Complete existing suite now passes79tests/562assertions in24.289seconds. No counters/billing basis changed; adjacency remains allowed. Source fixes revalidate locked count mode, compare every proposed interval pair, and use the last authoritative occurrence's duration only when the scheduling default is absent.
