## 2026-11-05 — chore(ops): POP operation to add the missing sign-in to taught, debited lessons
<!-- release-notes: silent_ship=silent-2026-11-05-taught-lesson-signin-repair-pop -->
<!-- silent-reason: reviewed ops data-repair operation; no screen change -->
- New POP operation `taught-lesson-signin-repair-20261105` (#4925 #4927 #4931, in-app #502 #504) with adapter `pop-taught-lesson-signin-repair.yml`. For past lessons since 2026-08-01 that are completed or attended, hold exactly one ledger debit and have no sign-in row, it writes one present sign-in at the payroll payee without touching the ledger, counters, status or payroll tables. Execute is pinned to the dry-run digest and needs the Founder's approval; rollback deletes exactly the created sign-ins.
