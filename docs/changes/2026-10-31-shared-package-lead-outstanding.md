## 2026-10-31 — fix(billing): shared package lead follows what the bill still owes (#4672)
<!-- release-notes: silent_ship=silent-2026-10-31-shared-package-lead-outstanding -->
<!-- silent-reason: edge-case fix to which sibling row carries 繳費單/登記收款; no wording change -->
- In 課程管理, the row that carries 繳費單 / 登記收款 for a shared count-mode package was chosen by invoice status. A fully collected bill whose stored status still said 未繳 could take the lead from the sibling that really owes. The lead now goes by `outstanding_amount > 0`, which the invoices endpoint always sends.
