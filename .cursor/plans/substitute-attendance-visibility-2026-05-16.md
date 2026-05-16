# 代課老師點名可見性 Bug Fix Plan

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P1 |
| 根因類型 | 權限邊界 + 前端資料合併 |
| 根因摘要 | `SmartCalendar.vue` 老師週曆在 teacher mode 只抓本老師 `schedules`，原老師看不到「已交給代課老師」的 scheduled 例外，因此保留原老師底卡；`AttendanceController::store` 也仍允許合約老師在已有代課時點名。 |
| 錯誤行為 | 單堂已指定代課後，原老師仍可能在行事曆看到該堂並送出點名。 |
| 預期行為 | 單堂有代課時，該堂只出現在代課老師視角；原老師不可看到該堂待點名，也不可透過 API 手動點名。 |
| 影響範圍 | 老師行事曆週檢視、點名面板、`POST /api/v1/attendance`、`POST /api/v1/attendance/batch-mark`。 |
| B1 偵查來源 | GitHub #357；`AI_REGRESSION_LESSONS.md` §R39/§R42/§R44；`SmartCalendar.vue`、`ClassSessionController::index`、`AttendanceController::store`。 |

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 代課老師點名可見性修復 |
| 版本 | 2026-05-16 |
| 狀態 | Draft，等待使用者批准 DEV |
| 嚴重度 | P1 |
| 目標角色 | teacher、director/admin（驗證） |
| 關聯 Bug | GitHub #357 |

## 2. 業務背景與影響

代課是單堂授課權責轉移。若原老師仍看到該堂並可點名，出缺勤紀錄會被錯誤歸屬，後續評量、扣堂、主任審核都可能跟著錯。

修復後預期行為：同一堂只會由 effective teacher（代課老師優先，否則正班老師）負責顯示與點名。

## 3. 範圍

In Scope:
- 老師週檢視：單堂代課後原老師不再看到該堂，代課老師看得到該堂。
- 手動點名 / 批次點名 API：有代課時只允許代課老師操作。
- Regression tests 覆蓋前端合併與後端權限。

Out of Scope:
- 不改代課建立流程本身。
- 不改 LearningRecord 代課歸屬，該族已由 §R39/§R46 規範。
- 不改 RFID 刷卡流程，除非測試證明同一 helper 需要同步帶 `StartTime`。
- 不做 DB migration。

## 4. RACI

| 類別 | R | A | C | I |
|---|---|---|---|---|
| DEV | AI Agent | AI Agent | 使用者 | 使用者 |
| TEST | AI Agent | AI Agent | 使用者 | 使用者 |
| REVIEW | AI Agent | AI Agent | 使用者 | 使用者 |
| OPS | AI Agent | AI Agent | 使用者 | 使用者 |

## 4b. Dependencies

- 無前置 PR。
- 依賴現有 `schedules.status='scheduled' + original_schedule_id IS NOT NULL` 作為單堂代課權威來源。
- 依賴 `ClassSession.StartTime` 精準匹配同日多堂。

## 5. Acceptance Criteria

### AC-001：原老師不可看到已代課單堂
- AC-001-a：老師週檢視載入某堂已指定代課的課，原老師的 occurrence list 不包含該堂。
- AC-001-b：同一 fixture 中代課老師的 occurrence list 包含該堂，且 `teacher_id` 為代課老師。

### AC-002：原老師不可手動點名代課單堂
- AC-002-a：`POST /api/v1/attendance` 由原老師對已代課 `ClassSessionID` 點名，回傳 403。
- AC-002-b：同一 `ClassSessionID` 由代課老師點名，回傳成功。

### AC-003：同日多堂不可誤判
- AC-003-a：同一 `StudentClass` 同一天 15:00 正班、20:00 代課，15:00 原老師可操作，20:00 只有代課老師可操作。

## 6. 功能需求 FR

- FR-001：`SmartCalendar.vue` 老師週檢視在合併前必須取得足夠的代課 exception 資訊，讓 `mergeWeekCalendarOccurrences()` 能把該堂 effective teacher 改成代課老師，再由 teacher scope filter 移除原老師視角。
- FR-002：`AttendanceController::store` 的老師授權必須採用 effective teacher：若該 `ClassSession` 有代課老師，只允許代課老師；若無代課才允許合約老師。
- FR-003：代課判定必須帶 `ClassSession.StartTime`，不可只用課程 + 日期。
- FR-004：`batchMark` 透過 `store()` 繼承同一權限，不另開例外。

## 7. 非功能需求 NFR

不適用效能型 bug；本次只修單週資料合併與單堂點名授權。前端不得引入額外高頻 API loop，後端不得放寬老師跨分校資料權限。

## 8. 技術方向

- `frontend/src/pages/SmartCalendar.vue`
  - 調整 teacher mode 的 schedules 載入策略：週曆合併需要知道同分校/可見課程的代課 scheduled 例外，不應只抓 `teacher_id=currentTeacherId`。
  - 保留 `mergeWeekCalendarOccurrences()` 作為唯一週檢視合併路徑。
- `frontend/src/lib/calendarOccurrenceMerge.test.js`
  - 新增 fixture：原老師底卡 + 代課 scheduled exception；驗證原老師被 filter 掉、代課老師看得到。
