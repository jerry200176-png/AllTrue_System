# 木柵今日行事曆同學生同時段重複 Bug Fix Plan

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P1 |
| 根因類型 | 前端合併去重不足 + legacy active 契約資料 |
| 根因摘要 | `SmartCalendar` 週檢視合併後的 `dedupeByStudentSlot()` 以 `student_course_id` 優先當 key，導致同一學生、同日、同開始時間但不同 `StudentClass` 的「legacy 固定契約 base card」與「實體 ClassSession card」同時顯示。 |
| 錯誤行為 | 木柵今日行事曆同一學生同時段出現兩張課卡。 |
| 預期行為 | 同一學生同日同開始時間只能顯示一張主要 occurrence；若有實體 `ClassSession`，應優先保留實體堂次，壓掉 legacy synthetic/base 契約卡。 |
| 影響範圍 | `SmartCalendar` 週檢視；木柵今日已觀察到洪家溱 10:00、陳宥霖 15:00。 |
| B1 偵查來源 | Production read-only API：`GET /class-sessions`、`GET /student-classes`、`GET /schedules`；`AI_REGRESSION_LESSONS.md` §R25b/§R40/§R43/§R47。 |

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 木柵行事曆同學生同時段去重 |
| 版本 | 2026-05-16 |
| 狀態 | Draft，等待使用者批准 DEV |
| 嚴重度 | P1 |
| 目標角色 | director/admin/teacher |
| 關聯 Bug | 使用者即時回報：木柵分校今日課程怪怪的，同學生同時段兩張 |

## 2. 業務背景與影響

行事曆是點名、調課、代課與主任查課的入口。同一學生同時段顯示兩張會讓老師誤以為有兩堂課，也可能造成後續點名或評量判斷混亂。

B1 查到今日木柵：
- `ClassSession` 今日 13 筆，沒有同學生同開始時間重複。
- `StudentClass` active 固定契約有同學生同時段多筆，其中舊月結/已用完契約仍 active。
- 具體可疑：
  - 洪家溱 10:00：SC#94 月結 legacy active + SC#1256 今日 `ClassSession#11262 leave`。
  - 陳宥霖 15:00：SC#97 月結 legacy active + SC#1254 今日 `ClassSession#10128 leave`。

## 3. 範圍

In Scope:
- 修 `calendarOccurrenceMerge.js` 的同學生同日同開始時間去重策略。
- 新增 regression test，覆蓋 legacy base contract + class-session-backed occurrence 只保留實體堂次。
- 保持週檢視唯一合法路徑仍是 `mergeWeekCalendarOccurrences()`。

Out of Scope:
- 不直接修改 production DB。
- 不在本 PR 自動停用 SC#94 / SC#97。
- 不改課程建立/續課資料模型。
- 不處理一般「老師/教室容量衝突」完整功能，只處理同學生同時段重複顯示。

## 4. RACI

| 類別 | R | A | C | I |
|---|---|---|---|---|
| DEV | AI Agent | AI Agent | 使用者 | 使用者 |
| TEST | AI Agent | AI Agent | 使用者 | 使用者 |
| REVIEW | AI Agent | AI Agent | 使用者 | 使用者 |
| OPS | AI Agent | AI Agent | 使用者 | 使用者 |

## 4b. Dependencies

- 無前置 PR。
- 依賴現有 `calendarOccurrenceMerge.js` 已作為週檢視合併單一入口。
- 若要做資料清理，需要另外批准 production DB 寫入流程與備份，不納入本計畫 DEV。

## 5. Acceptance Criteria

### AC-001：legacy base + 實體堂次只顯示一張
- AC-001-a：同一學生同日同開始時間，若一筆 occurrence 有 `class_session_id`，另一筆是不同 `StudentClass` 的 base/synthetic occurrence，系統只顯示 `class_session_id` 那筆。
- AC-001-b：保留的卡片需維持原本 status/badge（例如 leave/cancelled/attended）與 teacher 顯示。

### AC-002：同課同堂既有去重不退化
- AC-002-a：同一 `ClassSession.id` 仍只輸出一張卡。
- AC-002-b：代課 scheduled exception overlay 仍能覆蓋老師，不被新去重破壞。

### AC-003：資料查證可回歸
- AC-003-a：新增 fixture 模擬洪家溱/陳宥霖型態，不依賴 production DB。
- AC-003-b：`npm run test:calendar` pass。

## 6. 功能需求 FR

- FR-001：`dedupeByStudentSlot()` 應建立跨 `StudentClass` 的同學生同日同開始時間 key。
- FR-002：去重排序應優先保留 `class_session_id`-backed occurrence，其次 exception，最後 base/synthetic contract。
- FR-003：同分數時保留較新的/較明確 occurrence，但不可讓 legacy monthly base 壓過實體堂次。
- FR-004：不得在 Vue component 新增分散式 if 合併；所有規則留在 `calendarOccurrenceMerge.js`。

