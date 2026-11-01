## 2026-11-01 — fix(billing): a full-discount renewal shows 全額折扣，不用繳費 in the ledger, not 需核對 (#4656)
<!-- release-notes: staff_update=staff-2026-11-01-full-discount-ledger -->
- The ledger API adds `full_discount_settled`. It is true when a current-term 全額折扣 NT$0 renewal bill (#4018) settles the course, and stays false for 免費／贈送, which keeps `free_gift_settled`. The 學生帳務 panel used to treat that paid term as legacy data (需核對 and the legacy-flag actions). It now shows 「全額折扣，不用繳費」 on the contract card.
