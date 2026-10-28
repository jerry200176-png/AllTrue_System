## 2026-10-28 — chore(probe): package ledger probe lists a named package's rows on lessons not taught
<!-- release-notes: silent_ship=package-ledger-probe-rows -->
<!-- silent-reason: Operator-only read-only diagnostic; no screen or API behavior change. -->
- #4455: with `package_id`, the read-only shared-package ledger probe also lists every ledger row on a lesson that is not chargeable or was deleted (date and start from the lesson, its schedule audit snapshot, or its sign-in date), each live chargeable member lesson in the same slot with its own package and course-ledger net, and every row of a slot debited through more than one lesson. READ ONLY session, rolled back, ids/dates/statuses only.
