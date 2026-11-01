## 2026-11-01 — fix(billing-ui): student ledger drawer headings and width (#4771)
<!-- release-notes: staff_update=staff-2026-11-01-ledger-drawer-readability -->
- The 學生帳務 drawer is wider on desktop (560px to 720px, still full width on phones). Section headings (`.ledger-section h4`) go from 14px to 16px bold, with a divider between sections. CSS only in `AccountingLedgerModal.vue`; all test ids and a11y attributes unchanged.
- Checked at 360/375px: bill card values do render (stacked card layout). The lone `—` is the empty actions cell.
