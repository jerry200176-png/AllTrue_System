## 2026-11-05 — feat(billing): 帳單快速面板（v3 PR2，#4659 #4919）
<!-- release-notes: staff_update=staff-2026-11-05-bill-quick-view -->
- Frontend only. Clicking a 帳單 row (or its 確認入帳 / 核對 primary, or ⋯ 退回) opens a right-side quick view of that contract: 應收／已收／未收, the parent report from the existing `/accounting/ledger` call with 確認入帳 / 退回 (`paymentActions`), 登記收款, collapsed lesson dates and ⋯. Done rows leave the chip and a toast offers 看收據. A package row without a contract id now opens 看帳務 instead of 開帳單 (#4919). No API, money-rule or DB change.
