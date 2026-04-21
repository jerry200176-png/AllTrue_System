# Bug Fix Plan：代課後調課 schedules 表未同步導致代課老師顯示錯誤

---

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P1 |
| 根因類型 | 邏輯錯誤（缺少同步步驟） |
| 根因摘要 | `LearningRecordController::rescheduleSession` 只移動 `ClassSession.SessionDate`，未同步 `schedules` 表的 `rescheduled` / `scheduled` 列，導致代課後獨立調課時 schedules 留在舊日期 |
| 錯誤行為 | 先代課再調課後：schedules 表的代課相關列停留在原日期；新日期無 scheduled row；`class-sessions` index 解析 `teacher_id` 時仍顯示原班老師 |
| 預期行為 | 調課後 schedules 表的 `rescheduled` + `scheduled(substitute)` 列應同步遷移至新日期；若新日期有重複 scheduled 列（race condition 植入），應清除，只保留代課老師那筆 |
| 影響範圍 | 主任執行「先代課、後獨立調課」流程；`POST /api/v1/learning-records/reschedule-session`；`class-sessions` index `teacher_id` 顯示 |
| B1 偵查來源 | 本計畫整合 CI 錯誤訊息 + 程式碼直讀（`LearningRecordController.php` L1098–1192 vs `ClassSessionController.php` L1442–1475） |

---

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 代課後調課 schedules 同步 |
| 版本 | v1 |
| 狀態 | Draft |
| 嚴重度 | P1 |
| 目標角色 | 主任（director）、管理員（admin） |
| 關聯 Issue | GitHub #3 |
| 關聯測試 | `SubstituteReschedulesCombinationTest::test_substitute_then_reschedule_shows_substitute_teacher`、`::test_duplicate_scheduled_row_on_new_date_is_purged_by_sync`、`SubstituteTeacherTest::test_class_sessions_index_reflects_substitute_for_roll_call` |

---

## 2. 業務背景與影響

### 痛點

主任「先代課」後「獨立調課」，點名列表仍顯示原班老師，代課老師看不到該堂次，無法正常點名與提交學習評量。

### 修復後預期行為

- 「先代課再調課」後，schedules 表的代課相關列遷移至新日期
- `class-sessions` index 的 `teacher_id` / `teacher_name` 正確顯示代課老師
- 若 race condition 在新日期植入重複 scheduled 列，reschedule-session 執行後只保留代課老師那筆

---

## 3. 範圍

### In Scope

- `LearningRecordController::rescheduleSession`：在移動 ClassSession 後，新增 schedules 表同步邏輯（FR-001：遷移 + FR-002：清除重複）
- `SubstituteReschedulesCombinationTest`：取消兩個 `markTestSkipped`
- `SubstituteTeacherTest`：取消一個 `markTestSkipped`

### Out of Scope

- `ClassSessionController::substitute` 的合併代課+換時路徑不動（已正確）
- `ScheduleGuardService` 不動
- `schedules` 表結構 / migration 不動
- 前端 SmartCalendar 不動（FR-001 前端防衛是獨立計畫）
- `RescheduleSessionPrecisionTest`（FR-004 strict old_start_time）不動，仍 skip

---

## 4. RACI

| 角色 | 任務 |
|---|---|
| R（執行）| AI Agent |
| A（負責）| AI Agent |
| I（知情）| 系統管理員 |

## 4b. Dependencies

無前置 PR 或 Migration 依賴。

---

## 5. Acceptance Criteria

### AC-001：代課後調課 — schedules 正確遷移

- AC-001-a：代課後呼叫 reschedule-session，新日期的 `schedules` 表有且只有一筆 `status=scheduled` 且 `teacher_id = 代課老師` 的列
- AC-001-b：舊日期的代課 scheduled row 已不存在

### AC-002：race condition 重複列清除

- AC-002-a：reschedule-session 執行後，新日期相同 `original_schedule_id` 的 scheduled rows 只剩一筆
- AC-002-b：保留的那筆 `teacher_id = 代課老師`

### AC-003：class-sessions index 反映代課老師

