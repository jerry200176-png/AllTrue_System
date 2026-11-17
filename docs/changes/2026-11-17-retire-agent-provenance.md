## 2026-11-17 — chore(ci): retire automatic provenance runs and queue callers (#4103)
<!-- release-notes: silent_ship=silent-2026-11-17-retire-agent-provenance -->
<!-- silent-reason: 停止已退役的空殼 CI 自動執行及合併佇列中的殘留呼叫；教職員畫面與資料不變。 -->

- 核對 GitHub 現行必要檢查已不含 Agent Session Provenance，移除自動執行、佇列 dispatch 與完成事件觸發。
- 過渡期僅保留手動 dispatch，讓仍從舊版 main 啟動的佇列能驗證本次更新；呼叫端更新合併後再刪檔。加入跨版本佇列升級契約，防止過早刪除仍在使用的目標。
- 保留全部現行必要檢查、最終版本審查、合併及正式啟用權限；同步既有佇列契約測試。不修改 GitHub ruleset。
