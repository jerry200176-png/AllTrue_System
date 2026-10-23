## 2026-10-23 — Package ledger probe lists lessons charged on the course but not on the pool
<!-- release-notes: silent_ship=silent-2026-10-23-package-probe-charged-not-debited -->
<!-- silent-reason: operator diagnostic workflow; no product code or data change -->

`inapp-package-ledger-probe` gains a `charged_slots_not_debited` section and a per-package count in `summary`: lesson slots (date + start) where a member lesson is still charged on its course ledger (deduct minus reverse > 0) but the package ledger over every session of that slot is not negative. These are the exact repair targets for the package ledger's old row-based idempotency, which skipped a lesson reverted and then attended again (#4275, in-app #419). The section lists session ids, dates, start times, statuses and the slot net; it shows no names, amounts or notes. It still runs in one MySQL READ ONLY session that is rolled back.
