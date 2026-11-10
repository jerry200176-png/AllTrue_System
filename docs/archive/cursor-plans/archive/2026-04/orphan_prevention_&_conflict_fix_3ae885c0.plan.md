---
name: Orphan Prevention & Conflict Fix
overview: 在排課例外寫入入口加入 ClassSession 存在性驗證、修補衝堂偵測排除邏輯，並修正代課老師選擇器誤把「有剩餘容量的重疊課程」標為衝堂的假陽性問題。
todos:
  - id: verify-index
    content: "[PREP] 用 EXPLAIN 驗證 ClassSession 的 cs_scid_sessiondate_idx 可命中 FR-001 的 EXISTS 查詢；若不足，在 database/migrations/ 新增 artisan migration 補建複合索引"
    status: completed
  - id: be-entry-validation
    content: "[FEATURE-BE] 修改 backend/app/Http/Controllers/ScheduleController.php store 方法：在衝堂偵測前加入 ClassSession EXISTS 驗證（status=scheduled + original_schedule_id > 0），不存在則回 HTTP 422 code=no_class_session"
    status: completed
  - id: be-exclude-id
    content: "[FEATURE-BE] 修改 backend/app/Http/Controllers/ScheduleController.php store 方法：衝堂 guard payload 加入 exclude_schedule_id，從 DB 查出即將被替換的舊 scheduled 列 id 後傳入 ScheduleGuardService"
    status: completed
  - id: be-availability-capacity
    content: "[FEATURE-BE] 修改 backend/app/Services/SubstituteService.php collectTeacherBusySlots：每個 busy slot 加入 class_type 與 student_count 欄位；修改 backend/app/Http/Controllers/SubstituteController.php availability：計算 remaining_capacity = capacityForClassType(class_type) - student_count，回傳給前端"
    status: completed
  - id: fe-availability-fix
    content: "[FEATURE-FE] 修改 frontend/src/components/substitute/SubstituteTeacherPickerModal.vue：conflict 判斷邏輯改為：overlapping slot 的 remaining_capacity === 0 才標為 conflict；remaining_capacity > 0 改設 capacityWarn=true 允許選擇"
    status: completed
  - id: fe-ux-capacity-warning
    content: "[UI/UX-FE] 修改 frontend/src/components/substitute/SubstituteTeacherPickerModal.vue：老師卡片加入三態容量標籤（有空 ✓ 綠 / 尚有容量 ⚠ 橘 / 已滿 ✗ 紅），文字縮短規則與 tooltip 內容依 5b 節規格；375px 容量標籤縮單字"
    status: completed
  - id: fe-error-ux
    content: "[UI/UX-FE] 修改 frontend/src/components/substitute/SubstituteTeacherPickerModal.vue（或 SmartCalendar.vue onSubstituteV2Submit）：攔截 err.status === 422 && err.payload?.code === 'no_class_session'，在 modal 底部 inline 顯示 warning 色系提示文字（依 5b 節措辭），不使用 toast"
    status: completed
  - id: test-implement
    content: "[TEST] 新增 backend/tests/Feature/ScheduleStoreOrphanPreventionTest.php：3 cases（有 ClassSession→201 / 無 ClassSession→422 / leave 不觸發驗證→pass）；新增 AvailabilityCapacityTest：驗證 busy_slots 含 remaining_capacity 欄位且值正確；執行 ./vendor/bin/phpunit --filter ScheduleStoreOrphanPreventionTest 與 AvailabilityCapacityTest 全部通過"
    status: completed
  - id: test-regression
    content: "[TEST] 執行 ./vendor/bin/phpunit 完整測試套件（或 php artisan test）；確認既有 SubstituteTeacherTest / SubstituteUxV2Test / ScheduleController 相關測試全部通過，無新增失敗"
    status: completed
  - id: code-review
    content: "[REVIEW] 讀取所有本次變更的檔案（ScheduleController.php / SubstituteService.php / SubstituteController.php / SubstituteTeacherPickerModal.vue / SmartCalendar.vue），逐項對照 FR-001~006 驗收標準與 STRIDE 快評（重點：FR-001 不洩漏內部 row id、FR-005 response 不含學生姓名），列出任何需修正的問題並修正"
    status: completed
  - id: docs
    content: "[DOCS] 更新 docs/CHANGELOG.md：新增 v1.x 條目，涵蓋 FR-001 ClassSession 入口驗證、FR-002 exclude_schedule_id 修正、FR-005/006 availability 容量回傳與前端容量警示"
    status: completed
  - id: deploy
    content: "[OPS] 在 /home/admin/frontend 執行 npm run build；cp -r dist/. /home/admin/backend/public/；確認 curl /api/v1/health 回傳 status ok；執行 POST /api/v1/admin/cleanup-orphaned-schedules?dry_run=true 確認孤兒數為 0"
    status: completed
