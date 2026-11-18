## 2026-11-17 — chore(ci): remove the retired provenance compatibility stub
<!-- release-notes: silent_ship=silent-2026-11-17-remove-retired-provenance-stub -->
<!-- silent-reason: 移除已退出必要檢查、且已無自動呼叫端的相容空工作；產品介面及權限不變。 -->

- 接續 #5425／#4103：更新後的 main 合併佇列已實際測試並合併產品批次 #5430／#5429，移除暫留的手動空工作。
- 保留現行必要檢查、最終版本審查、權限及正式發布閘門；沿用既有跨版本 dispatch 契約測試，防止先刪目標、後刪呼叫端。
- 回滾可從前一個 main 還原此檔，不涉及資料或 schema；本次檔案清理不另宣稱 runner／Token 或整體成本節省比例。
