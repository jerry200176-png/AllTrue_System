## 2026-10-07 — fix(inapp): newly allowlisted Phase-C IDs are written automatically (F22)
<!-- release-notes: silent_ship=silent-2026-10-07-phasec-autodispatch -->
<!-- silent-reason: 後台流程修正；回報者收到的回覆內容不變，只是合併後會真的送出。 -->
- 新增 `bug-phase-c-autodispatch.yml`：Phase-C 白名單在 main 變更後，只對新增的 in-app ID 逐筆觸發結案、等每筆完成並核對結果為 resolved；讀不到前一版白名單或任一筆失敗即停。