isProject: false
---

# PRD：排課例外入口驗證 + 衝堂誤判根本修復

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 排課例外入口驗證（ClassSession Guard）+ 衝堂誤判根本修復 |
| 版本 / 日期 | v1.0 / 2026-04-20 |
| 狀態 | Draft |
| 目標角色 | 主任（透過行事曆指派代課/調課的主要操作者） |

---

## 2. 目標與業務背景

### 痛點

1. **孤兒 schedules 列造成行事曆顯示幽靈課堂**：當「調課日期」把 ClassSession 從日期 A 移到日期 B，但「排課例外」API 此前已在日期 A 建立代課標記，兩者脫鉤後日期 A 的例外列成為孤兒，繼續出現在行事曆中。
2. **孤兒列引發假陽性衝堂錯誤**：孤兒 schedules 列（teacher_id = 某老師、schedule_date = 特定日期）會被衝堂偵測邏輯算入該老師的占用人數，導致後續對同一老師同一日期的代課指派誤判為超過容量上限，操作被拒（HTTP 409）。
3. **衝堂偵測排除邏輯不完整**：`ScheduleController::store` 的衝堂偵測目前只傳入 `exclude_student_id`，未傳入 `exclude_schedule_id`，造成「更換代課老師」時，即將被取代的舊 scheduled 列仍然被計入占用，產生不必要的衝堂錯誤。

### 業務價值

- 主任可信賴行事曆：看到的課堂都是真實存在的。
- 代課指派操作不被虛假的衝堂錯誤阻擋，提高工作效率。
- 系統資料庫乾淨，減少未來難以追蹤的資料異常。

### 成功指標（KPI）

- 孤兒 schedules 列（status=scheduled、無對應 ClassSession）數量維持 **0**（每日可用 `cleanup-orphaned-schedules` API 驗證）。
- 對同一老師重新指派代課時，假陽性衝堂錯誤率降至 **0%**。
- `POST /api/v1/schedules` 在無 ClassSession 情況下建立例外列時，回傳 **422** 而非靜默建立孤兒。

---

## 3. 範圍

### In Scope

- `POST /api/v1/schedules`：加入 ClassSession 存在性驗證（限 `status=scheduled` + `original_schedule_id` 不為空的代課/調課新位置例外列）。
- `POST /api/v1/schedules`：衝堂偵測 payload 補入 `exclude_schedule_id`（即將被取代的舊 scheduled 列）。
- 前端代課/調課 modal：422 `no_class_session` 錯誤的提示文字改善。

### Out of Scope

- 既有孤兒資料的一次性清理（已另行完成，285 筆已刪除）。
- `ClassSessionController::substitute` 的衝堂偵測邏輯（此路徑已有 `exclude_schedule_id`，不在本次範圍）。
- `status=leave` 或 `status=rescheduled` 的請假/調課標記列，不套用 ClassSession 存在性驗證。
- 新增 UI 頁面或重大前端元件。

---

## 4. RACI

| 角色 | 執行者 | R/A/C/I |
|---|---|---|
| AI Agent | 本次計畫的執行主體 | R（所有 todos） |
| PM / Owner | 產品負責人 | A（目標確認；DoD 為客觀指標，AI 自動驗證） |
| 資安 | 靜態分析（code-review todo 涵蓋） | C（STRIDE 由 AI 靜態審查） |

> 本計畫所有 todos 均可由 AI Agent 自動完成：實作、測試執行、靜態程式碼審查、部署、驗證。DoD 均為客觀可驗證指標（test green / HTTP status / log 輸出），無需人工 sign-off。

---

## 5. User Stories

### US-001 阻擋無效排課例外