## 7. 非功能需求 NFR

不適用效能型 bug；去重只處理單週 occurrence array，資料量小。不得增加額外 API call。

## 8. 技術方向

- `frontend/src/lib/calendarOccurrenceMerge.js`
  - 調整 `dedupeByStudentSlot()` key：使用學生識別 + day/date + start，而不是優先使用 `student_course_id`。
  - scoring 保留 `class_session_id` > exception > base。
- `frontend/src/lib/calendarOccurrenceMerge.test.js`
  - 新增 legacy active monthly base + current class session fixture。
  - 確認只留實體堂次，並保留 leave status。
- `docs/CHANGELOG.md`
  - 若 DEV 完成，新增 `fix(calendar)` 條目。
- `docs/AI_REGRESSION_LESSONS.md`
  - 若確認為新防再犯，新增「同學生同時段去重不可以 StudentClassID 當唯一 key」。

## 8b. Decision Log

| 日期 | 決策 | 替代方案 | 理由 |
|---|---|---|---|
| 2026-05-16 | 先修前端合併去重，不直接寫 production DB | 直接停用 SC#94/SC#97 | DB 寫入需備份與人工確認；前端可先防止同族資料污染造成畫面重複。 |
| 2026-05-16 | 保留 `ClassSession` backed occurrence | 保留最新 `StudentClass` 或較大 ID | 行事曆/點名的權威是實體堂次，能保留狀態與 attendance/evaluation badge。 |
| 2026-05-16 | 不新增 API call | 額外查 conflict endpoint | 本 bug 可在既有 occurrence list 去重解決，避免性能/Actions 成本增加。 |

## 9. 資安與存取控制

純前端顯示合併規則，不新增公開端點、不改 auth、不擴大資料範圍。需確認不因去重跨學生或跨分校混資料；key 必須以同一週 occurrence 內的學生識別與日期時間為限。

## 10. QA 驗收

Happy Path:
- 木柵今日週檢視同學生同時段只剩一張卡。

Edge:
- 代課 overlay 仍能將老師改成代課老師。
- leave/cancelled status 不被 base contract 覆蓋。

Error:
- 若兩筆都是 `ClassSession` backed 的真正資料衝突，本次先保留最高分一筆，後續可另開 conflict badge 技術債。

### Revert-proof 驗證
- [ ] `git stash` 後重跑新增 `calendarOccurrenceMerge.test.js` case，至少 1 case failure。

## 11. 上線與維運

- Migration：無。
- CI：frontend Vite build + `npm run test:calendar`。
- Deploy：PR merge 後 `deploy.yml` 自動部署。
- Observability：部署後 `GET /api/v1/health` 與 `version.json`。
- 回滾：`git revert <merge_commit>`；無 DB rollback。

## 12. 優先級

P1。執行 Agent：
- `[DEV]` frontend merge fix
- `[TEST]` calendar regression + revert-proof
- `[REVIEW]` 對照 §R25b/§R40/§R43/§R47
- `[DOCS]` CHANGELOG / AI_REGRESSION_LESSONS
- `[OPS]` CI / deploy / health

## 13. 風險 / 假設 / 開放問題

本專案已知坑顯示：同學生/同課/同時段重複會影響點名與行事曆，且週檢視必須集中在 occurrence merge 處理。外部 scheduling 系統常見做法是即時偵測學生/老師/教室 double-booking；本次選擇在 calendar read path 先做同學生同時段去重，避免 legacy 資料污染畫面。

風險:
- 若未來真的允許同學生同時段多科共用一張卡，本次去重會只顯示一張；目前業務語意下學生同一時間不可在兩堂課。
- 資料根因（legacy active 月結契約）仍存在；本計畫只防畫面重複。

假設:
- `class_session_id` backed occurrence 是比 base recurring contract 更權威的顯示來源。
- 同學生同日同開始時間只應顯示一張主要課卡。

開放問題:
- 是否要另開 OPS/data-cleanup 任務，備份後停用 SC#94、SC#97 這類 legacy active 月結契約？

## 14. Definition of Done

- [ ] FR-001/FR-002：驗證方式：`cd frontend && npm run test:calendar` 回傳 pass，新增同學生同時段 fixture 通過。
- [ ] FR-004：驗證方式：`git diff frontend/src/pages/SmartCalendar.vue` 不新增分散合併邏輯。
- [ ] CHANGELOG：驗證方式：`git diff docs/CHANGELOG.md` 含 2026-05-16 `fix(calendar)` 條目。
- [ ] Health check：驗證方式：`curl -sk https://daan.lifenet.com.tw/api/v1/health` 回傳 `status=ok`。
