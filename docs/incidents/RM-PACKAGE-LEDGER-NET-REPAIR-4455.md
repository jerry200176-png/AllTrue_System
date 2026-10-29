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