> **As a** 系統（代表所有建立排課例外的操作方），  
> **I want** 在建立代課/調課新位置標記時確認目標日期有課堂紀錄存在，  
> **so that** 孤兒 schedules 列無法被寫入資料庫，行事曆永遠不會出現幽靈課堂。

**Acceptance Criteria**

- [ ] 當請求為 `status=scheduled` + `original_schedule_id > 0` + `student_course_id > 0` + `schedule_date` 不為空，且該 (student_course_id, schedule_date) 無非 cancelled/voided 的 ClassSession，系統回傳 HTTP **422**，body 含 `code: "no_class_session"`。
- [ ] 當上述條件均符合但 ClassSession **存在**，原有建立流程正常執行，回傳 201。
- [ ] `status=leave` 或 `status=rescheduled` 的請求**不受**此驗證影響。

### US-002 代課老師更換不觸發假陽性衝堂

> **As a** 主任，  
> **I want** 把一堂已有代課老師的課改換為另一位老師時，系統正確計算新老師的時段占用，  
> **so that** 不會因為「舊代課標記」被重複計入占用而誤判衝堂。

**Acceptance Criteria**

- [ ] 對同一課堂的代課標記 POST（`original_schedule_id` 指向同一錨點）時，系統在衝堂偵測中排除即將被替換的舊 scheduled 列。
- [ ] 若新老師在目標時段確實有其他學生且已達容量上限，仍正確回傳 HTTP **409**。

---

## 5b. UI/UX 精緻化需求

本次前端影響兩個元件：**422 錯誤回應顯示**（代課 modal）+ **老師容量警示**（SubstituteTeacherPickerModal）。

**A. 代課 modal 422 錯誤顯示**

| 面向 | 要求描述 |
|---|---|
| **版面層次** | 錯誤訊息顯示在 modal 的送出按鈕上方、表單最後一個欄位下方；字型大小 14px，與其他 inline 錯誤訊息層次一致 |
| **色彩一致性** | 使用既有 `--color-warning`（橘/黃色系）而非紅色（紅色保留給 409 真實衝堂） |
| **互動回饋** | 422 後送出按鈕 loading 消失，訊息 inline 顯示（不用 toast） |
| **空狀態設計** | 不適用 |
| **載入狀態** | 送出期間按鈕顯示 spinner，422 後恢復可按狀態，無 layout shift |
| **防呆設計** | 錯誤文字：「此日期尚未建立課堂，請先在課程管理確認課堂日期，再重新指派代課。」 |
| **響應式** | 沿用 modal 既有響應式設計 |

**B. SubstituteTeacherPickerModal 容量警示（FR-006 新增）**

| 面向 | 要求描述 |
|---|---|
| **版面層次** | 老師列表每列右側顯示容量狀態標籤：「有空 ✓」（綠）/ 「尚有容量 ⚠」（橘）/ 「已滿 ✗」（紅）；標籤寬度固定 64px，不影響老師名稱截斷 |
| **色彩一致性** | 三態顏色對應 `--color-success`、`--color-warning`、`--color-danger`；與既有系統 design token 一致 |
| **互動回饋** | remaining_capacity > 0 但有重疊：可選擇，點選後在 modal 底部顯示橘色提示「此時段重疊課程尚有 N 個空位，仍可繼續」；remaining_capacity = 0：按鈕 disabled，hover 顯示 tooltip「此時段老師已達上限，無法安排」 |
| **空狀態設計** | availability API 載入中：老師列表顯示 skeleton row（3 條），不顯示空白 |
| **載入狀態** | 使用者選擇目標日期後，availability 查詢期間每個老師列顯示 skeleton，完成後更新容量標籤 |
| **防呆設計** | 若 availability API 失敗（網路錯誤），老師標籤顯示「─」（無法判斷），仍可選擇，提交後由後端 guard 決定 |
| **響應式** | 容量標籤在手機寬度 < 375px 時縮為單字（「滿」「空」「有」），不換行 |

---

## 6. 功能需求（FR）

**FR-001**：`POST /api/v1/schedules` 收到 `status=scheduled`、`original_schedule_id > 0`、`student_course_id > 0`、`schedule_date` 不為空時，系統應在執行衝堂偵測**前**查詢 ClassSession 是否存在於 `(StudentClassID = student_course_id, DATE(SessionDate) = schedule_date, Status NOT IN ['cancelled','voided'])`；不存在時回傳 `{"message": "...", "code": "no_class_session"}` + HTTP 422。

