---
todos:
  - id: "learning-pending-test"
    content: "新增 regression test：老師未填優先/待填列表不得套用預設 90 天窗口"
    status: pending
  - id: "learning-pending-dev"
    content: "修正 LearningRecordsPage 查詢參數與未填定義，確認 TeacherHome summary 導向一致"
    status: pending
  - id: "learning-pending-release"
    content: "低頻率跑必要前端檢查；一次 push PR，CI 綠後 merge/deploy 並回覆 bug"
    status: pending
isProject: false
---
# Bug Fix Plan — 評量未填提醒與列表不一致

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P1 |
| 根因類型 | 前端查詢條件 / 定義不一致 |
| 根因摘要 | `LearningRecordsPage` 預設會注入近 90 天 `start_date`；此豁免只看 status tab，未涵蓋老師的「未填優先」filter，導致 summary/count 看得到歷史未填，但列表被日期窗口隱藏。 |
| 錯誤行為 | 老師看到未填數量，但列表沒有對應資料；工作台提醒與評量列表入口體驗不一致。 |
| 預期行為 | 未完成工作（pending / changes_requested / 未填優先）永遠可見，不被預設歷史窗口藏起來。 |
| 影響範圍 | `LearningRecordsPage.vue`、`TeacherHomePage.vue` 導向；不改 DB、不改權限。 |

## 1. 文件資訊
- 關聯：GitHub #359。
- 目標角色：老師。
- 狀態：B1 完成，待批准 DEV。

## 2. 業務背景與影響
老師無法找到系統提示要填的評量表，會造成評量延誤與主任追蹤困難。

## 3. 範圍
- In Scope：老師端未填/待修改列表不套用預設 90 天窗口；統一未填列表與工作台入口語意。
- Out of Scope：不改評量審核流程、不改代課權限、不改資料補建規則。

## 4. RACI
- R/A：AI Agent
- I：Jerry

## 4b. Dependencies
- 無 migration。
- 無前置 PR。

## 5. Acceptance Criteria
- AC-001：老師點「未填優先」時，API query 不帶自動 `start_date`。
- AC-002：老師的 pending / changes_requested 待辦不被預設日期窗口隱藏。
- AC-003：工作台提醒導向評量頁後，列表能看到對應待處理項目。

## 6. 功能需求
- FR-001：`resolvedDefaultWindowStart` 需把 `teacherPriorityFilter='unfilled'` 視為未完成工作豁免。
- FR-002：`_buildRecordsParams()` 必須由同一 helper 判斷是否套用 default window，避免 count/list 分裂。
- FR-003：新增純前端 regression test 覆蓋 query params。

## 7. 非功能需求
- 不適用；此 bug 非效能問題。

## 8. 技術方向
- `frontend/src/pages/LearningRecordsPage.vue`：抽出或調整 default window 判斷，讓未填優先與待審/需修改同樣豁免。
- `frontend/src/pages/TeacherHomePage.vue`：確認點擊待填入口帶入與列表一致的 filter。
- `frontend/src/lib/*test.js`：用純 JS 測 query params，避免反覆燒 backend CI。

## 8b. Decision Log
- 2026-05-16：不改後端 summary；選擇讓前端列表與待辦語意一致，因 summary 本來就是跨日期未完成工作。

## 9. 資安與存取控制
- 不新增端點、不放寬老師 scope；沿用現有 `learning-records` 與 `me/learning-pending-summary` auth。

## 10. QA 驗收
- Happy：未填優先載入歷史 pending/changes_requested。
- Edge：一般「全部/已核准」仍保留 90 天預設窗口以避免重查大量歷史。
- Revert-proof：stash 修復後新增 query-param test 至少 1 case failure。

## 11. 上線與維運
- 無 migration。
- 前端小 PR；CI 預期只跑 Vite build，PHPUnit 可 skipped。
- Deploy 後驗 `version.json` 與 `/api/v1/health`。
- 回滾：revert PR，預估 5 分鐘。

## 12. 優先級
- P1；下一個 DEV 候選。

## 13. 風險 / 假設 / 開放問題
- WebSearch 對齊：task badge 與列表 filter mismatch 是常見 UX 問題；最佳實務是未完成/待辦工作不應被預設日期窗口隱藏，否則 count 會造成誤導。
- 假設：#359 中的 4 筆資料目前正式站可能已被處理；修復仍覆蓋可重現的 filter mismatch。

## 14. Definition of Done
- [ ] Test：新增前端純測試，驗證未填優先不帶自動 `start_date`。
- [ ] Build：`npm run build` 通過。
- [ ] CI：PR checks 全綠。
- [ ] Deploy：`version.json` hash 更新且 `/api/v1/health` 回傳 `status: ok`。
- [ ] GitHub #359 / 正式站對應 bug：留言並標記 resolved（若有正式站 id）。
