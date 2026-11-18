## 2026-11-18 — fix(schedule): commit booking and session projection together
<!-- release-notes: silent_ship=silent-2026-11-18-schedule-atomic-projection -->
<!-- silent-reason: 修復排課失敗時殘留部分資料的內部一致性，沒有新增操作或更動教職員流程。 -->

- 新增與沿用已排時段時，在同一個原生交易內重新核對容量並寫入 Schedule／ClassSession；後段失敗會一起回復，避免失敗請求留下幽靈時段。
- 保留原有試聽／代課／跨日 anchor 的判定與回應；不修改既有正式資料、不新增 schema 或 API。
- 這是 #5404（in-app #563）的獨立前置修補，不代表既有課程轉用功能或真實案例已完成。測試注入真實投影／更新失敗，並核對交易提交後完整佔位。