- AC-003-a：代課後調課，`GET /api/v1/class-sessions?start=新日期&end=新日期` 回傳的 `teacher_id` = 代課老師 ID
- AC-003-b：正班老師查詢同一日期同一堂，也能看到這筆記錄（不因代課而被過濾掉）

---

## 6. 功能需求 FR

### FR-001：rescheduleSession 遷移代課 schedules 列

修復後，`rescheduleSession` 在移動 ClassSession 至新日期後，系統應：
1. 在 `schedules` 表查找當前課程（`student_course_id`）在**舊日期**的 `status=rescheduled` 列
2. 若存在：將其 `schedule_date` / `day_of_week` 更新為新日期
3. 查找對應的 `status=scheduled`（代課）列（`original_schedule_id` = rescheduled row 的 id）
4. 若存在：同步遷移 `schedule_date` / `day_of_week`

### FR-002：清除新日期的重複 scheduled 列

修復後，遷移完成後，系統應：
- 查找新日期上相同 `student_course_id` + `original_schedule_id` 的所有 scheduled 列
- 刪除**除剛遷移的那筆以外**的重複列（race condition 植入的舊教師列）

### FR-003：無代課情境不受影響

修復後，若 `schedules` 表在舊日期無對應的代課列（純調課，不涉及代課），系統應正常移動 ClassSession，不拋出例外。

---

## 7. 非功能需求 NFR

不適用。本 bug 為純邏輯錯誤，修復在既有 DB transaction 內新增最多 2 個 UPDATE + 1 個 DELETE，不影響效能基準。

---

## 8. 技術方向

### 涉及檔案與方法

| 檔案 | 方法 | 變更說明 |
|---|---|---|
| `app/Http/Controllers/LearningRecordController.php` | `rescheduleSession()` | 在 session 移動後、return 前，新增 schedules 同步邏輯（FR-001 遷移 + FR-002 清除重複） |
| `tests/Feature/SubstituteReschedulesCombinationTest.php` | `test_substitute_then_reschedule_shows_substitute_teacher`、`test_duplicate_scheduled_row_on_new_date_is_purged_by_sync` | 移除 `markTestSkipped` |
| `tests/Feature/SubstituteTeacherTest.php` | `test_class_sessions_index_reflects_substitute_for_roll_call` | 移除 `markTestSkipped` |

### 同步邏輯參考

`ClassSessionController::substitute` 的 `$hasReschedule=true` 路徑（L1461–1475、L1563–1568）已有完整實作，`rescheduleSession` 的新邏輯應複用相同 DB query 結構，在**同一 DB transaction 內**執行。

### 架構取捨

- 不抽 Service：此邏輯目前只在 `rescheduleSession` 需要，抽 Service 增加複雜度，未來若需複用再重構
- 使用 `lockForUpdate`：遵循業界標準（WebSearch 2025 結果），在 transaction 內對 schedules 列加 `lockForUpdate`，防止 race condition 在查詢後、刪除前插入新重複列

## 8b. Decision Log

| 日期 | 替代方案 | 選擇理由 |
|---|---|---|
| 2026-04-22 | 抽 ScheduleSyncService | 棄用：目前只一處使用，過度設計；需要複用時再抽 |
| 2026-04-22 | 前端 FR-001 防衛（不送 payload2） | 棄用：本 plan 不動前端；後端必須能獨立正確處理 |
| 2026-04-22 | 在 rescheduleSession 內直接複用同步邏輯 | 採用：最小改動，可測試，不破壞既有路徑 |

---

## 9. 資安與存取控制

不適用。本 bug 修復不涉及 auth / token / 角色邊界，reschedule-session 入口已有 middleware 限制主任/管理員。

---

## 10. QA 驗收

### Happy Path
- [ ] 代課後調課 → 新日期 schedules 只有代課老師列，舊日期無殘留

### Edge Case
- [ ] 純調課（從未代課）→ 無代課 schedules 列，FR-001 靜默跳過，不影響結果
- [ ] Race condition 重複列 → reschedule-session 後只剩代課老師那筆

### Error Case
- [ ] schedules 表查無舊日期 rescheduled 列 → 正常 200，不 500