**FR-002**：`POST /api/v1/schedules` 的衝堂偵測 (`ScheduleGuardService::validateScheduleOccurrence`) payload 應包含 `exclude_schedule_id`：當 `original_schedule_id > 0` 時，查詢資料庫中是否有符合相同 `(student_course_id, schedule_date, start_time, status=scheduled, original_schedule_id)` 的既有列，若有則將其 `id` 作為 `exclude_schedule_id` 傳入。

**FR-003**：FR-001 的驗證**不適用**於 `status=leave`、`status=rescheduled`，或 `original_schedule_id` 為空的純排程列；這些路徑行為維持原樣。

**FR-004**：前端代課/調課 modal 收到 HTTP 422 且 `code === "no_class_session"` 時，應在表單底部顯示正向引導錯誤文字（見 5b 節），使用 `--color-warning` 色系，不使用 toast。

**FR-005**：`GET /api/v1/teachers/{id}/availability` 回傳的每個 `busy_slot` 應加入 `class_type`（該課程型別）與 `remaining_capacity`（整數，0 表示真正滿載）欄位。`remaining_capacity` 計算方式：查詢 `buildTeacherDateOccupancyEntries` 在該時段的現有學生數，以 `capacity_for_class_type - existing_count` 得出；若 `existing_count >= capacity`，則 `remaining_capacity = 0`。

**FR-006**：`SubstituteTeacherPickerModal` 老師列表的衝堂判斷邏輯應改為：當所有與目標時段重疊的 `busy_slots` 之 `remaining_capacity` 都等於 0 時，才將該老師標為「衝堂（不可選）」；若有任何 `remaining_capacity > 0` 的重疊 slot，改顯示橘色「此時段尚有容量，可繼續」警示，但允許選擇。

---

## 7. 非功能需求（NFR）

- **效能**：FR-001 的 ClassSession 存在性查詢使用 `EXISTS` 子查詢，索引命中 `(StudentClassID, SessionDate, Status)`，額外延遲應 < 10ms。
- **向下相容**：FR-001/002 均在現有 API 路徑上加防護，不改變 API 契約（成功路徑回傳結構不變）。
- **錯誤降級**：若 ClassSession 資料表查詢發生 DB 異常，例外應向上拋出至 Laravel 的統一錯誤處理，回傳 500，不靜默吞掉錯誤。

---

## 8. 技術方向（給 CTO）

### 受影響的 API / 資料表

| 層級 | 名稱 |
|---|---|
| API | `POST /api/v1/schedules` |
| API | `GET /api/v1/teachers/{id}/availability`（回傳欄位擴充） |
| Controller | `ScheduleController`（`store` 方法） |
| Controller | `SubstituteController`（`availability` 方法） |
| Service | `ScheduleGuardService`（`validateScheduleOccurrence` 的 payload 結構） |
| Service | `SubstituteService`（`collectTeacherBusySlots`，需回傳容量資訊） |
| 資料表（讀） | `schedules`、`ClassSession`、`StudentClass` |
| 前端頁面 | `SmartCalendar.vue`（代課 modal 的錯誤處理段落） |
| 前端元件 | `SubstituteTeacherPickerModal.vue`（衝堂判斷邏輯 + 容量警示 UI） |

### 架構選擇理由

- **入口驗證（FR-001）置於衝堂偵測之前**：「目標日期無 ClassSession」是資料前提錯誤，應在衝堂偵測（業務邏輯）之前快速失敗，避免無意義的複雜查詢。
- **exclude_schedule_id 在 store 層解析（FR-002）**：Controller 最清楚「即將被取代的 scheduled 列是哪一筆」，由 Controller 查出 id 後傳入 Service，維持 Service 的 stateless 特性。
- **capacity 資訊在 availability 端點回傳（FR-005）**：前端選老師時需要判斷「這個重疊是否可以接受」，capacity 資訊由後端計算最可靠；前端只負責顯示邏輯，不重複實作容量計算。
- **不需要 migration**：本次修改均為讀取現有資料的防護邏輯，不新增欄位。

### 子任務 Agent 派發（對應 todos 順序）

