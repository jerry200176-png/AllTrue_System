## 2026-11-06 — fix(billing): an all-NT$0 bill no longer shows as a billing line (in-app #500, #4917)
<!-- release-notes: staff_update=staff-2026-11-06-billing-zero-bill-line -->
- Root cause: the contract card in the 帳務中心 student panel printed `應收・已收・未繳` whenever the course had any invoice, so a tutoring (or amount-less) contract with a NT$0 invoice showed 未繳 NT$0 / 已收 NT$0 with no reason. Tutoring, waived and full-discount cards now show no amount line (their rows already say why); any other all-zero bill shows the single label 金額未填. Display only; no data or backend change.
