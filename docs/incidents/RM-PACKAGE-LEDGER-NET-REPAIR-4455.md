# Repair Manifest — shared package ledger net repair (#4455)

Status: POP operation `package-ledger-net-repair-4455`; nothing runs until a Founder-approved POP request.
Risk: R3/T3 (production data repair). Source: read-only package ledger scan, GitHub #4455 (in-app #419, #4275).
Deploy #4452 (net-based package ledger) before executing, so no new class A/B rows appear.

Scope: `PackageLedgerNetRepairManifest::rows()`, 54 rows over 16 packages, insert-only into `package_session_ledger`
(reason `repair_4455`, note `#4455 class A|B`, `request_id` = POP request id), then the written packages' counters are
recomputed. No course ledger, session, invoice or payment write.

- Class A, missing debit (17): one -1 row; `student_class_id` = `ClassSession.StudentClassID`. Precondition: package net 0
  (group types: the (SessionDate, StartTime) slot net; package 123, one_on_one: the session net), course ledger net > 0,
  ClassSession status attended/completed/late, its `StudentClass.PackageID` is the package, group type matches the manifest.
- Class B, double debit (37): one +1 row; `student_class_id` from the `attendance` row. Precondition: session net -2 from
  exactly two rows, `attended` -1 and `attendance` -1. The ClassSession row is not required.

A row that already has a `repair_4455` row is `done` and skipped. Any other mismatch blocks the whole plan (codes carry
the session id: `a_package_net_`, `a_status_`, `a_course_net_`, `a_scope_`, `a_package_`, `a_session_missing_`,
`a_slot_twice_`, `b_net_`, `b_rows_`, `package_missing_`). Execute locks the 16 package rows and re-runs every check.

Dry-run: a super_admin drafts with `{"decision_reference": "repair-package-ledger-net-4455"}` and dry-runs through the POP
API. Expect `ok=true state=before`, counts `rows=54 to_insert=54 done=0 blocked=0 packages=16`, per-package
`remaining_before/after` and `ledger_net_before/after` (remaining clamps at 0; packages 134, 137, 143, 156 and 167 are already used up, so read the ledger net) and the digest. Approve: super_admin, `founder-go-` reference, `commit_sha` = deployed backend SHA.
The Pi scheduler (`pop:execute-approved`, every minute) runs execute, then verify: each row's target net is -1 with its
repair row present, and each package's counters equal its ledger.

Rollback: the execute snapshot holds the inserted row ids and prior counters. Rollback deletes exactly those rows while
the row's net is still -1, then recomputes counters; a row that moved on is skipped (`ok=false, partial=true`). As for the
other exact POP repairs, no production adapter runs this strategy's rollback (`--phase=rollback` serves only the atomic
financial strategies), so a rollback is a new Founder-approved path.
