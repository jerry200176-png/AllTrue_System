## 2026-10-21 — feat(parent): each course card lists its own lesson dates (in-app #418, #4274)
<!-- release-notes: silent_ship=silent-2026-10-21-parent-course-lesson-dates -->
<!-- silent-reason: 家長卡於上線並在正式站驗證後另 PR 發布（docs/parent-updates/）；教職員畫面無變化 -->
- 家長入口「課表」分頁的每張堂數制課程卡，可展開「查看上課日期（N 筆）」，列出這門課每一堂的日期與時間（最新在上，先顯示 10 筆，可「顯示全部」），並可連到「完整出缺勤紀錄」。共用方案的每一科只列自己的日期（「本科上課日期」），總堂數仍以方案為準。
- 日期清單與「已上」堂數同一來源：`SessionDeductionService::batchObservedUsedSessionEvidence()` 與 `batchObservedUsedSessions()` 共用同一組查詢與去重鍵，堂數計算結果不變。`GET /api/v1/parent/dashboard` 的 `classes[]` 新增唯讀欄位 `attended_lessons`（月結制為 null），只查已綁定學生自己、畫面上會顯示的課程。