1. `verify-index` → EXPLAIN 驗證 + 視情況產出 migration
2. `be-entry-validation` + `be-exclude-id` → 同一次修改 `ScheduleController.php`，可合批執行
3. `be-availability-capacity` → 修改 `SubstituteService.php` + `SubstituteController.php`
4. `fe-availability-fix` + `fe-ux-capacity-warning` → 同一個元件 `SubstituteTeacherPickerModal.vue`，可合批執行
5. `fe-error-ux` → 修改 `SubstituteTeacherPickerModal.vue` 或 `SmartCalendar.vue`
6. `test-implement` → 新增兩個 Pest test 檔並執行
7. `test-regression` → 執行完整 phpunit
8. `code-review` → 讀取所有變更檔，逐條對照 FR + STRIDE，輸出審查結果
9. `docs` → 更新 CHANGELOG.md
10. `deploy` → build + cp + health check

---

## 9. 資安與存取控制

- `POST /api/v1/schedules` 已受 `role:director` + `require_campus` middleware 保護，本次不修改存取控制。
- FR-001 的 422 回應包含業務錯誤碼 `no_class_session`，不洩漏 DB 內部欄位或 row ID，無 Information Disclosure 風險。
- **STRIDE 快評**：
  - **Spoofing**：無影響（JWT 驗證不變）。
  - **Tampering**：入口驗證減少孤兒資料污染，降低 Tampering 風險。
  - **Repudiation**：現有 Log::warning 已記錄衝堂事件，本次不需新增稽核 log。
  - **Information Disclosure**：422 body 使用業務語言，無技術細節洩漏。
  - **Denial of Service**：EXISTS 查詢加索引，無 N+1 風險。
  - **Elevation of Privilege**：無影響。

---

## 10. QA 驗收標準

### FR-001 驗收

| 情境 | 輸入 | 預期結果 |
|---|---|---|
| Happy Path | status=scheduled + origId + 有 ClassSession | HTTP 201，schedules 列建立成功 |
| Edge：ClassSession status=cancelled | 目標日期只有 cancelled ClassSession | HTTP 422，code=no_class_session |
| Error：無 ClassSession | 目標日期無任何 ClassSession | HTTP 422，code=no_class_session |
| 不適用路徑 | status=leave | 原有請假流程，不觸發此驗證 |
| 不適用路徑 | status=rescheduled | 原有調課標記流程，不觸發此驗證 |
| 不適用路徑 | status=scheduled 但 origId 為空 | 原有建立流程，不觸發此驗證 |

### FR-002 驗收

| 情境 | 輸入 | 預期結果 |
|---|---|---|
| Happy Path：更換代課老師 | 同一 origId，新老師時段空閒 | 衝堂偵測通過，舊 scheduled 列被更新，HTTP 201 |
| 真實衝堂 | 新老師已有其他學生且達容量上限 | HTTP 409，衝堂錯誤訊息正確 |

### FR-004 驗收（前端）

| 情境 | 輸入 | 預期結果 |
|---|---|---|
| 422 no_class_session | 代課 modal 收到 422 | modal 內顯示 warning 色系提示文字，按鈕 spinner 消失，可重新操作 |
| 其他 422 | 非 no_class_session 的 422 | 顯示通用錯誤訊息（現有行為不變） |

### FR-005/006 驗收（容量誤判修正）

| 情境 | 輸入 | 預期結果 |
|---|---|---|
| 老師有 one_on_two 課程（1/2 佔用） | availability date=4/22 teacher=66 | busy_slots 含 `remaining_capacity: 1`，前端顯示「尚有容量 ⚠」標籤，可選 |
| 老師有 one_on_one 課程（1/1 佔用） | availability teacher X, 已有 1 位學生 | busy_slots 含 `remaining_capacity: 0`，前端顯示「已滿 ✗」標籤，不可選 |
| 老師無任何課程 | availability teacher Y | busy_slots = \[\]，前端顯示「有空 ✓」標籤 |
| 實際案例 | 游家豫 4/21→4/22 陳章華 18:30-20:30 | 陳章華顯示「尚有容量 ⚠」而非「已滿 ✗」，可選，送出後 HTTP 200 成功 |

### 回歸測試

- 現有 `SubstituteTeacherTest`、`SubstituteUxV2Test` 全部通過（不受本次修改影響）。
- `ScheduleController::store` 的 leave/rescheduled 路徑全部通過。

### UI/UX 驗收清單

