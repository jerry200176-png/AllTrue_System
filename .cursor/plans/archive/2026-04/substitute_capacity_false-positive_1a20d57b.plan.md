---
name: substitute capacity false-positive
overview: 針對興隆分校「單堂換代課老師」誤判衝堂，先釐清資料來源（ClassSession vs schedules）並修正代課 API 在歷史堂次的容量檢核策略，保留未來堂次的衝堂保護。
todos:
  - id: confirm-rule-boundary
    content: 固定規則：歷史堂次放寬、未來堂次維持容量檢查
    status: completed
  - id: backend-guard-bypass
    content: 在 ClassSessionController::substitute 加入歷史堂次 guard bypass 條件
    status: completed
  - id: conflict-observability
    content: 補強衝突明細輸出與 log，讓 false-positive 可追查
    status: completed
  - id: tests-regression
    content: 新增 SubstituteTeacherTest 的歷史放寬與未來擋衝堂案例
    status: completed
  - id: frontend-message
    content: 前端代課錯誤提示支援 conflicts 細節
    status: completed
isProject: false
---

# 興隆分校代課誤擋修正計畫

## 目標
修正「已上/歷史單堂換代課老師」被容量檢查誤擋的問題，同時維持未來堂次的衝堂防護。

## 現況判讀
- 你遇到的訊息來自 [`/home/admin/backend/app/Services/ScheduleGuardService.php`](/home/admin/backend/app/Services/ScheduleGuardService.php) 的老師容量檢查（`老師此時段已有 X 位學生`）。
- 代課 API [`/home/admin/backend/app/Http/Controllers/ClassSessionController.php`](/home/admin/backend/app/Http/Controllers/ClassSessionController.php) 在 `substitute()` 內會先呼叫 `validateScheduleOccurrence(...)`，目前不分堂次是否已結束，一律檢查。
- 這與你實際看到「該老師該時段沒有顯示課程」產生落差，代表高機率存在「UI 不顯示但 guard 仍計入」的占用來源（例如歷史 `ClassSession` 或殘留 `schedules`）。

## 修正方向（產品規則）
- 已確認：
  - 歷史堂次（已結束/已上/已核准）可放寬，允許換代課老師。
  - 未來堂次維持目前容量檢查，避免真衝堂。

## 實作步驟
1. 在 [`/home/admin/backend/app/Http/Controllers/ClassSessionController.php`](/home/admin/backend/app/Http/Controllers/ClassSessionController.php) 的 `substitute()` 增加「堂次時態判斷」
- 用 `SessionDate + EndTime` 與 `now()` 判斷是否為歷史堂次，或用狀態 (`attended/completed/late/absent`) 作為歷史佐證。
- 僅在「未來/未上」情境執行 `ScheduleGuardService::validateScheduleOccurrence`。
- 在「歷史」情境跳過 guard，但保留現有分校、權限、師資綁校檢查。

2. 補強衝堂可觀測性（避免再次誤判難追）
- 在回傳 409 時，把 `conflicts[0].overlap_summary/overlap_details` 帶入前端可視訊息（至少記錄於後端 log）。
- 讓 PM/客服可快速知道「被哪筆資料擋住」。

3. 測試補齊（回歸防再犯）
- 擴充 [`/home/admin/backend/tests/Feature/SubstituteTeacherTest.php`](/home/admin/backend/tests/Feature/SubstituteTeacherTest.php)：
  - `歷史堂次可代課（即使同時段有既有占用）` 應成功。
  - `未來堂次遇到容量衝突` 應回 409 且含 conflict 資訊。
  - `未來堂次無衝突` 仍成功（避免誤傷現有流程）。

4. 前端錯誤提示優化（選配但建議同批）
- 在 [`/home/admin/frontend/src/composables/course-management/useSessionEditFlow.js`](/home/admin/frontend/src/composables/course-management/useSessionEditFlow.js) 的 `doSubstitute()`：
  - 若 409 且有 `conflicts`，顯示更具體提示（哪位學生/哪時段衝突）。
  - 保留目前簡訊息 fallback，避免 UX 退化。

5. 驗收清單（興隆分校場景）
- 以你提供的同一堂（2026-04-12 16:30~18:30, 已上, approved）重測：應可完成代課。
- 再測一筆未來堂次真衝堂：應仍被擋且訊息可解讀。
- 核對課程主檔老師不變、僅單堂代課變更（符合產品定義）。