### Revert-proof 驗證
- [ ] `git stash` 後，`test_substitute_then_reschedule_shows_substitute_teacher` 至少 1 case failure

---

## 11. 上線與維運

### 部署步驟
1. 合併 PR 到 `main`
2. 後端 code 已在 Pi（無 migration）
3. `sudo service php8.2-fpm reload`
4. 呼叫 `/api/internal/opcache-reset`

### Migration
無。

### Observability
Sentry 監控不再出現代課老師顯示為原班老師的 Sentry event。

### 回滾方案
`git revert` + 重新部署，約 5 分鐘。

---

## 12. 優先級

**P1** — 影響代課點名與學習評量提交流程。

執行 Agent：`[DEV]` AI Agent

---

## 13. 風險 / 假設 / 開放問題

### WebSearch 查詢結果（2026-04-22）

參考：[Laravel Race Conditions & Duplicate Prevention 業界最佳實踐 2025](https://medium.com/@manmohanaeir2058/dont-get-over-sold-understanding-and-solving-race-conditions-in-laravel-56b8f4da41f0)

業界共識：
- 修改 + 刪除的讀-改-寫序列應在 `DB::transaction()` + `lockForUpdate()` 內執行，防止 race condition 在 SELECT 和 DELETE 之間插入新重複列
- `rescheduleSession` 目前已在部分路徑使用 transaction，新邏輯應在同一 transaction 內

### 風險

| 風險 | 可能性 | 緩解 |
|---|---|---|
| 舊日期有多個 rescheduled 列（理論上不應存在） | 極低 | 取 `->first()` 只處理第一筆，不中斷流程 |
| 新日期的 day_of_week 計算錯誤 | 低 | 使用 `Carbon::parse($newDate)->dayOfWeekIso` |

### 假設

- 一個 (student_course_id, schedule_date, status=rescheduled) 最多對應一筆 rescheduled 列
- 代課老師的 scheduled 列以 `original_schedule_id` 關聯 rescheduled 列

### 開放問題

無。

---

## 14. Definition of Done

- [ ] **FR-001**（遷移）：`git diff app/Http/Controllers/LearningRecordController.php` 含 schedules 表遷移邏輯
- [ ] **AC-001**（代課後調課）：`SubstituteReschedulesCombinationTest::test_substitute_then_reschedule_shows_substitute_teacher` 從 skip 變 pass
- [ ] **AC-002**（重複清除）：`SubstituteReschedulesCombinationTest::test_duplicate_scheduled_row_on_new_date_is_purged_by_sync` 從 skip 變 pass
- [ ] **AC-003**（代課老師可見性）：`SubstituteTeacherTest::test_class_sessions_index_reflects_substitute_for_roll_call` 從 skip 變 pass
- [ ] **全測試綠**：CI `Skipped: 26`（少 3 個），Failures: 0, Errors: 0
- [ ] **Revert-proof**：`git stash` 後 3 個測試至少各 1 failure
- [ ] **CHANGELOG**：`git diff docs/CHANGELOG.md` 含 `2026-04-22` 新增條目
- [ ] **Health check**：部署後 `curl -sk http://localhost/api/v1/class-sessions?start=...` 回傳 HTTP 200

---

## Todos

- [ ] `[DEV]` 修改 `LearningRecordController::rescheduleSession`：在 session 移動後加入 schedules 同步（FR-001 遷移 + FR-002 清除重複），在同一 DB transaction 內執行，使用 `lockForUpdate`
- [ ] `[TEST]` 取消 `SubstituteReschedulesCombinationTest` 兩個 `markTestSkipped`
- [ ] `[TEST]` 取消 `SubstituteTeacherTest::test_class_sessions_index_reflects_substitute_for_roll_call` 的 `markTestSkipped`
- [ ] `[TEST]` Push CI 確認全綠
- [ ] `[REVIEW]` Code Review：逐條對照 FR-001、FR-002、FR-003
- [ ] `[DOCS]` 更新 `docs/CHANGELOG.md`
- [ ] `[OPS]` `sudo service php8.2-fpm reload` + OPcache 清除