**A. 422 錯誤顯示**
- [ ] 422 no_class_session 訊息使用 `--color-warning` 色系，非 `--color-danger`
- [ ] 訊息為 inline 顯示（在 modal 表單底部），不使用 toast
- [ ] 措辭為正向引導語，不含技術術語（ClassSession / 422 / DB 等詞）
- [ ] 送出按鈕在 422 後恢復可按狀態，spinner 消失
- [ ] 訊息文字在手機尺寸（375px）下不溢出 modal

**B. 容量警示（FR-006）**
- [ ] remaining_capacity = 0 的老師顯示紅色「已滿 ✗」且不可選，hover 顯示 tooltip
- [ ] remaining_capacity > 0 有重疊的老師顯示橘色「尚有容量 ⚠」且可選
- [ ] 無重疊或無 busy slot 的老師顯示綠色「有空 ✓」
- [ ] availability API 載入中顯示 skeleton，失敗時顯示「─」不阻擋操作
- [ ] 375px 寬度下容量標籤縮短為單字（「滿」「有」「空」），不換行

---

## 11. 上線與維運

### 部署步驟

1. 後端：部署 `ScheduleController.php` 修改（無 migration，直接生效）。
2. 前端：`npm run build && cp -r dist/. backend/public/`。
3. 確認 `GET /api/v1/health` 回傳 `status: ok`。
4. 可用 `POST /api/v1/admin/cleanup-orphaned-schedules?dry_run=true` 確認孤兒數為 0。

### 監控

- 觀察 Laravel log 中 `Schedule create conflict` 的 `no_class_session` 出現頻率（應趨近 0）。

### 回滾方案

- 後端：`git revert` 對應 commit，重新部署即可（無 DB schema 變更，無需 migration rollback）。
- 前端：重新部署前一版 assets。

---

## 12. 里程碑與優先級

| 優先級 | 功能項目 | 預估工期 | 執行 Agent |
|---|---|---|---|
| 優先級 | todo id | 功能項目 | 估時 |
|---|---|---|---|
| P0 | `verify-index` | DB 索引確認 / 補建 | 0.2h |
| P0 | `be-entry-validation` | FR-001 ClassSession 入口驗證 | 0.5h |
| P0 | `be-exclude-id` | FR-002 exclude_schedule_id 補入 | 0.5h |
| P0 | `be-availability-capacity` | FR-005 availability 回傳容量資訊 | 1h |
| P0 | `fe-availability-fix` | FR-006 衝堂邏輯改用 remaining_capacity | 0.5h |
| P0 | `fe-ux-capacity-warning` | FR-006 三態容量標籤 UI | 0.5h |
| P1 | `fe-error-ux` | FR-004 前端 422 提示文字 | 0.5h |
| P1 | `test-implement` | Pest Feature Test（FR-001/005） | 1.5h |
| P1 | `test-regression` | 完整套件回歸驗證 | 0.2h |
| P1 | `code-review` | 靜態分析 + STRIDE 確認 | 0.5h |
| P2 | `docs` | CHANGELOG 更新 | 0.2h |
| P2 | `deploy` | Build + 部署 + health check | 0.3h |

---

## 13. 風險、假設、開放問題

### 風險與業界緩解方案

