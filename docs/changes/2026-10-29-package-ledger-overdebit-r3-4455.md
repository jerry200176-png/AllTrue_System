## 2026-10-29 — #4455 round 3: last package over-debits down to taught + absent
<!-- release-notes: silent_ship=silent-2026-10-29-package-ledger-overdebit-r3-4455 -->
<!-- silent-reason: data repair operation; nothing changes for staff until the Founder-approved repair runs -->

`package-ledger-net-repair-4455` gains a Founder-gated round 3 (`decision_reference` `repair-package-ledger-overdebit-r3-4455`, reason `repair_4455_r3`). It writes 5 class R rows, each +1 on a package-creation seed whose lesson was deleted with nothing on that day: 4 in package 195 and 1 in 173. That brings ledger used down to taught + absent (Founder 2026-10-29). It uses round 2's checks and is insert-only. Package 58 waits for a course-ledger reversal (runbook §Round 3). Refs #4455
