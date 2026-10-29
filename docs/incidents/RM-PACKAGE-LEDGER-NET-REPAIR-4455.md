# Repair Manifest — shared package ledger net repair (#4455)

Status: POP operation `package-ledger-net-repair-4455`; nothing runs until a Founder-approved POP request. Risk: R3/T3 (production data repair). Source: read-only package ledger scan, GitHub #4455 (in-app #419, #4275). Deploy #4452 (net-based package ledger) before executing, so no new class A/B rows appear.

Scope: `PackageLedgerNetRepairManifest::rows()`, 52 rows over 15 packages, insert-only into `package_session_ledger` (reason `repair_4455`, note `#4455 class A|B`, `request_id` = POP request id), then the written packages' counters are recomputed from a current ledger read. No course ledger, session, invoice or payment write.

- Class A, missing debit (15; 28718 and 36716 were dropped after the first production dry-run blocked them with `a_session_net`, see #4470): one -1 row; `student_class_id` = `ClassSession.StudentClassID`. Precondition: package net 0 (group types: the (SessionDate, StartTime) slot net; package 123, one_on_one: the session net) and the lesson's own package net 0, course ledger net > 0, ClassSession status attended/completed/late, its `StudentClass.PackageID` is the package, group type matches the manifest.
- Class B, double debit (37): one +1 row; `student_class_id` from the `attendance` row. Precondition: session net -2 from exactly two rows, `attended` -1 and `attendance` -1. The ClassSession row is not required.

A row that already has a `repair_4455` row is `done` and skipped. Any other mismatch blocks the whole plan (codes carry the session id: `a_package_net_`, `a_session_net_`, `a_status_`, `a_course_net_`, `a_scope_`, `a_package_`, `a_session_missing_`, `a_slot_twice_`, `b_net_`, `b_rows_`, `package_missing_`). Execute locks the member courses (primary key, id order) and then the 15 package rows as its first statements, and re-runs every check with current reads.

Run: `.github/workflows/pop-package-ledger-net-repair-4455.yml` (#4459) with `mode=dry-run` and then `mode=approve`; each run waits for the Founder's `production-activation` approval and uses an unexpired super_admin session on the Pi. Dry-run expects `ok=true state=before`, counts `rows=52 to_insert=52 done=0 blocked=0 packages=15`, and per-package `remaining_before/after` and `ledger_net_before/after` (remaining clamps at 0; packages 134, 137, 143, 156 and 167 are already used up, so read the ledger net). Approve uses `founder-go-package-ledger-net-repair-4455` bound to the deployed backend SHA. The Pi scheduler (`pop:execute-approved`, every minute) runs execute, then verify: each row's target net is -1 with its repair row present, and each package's counters equal its ledger.

Rollback: the execute snapshot holds the inserted row ids and prior counters. Rollback deletes exactly those rows while the row's net is still -1, then recomputes counters; a row that moved on is skipped (`ok=false, partial=true`). As for the other exact POP repairs, no production adapter runs this strategy's rollback (`--phase=rollback` serves only the atomic financial strategies), so a rollback is a new Founder-approved path (#4458). The rows stay identifiable by reason `repair_4455` and the POP request id.

## Round 2 — over-debits (re-check 2026-10-28)

Status: manifest in code, nothing executed. The repair runs only after a Founder-approved POP request: first a dry-run on `production-activation`, then the approve click. Source: the post-repair re-check on #4455 and the read-only `inapp-package-ledger-probe` runs per package (runs 40735822124–40736430559, ids only).

Scope: `PackageLedgerNetRepairManifest::rows(2)`, 39 class R rows over 8 packages (133, 140, 155, 160, 173, 175, 176, 207). It is insert-only into `package_session_ledger` with reason `repair_4455_r2` and note `#4455 class R` (round 1's `repair_4455` already sits on some sessions, and the unique key is package + session + reason). Each row is minus the lesson's package net as found, so the lesson ends at net 0:
- +1 on 37 extra debits. These are package-creation seeds (`attended`) whose lesson was deleted minutes after the package was made. The lesson was re-created that day at its real start and debited again (175 ×3, 133 ×4, 176 ×20), or it is gone with nothing taught that day in a package whose taught lessons all carry their own debit (176 ×3, 140 ×2). The rest are cancelled seeds sharing a debited slot (155 ×3, 173 ×1) and a group slot's second debit (207 ×1).
- −1 on 2 stray `duplicate_session` credits (176 / 36788 and 160 / 30936). The duplicate cleanup credited the cancelled duplicate although the slot's debit sits on the kept lesson. That left the slots of the blocked class A rows 36716 and 28718 at net 0. Bringing the credit back to 0 puts each slot at −1, so 36716 and 28718 need no row of their own.

Preconditions (any mismatch blocks the whole plan; codes carry the session id):
- `r_status_`: the lesson's status (or `deleted`) and its slot are as listed.
- `r_net_`: its package net is the listed −1 or +1.
- `r_course_net_`: its course ledger does not charge it (deduct minus reverse ≤ 0).
- `r_scope_`: the package is a group type.
- `r_slot_net_`: after every round 2 row in that slot, the rest of the slot nets −1 if a lesson there is attended, late or course-charged, and 0 otherwise.

Execute locks member courses and then packages, as in round 1, and re-checks under lock.

Not in round 2 (Founder questions on #4455):
- Package 195's 34 seeds, 25 of them re-created as uncharged `completed` lessons.
- 173's 36236 and 36237. That package also has an uncharged `completed` lesson, which is the class D rule-1 review.
- Package 58's 10678, a cancelled lesson that its course ledger still charges.

Run: the same workflow, which now drafts `decision_reference` `repair-package-ledger-overdebit-4455` with idempotency key `founder-package-ledger-overdebit-repair-4455`; `mode=dry-run`, then `mode=approve`. The dry-run expects `ok=true state=before`, counts `rows=39 to_insert=39 done=0 blocked=0 packages=8`, and per package `ledger_net` 133 −36→−32, 140 −14→−12, 155 −18→−15, 160 −30→−31, 173 −24→−23, 175 −12→−9, 176 −57→−35, 207 −5→−4. Verify checks each lesson at net 0 with its repair row, and counters equal to the ledger.

Rollback: the snapshot holds the inserted ids and `round: 2`. A rollback deletes only rows whose lesson still nets 0. As in round 1, no production adapter runs it, so a rollback is a new Founder-approved path.

## Round 3 — down to taught + absent (Founder 2026-10-29, option A)

Round 2 ran on 2026-10-29: approve run 40822045546, audit verified. The post-round-2 probe (runs 40825430575, 40825468531, 40825511624) left 3 packages over: 195 +4 (ledger used 46, taught 42), 173 +1 (24 vs 23) and 58 +1 (7 vs 6). The Founder chose to reverse only the excess, so used equals taught + absent and never goes below it.

Scope: `PackageLedgerNetRepairManifest::rows(3)`, 5 class R rows with reason `repair_4455_r3`. The checks are the same as in round 2. Only package-creation seeds (`attended`, 16:00) qualify: the lesson was deleted, nothing is on that day, and there is no course charge. 195 has 9 of these and 173 has 2.
- 195: 38727, the 2026-07-12 16:00 slot's second debit (38696 holds that slot). Then 38704, 38706 and 38708, the latest dates.
- 173: 36237, the later of 36236 and 36237.

The probe cannot tell which deleted date each uncharged `completed` lesson (195: 5, 173: 1) replaced. So which seeds keep their debit is a choice, and the balances come out the same.

Not in round 3: package 58 / 10678. That cancelled lesson is still charged on its course ledger (deduct net +1). Under rule 1 the package follows the course, so the R check `r_course_net_` blocks a package-only row, and this operation writes no course ledger. The smallest addition is a class C row that calls `SessionDeductionService::reverseForSession(1329, 10678, …)`. That existing writer adds the course `reverse` event and syncs the package +1 through `PackageDeductionService::syncFromStudentClassDeduction`. Its preconditions are: lesson cancelled, course net exactly +1, package net −1. Rollback would remove both rows. This needs a Founder GO, because it adds a course-ledger write to this operation.

Run: the same workflow, which now drafts `decision_reference` `repair-package-ledger-overdebit-r3-4455` with idempotency key `founder-package-ledger-overdebit-r3-repair-4455`. The dry-run should show `ok=true state=before`, counts `rows=5 to_insert=5 done=0 blocked=0 packages=2`, and `ledger_net` 173 −24→−23 and 195 −46→−42. Verify and rollback are the same as in round 2, with `round: 3` in the snapshot.

## Round 4 — package 58's cancelled lesson still charged on its course (Founder GO 2026-10-29「AA」)

Scope: `PackageLedgerNetRepairManifest::rows(4)` holds one class C row: package 58, session 10678, course 1329. The lesson is cancelled (2026-05-07 20:00, one_on_one, date mode). It is still charged once on its course ledger (deduct − reverse = +1) and debited once on the package (−1), as of probe run 40825511624.

Execute, under the round's locks, calls the existing `SessionDeductionService::reverseForSession(1329, 10678, 'repair_4455_r4', …)`. That writer adds one `session_deduction_ledger` `reverse` row with source `repair_4455_r4`. It then syncs the package through `PackageDeductionService::syncFromStudentClassDeduction`, which adds one +1 `package_session_ledger` row with reason `repair_4455_r4` and recomputes the package counters. The strategy then stamps that row with the POP request id.

The course counters are not recomputed, for two reasons:
- `SessionDeductionService::recomputeCounters()` sets `Stop = 0` on a non-count course, and course 1329 is stopped.
- It counts only the attendance/retro_leave/status_adjust/duplicate_session sources, so this reverse would not change them anyway.

Preconditions (any mismatch blocks the plan):
- `c_status_`: the lesson is still cancelled, on course 1329, at its slot.
- `c_net_`: package net −1.
- `c_course_net_`: course net exactly +1, with no round-4 reverse yet.
- `c_package_`: course 1329 still belongs to package 58.
- `c_floor_`: after the credit, package used stays at or above taught + absent. That floor is read under a shared lock during execute.

Run: the same workflow with `round=4`. It drafts `decision_reference` `repair-course-charge-r4-4455` with idempotency key `founder-course-charge-r4-repair-4455`. `round=3` still drafts round 3. The dry-run should show `ok=true state=before`, counts `rows=1 to_insert=1 done=0 blocked=0 packages=1`, and package 58 `ledger_net` −7→−6. `remaining` stays 0 before and after, because it is a date-mode package with total 0. Verify checks package net 0 and course net 0 on 10678, both repair rows present, counters equal to the ledger, and the floor. Rollback deletes both rows only while the lesson still nets 0 on both ledgers. The snapshot holds `rows` and `course_rows`.
