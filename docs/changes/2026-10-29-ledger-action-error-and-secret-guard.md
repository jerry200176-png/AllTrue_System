## 2026-10-29 — fix(billing UI): a failed void keeps the ledger on screen (#4551); ci: credential log guard covers 6 more forms (#4029-#4033, #4036)
<!-- release-notes: staff_update=staff-2026-10-29-ledger-action-error -->
- `AccountingLedgerModal.vue`: 撤銷收款／作廢帳單 failures set a separate `actionError`, reread the ledger and show the reason with a 重新整理 button instead of replacing the whole panel (#4551). The shared field-error wording (`httpFailureMessage`) is left for a follow-up.
- `scripts/ci/workflow-secret-echo.test.mjs`: new rules and must-catch probes for indirect expansion, here-strings into a shell, `let`, `ps e*`, `/proc/<pid>/environ`, staged files, secret env aliases through transforms, github-script `process.env` forms and Python heredoc captures. Real workflows still pass.