- `backend/app/Http/Controllers/AttendanceController.php`
  - `resolveSubstituteTeacherUserIdForSession()` 改為帶入 start time，委派 `SubstituteScheduleService::resolveSubstituteUserId()`。
  - `store()` 改成「有 substitute 則 substitute 才能點名；沒有 substitute 才看 contract teacher」。
- `backend/tests/Feature/AttendanceEndedSessionsSubstituteTest.php` 或新增 `AttendanceSubstituteAuthorizationTest.php`
  - 覆蓋原老師 403、代課老師成功、同日多堂精準匹配。

## 8b. Decision Log

| 日期 | 決策 | 替代方案 | 理由 |
|---|---|---|---|
| 2026-05-16 | 前端仍以 `mergeWeekCalendarOccurrences()` 合併，不在 Vue component 分散 if | 直接在 template 或 `filteredCourses` 特判 | 符合 §G-007 / §R25b，避免週檢視三來源再分裂。 |
| 2026-05-16 | 後端 API 權限也修，不只修 UI | 只隱藏原老師畫面 | 原老師仍可直接呼叫 API 點名，屬權限邊界缺口。 |
| 2026-05-16 | 代課匹配必帶 start time | date-only 查代課 | §R39 已記錄同日多堂風險，date-only 會誤擋/誤放。 |

## 9. 資安與存取控制

涉及 teacher 權限邊界，需做條件性資安審查。

- 不新增公開端點。
- 不放寬老師跨分校查詢。
- 原老師在單堂代課期間不應具備該堂 attendance write 權限。
- 若前端需要讀更多 schedules，最終渲染與可操作項目必須仍依 effective teacher filter；後端點名權限作為真正 enforcement。

## 10. QA 驗收

Happy Path:
- 代課老師登入週曆，看得到代課堂並可點名。

Edge:
- 原老師登入週曆，看不到該代課堂。
- 同一課程同一天兩堂，只有被代課的那堂轉移權限。

Error:
- 原老師對代課堂 `POST /attendance` 回 403。

### Revert-proof 驗證
- [ ] `git stash` 後重跑新增 frontend test，原老師被 filter 的 case 至少 1 failure。
- [ ] `git stash` 後重跑新增 backend test，原老師 403 case 至少 1 failure。

## 11. 上線與維運

- Migration：無。
- CI：frontend calendar pure test + backend feature test；PR CI 綠才 merge。
- Deploy：PR merge 後由 `deploy.yml` 自動部署。
- Observability：部署後 `GET /api/v1/health`；正式站若有對應 bug 回報則留言 resolved。
- 回滾：`git revert <merge_commit>`，無 migration rollback；預估 5-10 分鐘。

## 12. 優先級

P1。執行 Agent：
- `[DEV]` 前端 + 後端修復
- `[TEST]` regression + revert-proof
- `[REVIEW]` 權限邊界 review
- `[DOCS]` CHANGELOG + AI_REGRESSION_LESSONS（若新增防再犯）
- `[OPS]` CI / deploy / health

## 13. 風險 / 假設 / 開放問題

本專案文件：§R39、§R42、§R44 都指向同一原則：單堂代課以 `schedules` effective teacher 為權威，且要匹配 `StartTime`。外部查詢到 K12 / SIS 產品常見做法是 substitute assignment 會授予代課老師 class rollcall / attendance access；原老師是否被技術性禁止各產品文件未明講，但 AllTrue 的資料正確性要求必須由後端 enforcement 保證。

風險:
- teacher mode 若讀全分校 schedules，可能增加前端 payload；需限制日期窗口與仍只用於 occurrence 合併。
- 後端授權改嚴可能暴露既有 stale substitute rows；測試需覆蓋「stale 原老師 scheduled row 不應搶贏」。

假設:
- 單堂代課仍以 `schedules.status='scheduled'` 且 `original_schedule_id IS NOT NULL` 表示。
- `Teacher.id === User.id`，可直接用 `auth_teacher_id` 比對 `schedules.teacher_id`。

開放問題:
- 正式站 #357 未提供具體課堂 ID；DEV 可先用 regression fixture 修通，再請使用者/回報者驗收實際興隆案例。

## 14. Definition of Done

- [ ] FR-001：驗證方式：`cd frontend && npm run test:calendar` 回傳 pass，新增 substitute teacher visibility case 通過。
- [ ] FR-002/FR-003：驗證方式：GitHub Actions PHPUnit feature test pass，原老師代課堂點名為 403、代課老師成功。
- [ ] FR-004：驗證方式：batch mark test pass，批次路徑繼承 `store()` 權限。
- [ ] Revert-proof：驗證方式：暫時還原修復後，新增 frontend/backend tests 至少各 1 case failure。
- [ ] CHANGELOG：驗證方式：`git diff docs/CHANGELOG.md` 含 2026-05-16 `fix(attendance)` 條目。
- [ ] 權限 review：驗證方式：PR review checklist 明確確認未新增公開端點、未放寬 teacher write scope。
- [ ] Health check：驗證方式：`curl -sk https://daan.lifenet.com.tw/api/v1/health` 回傳 `status=ok`。
