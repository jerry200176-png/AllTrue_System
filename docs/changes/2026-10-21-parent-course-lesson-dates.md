## 2026-10-21 — feat(parent): each course card lists its own lesson dates (in-app #418, #4274)
<!-- release-notes: silent_ship=silent-2026-10-21-parent-course-lesson-dates -->
<!-- silent-reason: 家長卡於上線並在正式站驗證後另 PR 發布（docs/parent-updates/）；教職員畫面無變化 -->
- 家長入口「課表」分頁的每張堂數制課程卡，可展開「已上 N 堂・查看上課日期」，列出這門課每一堂的日期與時間（最新在上，編號與已上堂數對得起來），不用再切到「學習」分頁對科目自己數。共用方案的每一科只列自己的日期，總堂數仍以方案為準。
- 後端 `GET /api/v1/parent/dashboard` 的 `classes[]` 新增唯讀欄位 `attended_lessons`（堂數制才有；月結制為 null），來源與「已上」計數相同（已扣堂簽到、已完成堂次、未綁堂次的已核准評量），只查已綁定學生自己的課程。