| 風險 | 等級 | 業界標準解法 | 本專案採行方式 |
|---|---|---|---|
| FR-001 EXISTS 查詢在高並發下增加延遲 | 低 | Google/Stripe 使用 **Partial Index** 限制掃描範圍（如 `WHERE Status NOT IN (...)` 的條件索引） | 現有 `cs_scid_sessiondate_idx(StudentClassID, SessionDate)` 已覆蓋主要過濾條件；`verify-index` todo 以 `EXPLAIN` 確認；若不足則新增 migration 補建 |
| `mergeBusySlots` 合併多個 slots 後遺失 `class_type` 資訊，導致 FR-005 無法計算 remaining_capacity | 中 | Google Calendar Freebusy API 保留每個 event 的完整 metadata，**不 merge**，由 client 決定顯示策略 | FR-005 新增 raw slot 回傳路徑（`collectTeacherBuslySlotsWithCapacity`），不使用 `mergeBusySlots`；原有 `collectTeacherBusySlots` 行為不變，向下相容 |
| 前後端 CAPACITY_MAP 不一致（前端若自行維護容量規則，日後擴充類型時需同步更新） | 中 | Shopify、Linear 等系統將業務 rule 統一放後端，前端只消費計算結果，**Single Source of Truth** | FR-005/006 已採此原則：後端計算 `remaining_capacity` 整數下傳，前端不持有 capacity map，消除不一致風險 |
| 合法的「先建排課例外再建 ClassSession」流程被 FR-001 阻擋 | 中 | Shopify inventory 用 **Two-Phase Write**（reserve → confirm）；Saga pattern 允許先 draft 後 commit | 本次維持系統規範「ClassSession 先行」；若有邊緣流程需要先行建立 schedules，可引入 `status=draft` 繞過驗證，屬獨立 feature，**Out of Scope** |
| SubstituteTeacherPickerModal 為每個老師各發一次 availability 請求，30 個老師 = 30 個並行請求，慢速網路下 UI 閃爍 | 低 | Gmail 聯絡人補全採 **debounce + batch endpoint**（單次請求多個聯絡人）；Promise.all 並行已比 sequential 好 | 目前前端已用 `Promise.all` 並行發送（非 sequential N+1），延遲可接受；若未來老師數量增加，可新增 `POST /api/v1/teachers/batch-availability` batch endpoint 作為優化 |
| FR-005 回傳過多 class_type 資訊可能被外部呼叫者推斷教學安排 | 低 | OWASP API Security 建議 **Minimum Disclosure**：API response 只回業務需要的最小欄位集 | `remaining_capacity` 為整數（不含學生姓名 / 課程 id），`class_type` 限 enum 值，無 PII；`code-review` todo 靜態確認 |

### 假設

- **ClassSession 先行原則**（已確認）：所有 `status=scheduled + original_schedule_id > 0` 的排課例外必定以對應 ClassSession 存在為前提，此為系統設計契約，FR-001 驗證基於此假設。
- **featureSubstituteV2 = true**（已確認為 default）：前端代課 V2 Modal (`SubstituteTeacherPickerModal`) 為主要操作路徑，FR-005/006 修改在此路徑生效。
- **現有 `cs_scid_sessiondate_idx` 足夠**（待 `verify-index` todo 以 EXPLAIN 驗證）：若不足，`verify-index` todo 產出 migration 補建。
- **FR-005 raw slot 不使用 `mergeBusySlots`**：新增獨立方法，確保現有跨分校衝堂偵測路徑（使用 `mergeBusySlots`）行為不變。

### 開放問題

- ~~前端代課路徑是否呼叫 `POST /api/v1/schedules`~~：**已確認**。主路徑為 `ClassSessionController::substitute`；`POST /api/v1/schedules` 為邊緣路徑，FR-001/002 仍需保護。
- ~~`collectTeacherBusySlots` 索引確認~~：**由 `verify-index` todo AI 自動執行 EXPLAIN + 如有不足自動補 migration**，無需人工確認。

---

## 14. Definition of Done

以下所有項目由 AI Agent 執行並自動驗證，無需人工介入：

- [ ] `verify-index`：EXPLAIN 輸出顯示 `cs_scid_sessiondate_idx` 命中（或補建 migration 後命中）
- [ ] `be-entry-validation`：`POST /api/v1/schedules` 無 ClassSession 時回 422 + `code=no_class_session`（Pest test green）
- [ ] `be-exclude-id`：同一 origId 更換代課老師不觸發假陽性 409（Pest test green）
- [ ] `be-availability-capacity`：`GET /api/v1/teachers/{id}/availability` busy_slots 含 `remaining_capacity` 整數欄位（Pest test green）
- [ ] `fe-availability-fix` + `fe-ux-capacity-warning`：`SubstituteTeacherPickerModal.vue` `remaining_capacity > 0` 的老師顯示橘色「尚有容量」且可選
- [ ] `fe-error-ux`：422 `no_class_session` 在 modal 底部 inline 顯示 warning 色提示文字
- [ ] `test-regression`：`./vendor/bin/phpunit` 全套測試通過，無新增失敗
- [ ] `code-review`：靜態分析確認 STRIDE 快評無阻擋項，FR-005 response 不含 PII
- [ ] `docs`：`docs/CHANGELOG.md` 含本次所有 FR 條目
- [ ] `deploy`：`GET /api/v1/health` 回 `status: ok`；cleanup dry_run 回孤兒數 0
