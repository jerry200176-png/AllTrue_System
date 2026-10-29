## 2026-10-29 — fix(billing): 標記「免費／贈送」時，看該期實際生效的單價（#4008）
<!-- release-notes: staff_update=staff-2026-10-29-free-gift-amendment-by-term -->
- 標記「免費／贈送」不再因為課程曾經或將來有調價紀錄就被擋下：只看那一期當天生效的單價；那一期單價大於 0 才會拒絕。
