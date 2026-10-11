## 2026-10-11 — fix(billing): 帳務中心名單的已繳／部分繳／未繳與已收金額改讀帳單與收款紀錄
<!-- release-notes: staff_update=staff-2026-10-11-alerts-status-from-verdict -->
- 帳務中心（繳費提醒）每筆單科合約的「已繳／部分繳／未繳」和「已收」改用帳單與收款紀錄算，不再看課程上的已繳勾選與帳單上舊的累計數字。
- 已收齊卻還掛在「未繳費」、只收一部分卻顯示「已繳」的合約會自己改對；名單列入條件與金額計算不變。
- 新增 `ContractMoneyCallersAgreeTest`：120 組種子合約檢查名單、課程查找與 Verdict 的答案一致。
