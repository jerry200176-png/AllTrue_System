## 2026-10-23 — POP operation to repair the shared package ledger rows found in #4455
<!-- release-notes: silent_ship=silent-2026-10-23-package-ledger-net-repair-4455 -->
<!-- silent-reason: data repair operation; runs only after Founder approval -->

New POP operation `package-ledger-net-repair-4455` (`PackageLedgerNetRepairStrategy`, exact manifest, Founder approval, Pi-local). It inserts one `repair_4455` row per listed session: a -1 debit for the 17 lessons charged on their course but never debited on the shared pool (class A), and a +1 credit for the 37 sessions debited twice as `attended` and `attendance` (class B). Then it recomputes each package's counters. Every precondition is re-checked under the package row locks, and any drift fails the whole plan. A row already repaired is skipped. Verify checks each target net and that the counters match the ledger. Rollback deletes exactly the inserted rows. Runbook: `docs/incidents/RM-PACKAGE-LEDGER-NET-REPAIR-4455.md`. Refs #4455