## 風險與注意
- 放寬範圍必須限於「歷史堂次」，避免影響未來排課容量保護。
- 歷史堂次若已有點名/評量，僅能改單堂授課者，不得改動扣堂與帳務邏輯。
- 需注意時區與 `EndTime` 空值情境，避免誤判歷史/未來。

## QA 測試計畫（回歸 + 相關測試）

### 測試目標
- 確認「歷史堂次代課」不再被容量誤擋。
- 確認「未來堂次」仍受容量守門保護，不引入衝堂回歸。
- 確認代課只影響單堂授課教師，不影響課程主檔與後續排課。
- 確認評量/點名/科目數統計在代課後維持一致性。

### 測試範圍
- 後端 API：
  - `POST /api/v1/class-sessions/{id}/substitute`
  - 關聯查詢：`GET /api/v1/class-sessions`, `GET /api/v1/learning-records`
- 前端流程：
  - `CourseManagement` 單堂課操作 modal 的「換代課老師」
  - 錯誤訊息顯示（409 conflict 詳細資訊）
- 關聯資料：
  - `ClassSession`, `schedules`, `LearningRecord`, `learning_record_teacher_changes`, `StudentClass`

### 測試類型與案例清單
1. Feature API 測試（P0）
- 歷史堂次（已上/過去日）代課成功，回傳 200。
- 未來堂次且真衝堂，回傳 409，含 `conflicts` 詳細資訊。
- 未來堂次且無衝堂，回傳 200 並建立/更新對應 `schedules`。
- 代課老師與正班老師相同，回傳 422（既有規則不變）。
- 代課老師未綁定分校，回傳 422（既有規則不變）。

2. 資料一致性測試（P0）
- 成功代課後：
  - `schedules` 存在 `rescheduled + scheduled(original_schedule_id)` 配對。
  - `LearningRecord.TeacherID` 更新為代課老師（若該堂有 LR）。
  - `learning_record_teacher_changes` 有 audit 紀錄。
- `StudentClass.TeacherID` 不變（僅單堂替換）。

3. 回歸測試（P0/P1）
- `GET /api/v1/class-sessions` 顯示該堂 `teacher_id/teacher_name` 為代課老師（既有能力不退化）。
- 代課老師可在 `learning-records` 看見該堂（既有能力不退化）。
- 既有 idempotent 行為：同堂重複代課不應新增重複配對排程。

4. 前端整合測試（P1）
- modal 提交成功：顯示成功訊息並刷新課程列表。
- 409 錯誤：顯示可讀衝突訊息（含時段/學生摘要）。
- token 過期/未登入：顯示既有登入提示，不發生 silent fail。

5. 權限與分校隔離（P1）
- director/admin/super_admin 可操作。
- teacher 角色不可操作（403）。
- 不可對非授權分校資料代課（403）。

### 測試資料設計
- 分校：至少建立興隆（主測）+ 另一分校（隔離驗證）。
- 老師：
  - 正班老師 A（興隆）
  - 代課老師 B（興隆）
  - 非興隆老師 C（驗證綁校限制）
- 堂次：
  - 歷史堂次（已上，含 approved LR）
  - 未來堂次（製造衝堂）
  - 未來堂次（不衝堂）

### 建議新增/調整自動化測試檔
- 主檔：`/home/admin/backend/tests/Feature/SubstituteTeacherTest.php`
- 建議新增 case：
  - `test_substitute_allows_past_session_even_when_capacity_would_conflict`
  - `test_substitute_future_session_still_blocked_on_capacity_conflict`
  - `test_substitute_future_session_without_conflict_succeeds`
  - `test_substitute_conflict_response_contains_overlap_details`

### 驗收門檻（Go/No-Go）
- P0 全數通過才可上線。
- 興隆實例重現步驟可穩定通過（至少 2 次）。
- 未來堂次衝堂保護不退化（409 路徑需保留）。
- 無跨分校越權與無帳務/扣堂副作用。

### 上線後監控與抽查
- 監控 `laravel.log` 的 `[substitute]` 記錄：
  - `pre_transaction`, `failed`, `applied` 的比例變化。
- 抽查 1 週內代課操作：
  - 是否再出現「畫面無課但回 409」。
  - 若有，立即抓 `overlap_details` 反查來源（`class_session` 或 `schedule`）。