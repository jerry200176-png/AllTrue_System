## 2026-10-15 — fix(billing): 作廢後重開更正帳單時，新舊帳單備註互記單號、原因與操作者 (Founder D3；Refs #4115)
<!-- release-notes: silent_ship=silent-2026-10-15-void-reissue-traceability -->
<!-- silent-reason: API only; no screen sends replaces_invoice_id yet (lands with the Founder-decided UI PR) -->
- 開帳單 API 新增選填 `replaces_invoice_id` 與 `correction_reason`：只能更正同一位學生、同一門課且已作廢的帳單；新帳單備註記「更正自 #舊單號／操作者／原因」（操作者由系統寫入），舊帳單備註加上新單號（不會截掉原作廢紀錄），並寫入稽核紀錄；是否已更正以稽核紀錄判斷。同一原因與金額重送不會開出第二張，其他重送會被拒絕。
