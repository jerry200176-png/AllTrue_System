## 2026-11-18 — fix(in-app): allowlist public resolution evidence before internal provenance
<!-- release-notes: silent_ship=silent-2026-11-18-bug-evidence-public-boundary -->

- 公開回報明細與產品閉環計數只讀既有 `resolution_production_verification`；未知或內部類型仍保留於 append-only 稽核儲存，不會被當作上線證據。
- 這是 #5498 原生留言來源修正的先行讀取防護，尚未新增任何來源證據寫入、狀態操作或歷史資料修復。
- 先正式部署並驗證此防護，才可合併／啟用後續內部紀錄 producer；之後的退版最低版本必須保留此讀取界線。不能以回退整包程式或刪除稽核資料處理。
- 合成回歸驗證公開與 includeInternalNotes 明細均不洩漏未知類型，既有公開證據與原始稽核筆數不變。Silent ship：既有公開 producer 均為既有類型，沒有新使用者操作或畫面。
