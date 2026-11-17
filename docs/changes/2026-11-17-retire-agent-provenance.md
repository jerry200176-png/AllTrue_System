## 2026-11-17 — chore(ci): retire the unused provenance pass-through (#4103)
<!-- release-notes: silent_ship=silent-2026-11-17-retire-agent-provenance -->
<!-- silent-reason: 移除已退役的空殼 CI workflow 及合併佇列中的殘留呼叫；教職員畫面與資料不變。 -->

- 核對 GitHub 現行必要檢查已不含 Agent Session Provenance，移除只輸出退役提示的 workflow、佇列 dispatch 與完成事件觸發。
- 保留全部現行必要檢查、最終版本審查、合併及正式啟用權限；同步既有佇列契約測試。不修改 GitHub ruleset。
