## 2026-11-17 — fix(ui): 讀取進度線涵蓋所有主要畫面（in-app #493 後續）
<!-- release-notes: staff_update=staff-2026-11-17-loading-bar-coverage -->
- 頂端進度線（#4881）只算經過 `trackedFetch` 的讀取；約 43 個畫面與 API 模組直接呼叫 `fetch`，讀取時看不到進度線。
- 現在這些檔案改走 `trackedFetch`（模組層 `import { trackedFetch as fetch }`，與 `api.js` 同一做法）。
- 刻意不接：輪詢、背景更新、開機載入、搜尋即時建議（`App.vue`、`chatApi.js`、`TeacherHomePage.vue`、通知中心的定時更新等），避免進度線一直閃。
