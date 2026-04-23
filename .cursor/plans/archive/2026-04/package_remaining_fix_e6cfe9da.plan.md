---
name: Package Remaining Fix
overview: 雙層修正：(1) 後端新增「方案加購堂數」機制，PUT /api/v1/course-packages/{id} 支援修改 total_sessions 並同步所有成員課程的 SessionCount 與排課；(2) 前端改用方案池值顯示剩餘堂數，消除「平分」的誤導顯示與 UI 警告誤報。
todos:
  - id: preflight-read
    content: "[PRE-FLIGHT] 讀取確認程式位置：(1) CoursePackageController::update 行508-564（validation、cascade 邏輯）；(2) StudentClassController::cancelExcessScheduledSessions 行2772、extendSessionsIfNeeded 行2798（確認為 private）；(3) useCourseSessionsDisplay.js 的 displayRemainingSessions 與 sessionCountWarning；(4) StudentsList.vue 剩餘堂數主要顯示區塊；(5) CoursePackagesPage.vue edit modal 區塊（確認是否有 total_sessions 欄位）。記錄實際行號後才進入下一步。"
    status: completed
  - id: backend-make-scheduling-public
    content: "[BACKEND] StudentClassController.php：將行2772 'private function cancelExcessScheduledSessions' 改為 'public function cancelExcessScheduledSessions'；將行2798 'private function extendSessionsIfNeeded' 改為 'public function extendSessionsIfNeeded'。只改存取修飾子，不動方法本體。完成後立即執行 php artisan test --filter SessionCountWarningTest 確認 5 案例（CaseA~E）仍全部綠燈（AI_REGRESSION_LESSONS §2026-04-15 回歸守護）。"
    status: completed
  - id: backend-total-sessions-api
    content: "[BACKEND] CoursePackageController::update（行521-563）新增 total_sessions 支援，步驟：(A) validation rules 加入 'total_sessions' => 'nullable|integer|min:1|max:999'；(B) 若 $pkg->billing_mode !== 'count'，return 422 '月結方案不支援修改總堂數'；(C) 若 total_sessions 傳入且 !== 現有值：guard new_total < $pkg->used_sessions 時 return 422 '新總堂數不可小於已使用堂數（N 堂）'；(D) DB::transaction(function() use ($pkg, $newTotal) { ... }) 包裹：(D1) StudentClass::where('PackageID',$pkg->id)->update(['SessionCount'=>$newTotal,'PackageTotalSessions'=>$newTotal])（僅這兩欄，不含 Charge/Rate/RemainingSessions，RISK-002 守護）；(D2) $controller = app()->make(StudentClassController::class); foreach (StudentClass::where('PackageID',$pkg->id)->get() as $member) { $controller->cancelExcessScheduledSessions($member->ID,$newTotal); $controller->extendSessionsIfNeeded($member,$newTotal); }（(D3) $pkg->remaining_sessions = $newTotal - $pkg->used_sessions; $pkg->total_sessions = $newTotal; $pkg->save()；(E) Log::info('CoursePackage totalSessions changed',[...before/after/member_count/by_user，格式參照 rebuildLedger 行422-428]；(F) response 包含 'cancelled_sessions' => $cancelledList（收集 (D2) 中被取消的 ClassSession id list）。"
    status: completed
  - id: backend-risk002-verify
    content: "[REVIEW/RISK-002] 閱讀 AI_REGRESSION_LESSONS §2026-04-17「Rate/SessionCount 異動保留 delta（preserved_delta）」；確認 backend-total-sessions-api 的 bulk update 語句只包含 SessionCount 和 PackageTotalSessions，不含 Charge；確認 update 路徑不會觸發 StudentClassController::update 的 preserved_delta 重算（因為我們直接走 Eloquent bulk update 繞過 Controller，不觸發 $mapped['SessionCount'] 分支）。"
    status: completed
  - id: frontend-composable
    content: "[FRONTEND] 修改 frontend/src/composables/course-management/useCourseSessionsDisplay.js：(1) displayRemainingSessions：在現有邏輯前加判斷 if (course?.PackageID) return { rem: course.package_remaining_sessions ?? 0, total: course.package_total_sessions ?? 0, isPackage: true }；(2) sessionCountWarning：在 getPurchasedSessions(course) 呼叫前加 if (course?.PackageID) purchased = course.package_total_sessions ?? 0（方案課程以池總數為基準，消除 SessionCount 脫鉤的誤報）；(3) 不修改 effectiveSessionCount()、SESSION_NOT_OCCUPYING_QUOTA 常數、非方案課程邏輯；(4) 修改後再次執行 php artisan test --filter SessionCountWarningTest（CaseA~E 全綠，確保請假假陽性修正未被回歸）。"
    status: completed
  - id: frontend-studentslist
    content: "[FRONTEND] 修改 frontend/src/pages/StudentsList.vue：(1) 搜尋「剩餘堂數」主要顯示區塊，找到方案課程分支（PackageID 存在），將 RemainingSessions/SessionCount 替換為 package_remaining_sessions/package_total_sessions；(2) 移除「方案池 X/Y 堂」次要小字（搜尋 tag-package-hint 附近的小字節點）；(3) 進度條 width style：PackageID 存在時改用 package_used_sessions/package_total_sessions 計算百分比；(4) 加購按鈕警示（btn-renew-warn 或等效 class）觸發條件：PackageID 存在時改為 package_remaining_sessions <= 2；(5) 加購 modal 中「目前剩餘」標籤文字改為「目前剩餘（方案池）」，值改用 package_remaining_sessions；(6) 所有 package_remaining_sessions 讀取加 ?? 0（NFR-002 null guard）。"
    status: completed
  - id: frontend-package-modal
    content: "[FRONTEND] 修改 frontend/src/pages/CoursePackagesPage.vue 的 edit/update modal（搜尋現有 updatePackage 呼叫的表單區塊）：(1) 若 edit modal 不含 total_sessions 欄位，新增 number input（min=1，v-model 綁定 editForm.total_sessions，預設值 = pkg.total_sessions）；(2) inline guard：若 editForm.total_sessions < pkg.used_sessions，顯示橘色警告文字「新總堂數不可小於已使用堂數（N 堂）」且 submit disabled；(3) 若 editForm.total_sessions < pkg.total_sessions，在 submit 前 confirm()「減少後將取消未來排課，確認繼續？」；(4) updatePackage 呼叫加入 total_sessions 欄位；(5) 成功後 toast/alert「方案已更新，排課同步完成」；(6) 送出期間 submit button disabled 防止重複點擊。（coursePackagesApi.js::updatePackage 已接受任意 payload，JSON.stringify(payload)，無需改動）"
    status: completed
  - id: test-backend-sync
    content: "[TEST] 新增 backend/tests/Feature/PackageTotalSessionsSyncTest.php，測試案例（參照 PackageDisplayAndGuardTest.php 的 setUp/factory 格式）：(1) test_increase_total_sessions_extends_all_members：4→8，兩科各補4堂 ClassSession，SessionCount=8；(2) test_decrease_total_sessions_cancels_excess_scheduled：8→5，最後3堂 scheduled 被取消，attended 不受影響；(3) test_same_value_is_noop：傳入相同值 200 OK，DB 無變更；(4) test_below_used_sessions_returns_422；(5) test_three_subjects_all_extended：3科方案全部補排；(6) test_sync_does_not_alter_charge：加購後每科 StudentClass.Charge 不變（RISK-002）；(7) test_monthly_package_returns_422；執行 php artisan test --filter PackageTotalSessionsSyncTest 確認全部通過。"
    status: completed
  - id: test-regression-guard
    content: "[TEST/REGRESSION] 執行 php artisan test --filter 'SessionCountWarningTest|PackageDisplayAndGuardTest' 確認所有已存在測試仍通過；若 SessionCountWarningTest 有失敗，回查 frontend-composable 的修改是否影響了後端測試邏輯（注意：兩者是否共用同一 composable 邏輯或後端有獨立實作），修正後再跑。"
    status: completed
  - id: backfill-artisan
    content: "[FEATURE] 新增 backend/app/Console/Commands/SyncPackageSessionCounts.php（php artisan packages:sync-session-counts）：(A) 支援 --dry-run flag；(B) 查詢 StudentClass WHERE PackageID IS NOT NULL AND SessionCount != (SELECT total_sessions FROM course_packages WHERE id=PackageID)；(C) dry-run：只列出差異（package_id, student_class_id, current_count, correct_count）；(D) 正式執行：bulk update SessionCount+PackageTotalSessions，並對 current < correct 的成員呼叫 extendSessionsIfNeeded；(E) 在 app/Console/Kernel.php 或 routes/console.php 中以 Artisan::command 或 $commands[] 形式註冊；輸出每步操作結果。"
    status: completed
  - id: security-access-control
    content: "[資安] 確認 CoursePackageController::update 新增的 total_sessions 邏輯走現有 role guard（行515-519：director/admin/super_admin + campus 隔離）；確認 billing_mode guard 已加入；確認 Log::info 包含 total_sessions before/after 和 auth_user id（參照 rebuildLedger Log 格式行422-428）；檢查 STRIDE Tampering 威脅：new_total < used_sessions 的 422 guard 是否已覆蓋惡意縮減場景。"
    status: completed
  - id: code-review-final
    content: "[REVIEW] 最終審查：(1) 搜尋 StudentsList.vue 和 CourseManagement.vue 中所有 remaining_sessions、RemainingSessions 參考點，確認方案課程分支全部改用 package_remaining_sessions（無遺漏）；(2) 確認非方案課程的顯示邏輯與修改前完全一致（FR-007 回歸保護）；(3) 確認 extendSessionsIfNeeded 呼叫是 append-only（不整刪重建，RISK-001）；(4) ReadLints 檢查所有已修改的 .php 和 .vue 檔案的 linter 錯誤並修正。"
    status: completed
  - id: docs-changelog
    content: "[DOCS] 更新 docs/CHANGELOG.md，新增條目（日期 2026-04-20）：(1) 修正方案共用課程剩餘堂數顯示（StudentsList + CourseManagement 改用方案池剩餘/總購買數）；(2) PUT /api/v1/course-packages/{id} 新增 total_sessions 欄位，加購後自動同步成員 SessionCount 與排課補排；(3) 新增 Artisan command packages:sync-session-counts 供歷史資料修復；(4) StudentClassController::extendSessionsIfNeeded + cancelExcessScheduledSessions 改為 public 以支援跨 Controller 呼叫。"
    status: completed
  - id: deploy
    content: "[部署] (1) 後端：git add -A && git commit -m '...' && git push（無 migration）；(2) 前端：cd /home/admin/frontend && npm run deploy，確認輸出顯示 index.html 和 assets/ 同輪更新；(3) 驗收：打開有方案課程的學生頁面，確認剩餘堂數顯示方案池值（非 per-course 值）；(4) 在方案管理頁對一個測試方案加購 1 堂，確認對應課程自動補排排課，且 SessionCount 更新為新值；(5) 執行 php artisan packages:sync-session-counts --dry-run 確認歷史資料差異報告輸出正常。"
    status: completed
isProject: false
---

# PRD — 方案共用課程堂數同步與剩餘顯示修正

## 1. 文件資訊

| 欄位 | 內容 |
|------|------|
| 功能名稱 | 方案共用課程堂數同步（後端）+ 剩餘堂數正確顯示（前端） |
| 版本 / 日期 | v1.1 / 2026-04-20 |
| 狀態 | Draft |
| 目標角色 | 主任（加購方案堂數、查看課程剩餘進度） |

---

## 2. 目標與業務背景

### 痛點（雙層）

**層一 — 顯示問題（前端）**：主任在學生列表看到方案課程的剩餘堂數顯示「4 / 4 堂（方案共用）」加上小字「方案池 8/8 堂」，數字矛盾，主任無法直接判斷還有幾堂可上。

**層二 — 排課同步問題（後端，根本原因）**：方案的加購堂數（`total_sessions` 增加）後，系統沒有機制同步更新各科目課程的 `SessionCount`。`SessionCount` 控制了排課延伸（`extendSessionsIfNeeded`）的上限，若停留在舊值，加購後的堂數不會自動補排；若後來手動調整個別課程的 `SessionCount`，方案池的 `total_sessions` 又不同步，造成「排程列數與購買堂數不一致」警告持續出現。

**現況確認**：
- `PUT /api/v1/course-packages/{id}` 目前不接受 `total_sessions` 修改
- `createMultiSubject` 建立時每科 `SessionCount = total_sessions`（不平分，設計正確）
- 扣堂邏輯（`PackageDeductionService`）走方案池 ledger，不受 `SessionCount` 影響（扣堂正確）
- **唯一有問題的是**：`SessionCount` 與 `total_sessions` 脫鉤後，排課延伸上限錯誤 + 顯示數字錯誤

### 業務價值

- 主任加購方案堂數後，系統自動補排所有科目的課，無需手動一科一科調整
- 主任在所有頁面看到的「剩餘堂數」就是方案池剩餘，數字一致且直覺
- 「排程列數與購買堂數不一致」警告只在真正異常時出現，不再誤報

### 成功指標 (KPI)

- 方案加購堂數後，所有成員科目的 `SessionCount` 自動更新，與 `total_sessions` 一致
- 學生列表與課程管理頁的剩餘堂數顯示值，與方案管理頁的「方案池剩餘」數字一致，誤差 = 0
- 「排程列數與購買堂數不一致」警告誤報率降為 0

---

## 3. 範圍

### In Scope

- **後端**：`PUT /api/v1/course-packages/{id}` 新增 `total_sessions` 欄位，更新後同步所有成員課程的 `SessionCount` 並觸發排課延伸/取消
- **前端**：學生管理頁、課程管理頁的剩餘堂數主要顯示數字改為方案池值
- **前端**：`sessionCountWarning` 的基準數改為 `package_total_sessions`（方案課程），消除誤報警告
- **前端**：進度條、加購按鈕警示、加購 modal 剩餘數字改為方案池值

### Out of Scope

- 方案管理頁（PackageManagement）的加購 UI 元件本次不另建新頁面，沿用現有 package update modal（僅新增 total_sessions 欄位輸入）
- 月結方案（billing_mode = date）不涉及 SessionCount，本次不修改
- 非方案課程的所有顯示與加購邏輯不受影響
- 家長端（ParentPortal）不顯示剩餘堂數欄位，本次不修改
- `PackageDeductionService` 池扣堂邏輯（已正確）不修改

---

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|------|------|---------|
| PM | 產品負責人 | A（負責簽核） |
| CTO / 工程 Lead | 後端 + 前端工程師 | R（實作） |
| UI/UX Designer | 設計師 | R（5b 節精緻化規格與 sign-off） |
| QA | 測試工程師 | R（驗收） |
| 資安 | 資安工程師 | C（新 API 欄位的存取控制審查） |
| IT / Ops | 運維人員 | I（部署通知） |

---

## 5. User Stories

### US-001 — 主任加購方案堂數後自動補排課

> **As a** 主任，**I want** 在方案編輯畫面調高總堂數後，系統自動補排所有科目的課，**so that** 我不需要手動進入每一科調整排課。

Acceptance Criteria：
- [ ] 編輯方案 modal 新增「總堂數」欄位，可輸入正整數
- [ ] 儲存後，方案 `total_sessions` 更新，所有成員科目的 `SessionCount` 同步為新值
- [ ] 若新值 > 舊值，所有成員科目自動補排缺少的課次（延伸至新 SessionCount）
- [ ] 若新值 < 舊值，所有成員科目取消超額的未來「scheduled」排課（已上課 attended/leave 不影響）
- [ ] 操作後方案池 `remaining_sessions` 正確重算（ledger 不變，total_sessions 增加則 remaining 增加）

### US-002 — 主任查看方案課程剩餘堂數

> **As a** 主任，**I want** 在學生列表與課程管理頁看到方案課程的剩餘堂數顯示整個方案池的剩餘，**so that** 我能立即判斷方案還剩幾堂，無需額外計算。

Acceptance Criteria：
- [ ] 方案課程剩餘堂數格式：「**N** / M 堂（方案共用）」，N = 方案池剩餘，M = 方案池總購買數
- [ ] 同方案下的多科課程，列表上每科顯示相同的方案池剩餘數字
- [ ] 「排程列數與購買堂數不一致」警告改以 `package_total_sessions` 為基準，不再因 SessionCount 舊值誤報

### US-003 — 主任加購 modal 看到正確剩餘

> **As a** 主任，**I want** 在加購 modal 看到方案池的當前剩餘，**so that** 我能判斷是否需要增加方案堂數。

Acceptance Criteria：
- [ ] 加購 modal「目前剩餘（方案池）」顯示方案池剩餘，非個別課程分攤值
- [ ] 加購按鈕警示（續報加購）以方案池剩餘 ≤ 2 觸發

---

## 5b. UI/UX 精緻化需求

### 方案編輯 modal（現有 modal 新增欄位）

| 面向 | 要求描述 |
|------|---------|
| **版面層次** | 新增「總堂數」欄位（number input）置於方案名稱下方；目前剩餘以唯讀文字顯示於欄位旁（灰色小字「目前已用 N 堂，修改後剩餘將重新計算」），使主任清楚操作影響 |
| **色彩一致性** | 欄位樣式沿用現有 form-group 規格；若輸入值小於目前已用堂數（`used_sessions`），inline 警告以橘色顯示「新總堂數不可小於已使用堂數（N 堂）」 |
| **互動回饋** | 儲存時 button 出現 loading spinner；成功後 toast「方案已更新，排課同步完成」（持續 3 秒）；失敗時 toast 紅色錯誤訊息 |
| **空狀態設計** | 不適用（為既有 modal 新增欄位） |
| **載入狀態** | 儲存期間 modal 按鈕 disabled + loading 樣式，防止重複提交 |
| **防呆設計** | 總堂數 < 已用堂數：inline 錯誤，不允許送出；減少堂數：「減少後將取消 N 堂未來排課，確認繼續？」二次確認 dialog |
| **響應式** | 主任桌面端操作，沿用現有 modal 斷點規格 |

### 學生管理頁（StudentsList）— 剩餘堂數欄

| 面向 | 要求描述 |
|------|---------|
| **版面層次** | 「**N** / M 堂」中 N 粗體；「（方案共用）」tag 使用現有 `.tag-package-hint` 綠色小標；移除「方案池 X/Y 堂」次要小字（已整合至主要顯示，避免重複） |
| **色彩一致性** | 方案課程進度條一律綠色（`#2e7d32`）；警示紅/橘色僅在方案池剩餘 ≤ 2 時觸發 |
| **互動回饋** | 加購按鈕由 ghost → `btn-renew-warn` 橘色警示的條件改為方案池剩餘 ≤ 2 |
| **空狀態 / 載入 / 防呆** | 本次為顯示邏輯修改，不新增 loading 狀態；降級處理：`package_remaining_sessions` 為 null 時顯示 0，不報錯 |
| **響應式** | 主任桌面端，沿用現有標準 |

### 課程管理頁（CourseManagement）— 剩餘堂數欄

| 面向 | 要求描述 |
|------|---------|
| **版面層次** | 現有格式「N（方案共用）」，N 改為方案池剩餘 |
| **色彩一致性** | `.low` 橘色 class 觸發條件改為方案池剩餘 ≤ 2 |
| **互動回饋** | 操作選單「加購堂數 / 續報加購」警示條件與學生列表一致 |

---

## 6. 功能需求 (FR)

**FR-001 — 方案 API 支援修改 total_sessions**

`PUT /api/v1/course-packages/{id}` 應接受 `total_sessions` 欄位（正整數）。當傳入值與現有 `total_sessions` 不同時，系統應：
- 更新 `course_packages.total_sessions`
- 同步更新所有成員 `student_classes.SessionCount` 為新值
- 同步更新所有成員 `student_classes.PackageTotalSessions` 為新值
- 若新值 > 舊值：對每個成員呼叫排課延伸邏輯，補排至新 SessionCount
- 若新值 < 舊值：不可小於 `used_sessions`（422 拒絕）；對每個成員取消超額未來 scheduled 排課
- 重算方案池 `remaining_sessions`（total_sessions 增加時 remaining 同步增加，ledger 不寫新行）

**FR-002 — 學生列表方案課程剩餘堂數顯示**

系統應在學生列表頁（StudentsList），針對方案課程顯示「`package_remaining_sessions` / `package_total_sessions` 堂（方案共用）」，移除次要「方案池 X/Y 堂」小字。

**FR-003 — 進度條反映方案池進度**

學生列表頁方案課程進度條，應以方案池已用/總數計算填充比例。

**FR-004 — 加購按鈕警示以方案池剩餘為準**

學生列表頁加購按鈕，方案課程以方案池剩餘 ≤ 2 觸發橘色警示，避免誤報。

**FR-005 — 加購 modal 顯示方案池剩餘**

加購 modal 針對方案課程顯示方案池剩餘，標籤改為「目前剩餘（方案池）」。

**FR-006 — 課程管理頁方案課程剩餘堂數顯示**

課程管理頁方案課程剩餘堂數欄以方案池剩餘為主要數字；「排程列數與購買堂數不一致」警告改以 `package_total_sessions` 為比較基準（方案課程），消除 SessionCount 脫鉤造成的誤報。

**FR-007 — 非方案課程顯示不受影響**

所有非方案課程的顯示邏輯、進度條、按鈕警示、modal 邏輯，與修改前完全相同（回歸保護）。

---

## 7. 非功能需求 (NFR)

**NFR-001 — 效能**

FR-001 後端同步操作（SessionCount 更新 + 排課延伸）應在單一 transaction 內完成。預估一個方案最多 5 科，每科最多補排 20 堂，總計 ≤ 100 筆 INSERT，執行時間目標 < 3 秒。

**NFR-002 — 降級策略（前端）**

`package_remaining_sessions` 為 null 時顯示 0，不拋出 JS 錯誤；UI warning 降級為不顯示（非 null 才比較）。

**NFR-003 — 冪等性（後端）**

對相同 `total_sessions` 重複呼叫 `PUT /api/v1/course-packages/{id}` 應為 no-op（不重複補排、不重複取消）。

**NFR-004 — 不動 ledger 行**

`total_sessions` 變更只更新 `course_packages` 表的計數欄位與 `student_classes` 的 `SessionCount`，不寫入 `package_session_ledger`（ledger 只記錄出席扣堂事件）。

---

## 8. 技術方向（精確程式位置與架構決策）

### 受影響檔案與精確位置

| 層 | 檔案 | 修改位置 |
|----|------|---------|
| API（後端） | `backend/app/Http/Controllers/CoursePackageController.php` | `update()` 行 508-564：新增 `total_sessions` validation + 同步邏輯 |
| 排課邏輯（後端） | `backend/app/Http/Controllers/StudentClassController.php` | 行 2772 `cancelExcessScheduledSessions`、行 2798 `extendSessionsIfNeeded`：`private` → `public` |
| Composable | `frontend/src/composables/course-management/useCourseSessionsDisplay.js` | `displayRemainingSessions`（PackageID 分支）、`sessionCountWarning`（比較基準） |
| 學生管理頁 | `frontend/src/pages/StudentsList.vue` | 方案課程剩餘顯示、進度條、加購按鈕、modal |
| 方案管理頁 | `frontend/src/pages/CoursePackagesPage.vue` | edit modal 新增 `total_sessions` 欄位 + guard + confirm dialog |
| API lib | `frontend/src/lib/coursePackagesApi.js` | **無需改動**（`updatePackage` 已接受任意 payload） |
| Artisan | `backend/app/Console/Commands/SyncPackageSessionCounts.php` | 新建（backfill 工具） |
| 資料表 | `course_packages`、`student_classes`、`ClassSession` | 無 migration（欄位已存在） |

### 架構決策：如何在 CoursePackageController 中呼叫 StudentClassController 的排課邏輯

**問題**：`extendSessionsIfNeeded`（行 2798）和 `cancelExcessScheduledSessions`（行 2772）是 `StudentClassController` 的 `private` 方法，`CoursePackageController` 無法直接呼叫。

**決策**：將這兩個方法從 `private` 改為 `public`（最小改動），然後在 `CoursePackageController::update` 中使用 Laravel 的服務容器解析並呼叫：

```php
$scController = app()->make(\App\Http\Controllers\StudentClassController::class);
foreach (StudentClass::where('PackageID', $pkg->id)->get() as $member) {
    $scController->cancelExcessScheduledSessions((int)$member->ID, $newTotal);
    $scController->extendSessionsIfNeeded($member, $newTotal);
}
```

**不採用「提取為獨立 Service」**：`extendSessionsIfNeeded` 依賴 `resolveScheduleSlotsForRebuild`、`buildSessionsForCount`、`sessionEndedByEndTime` 等 6+ 個 private 方法，整體提取等同重寫，Regression 風險過高。本次只修改存取修飾子，不動方法本體。

### RISK-002 防護：bulk update 嚴格限定兩欄

```php
// 正確（只改 SessionCount + PackageTotalSessions）
StudentClass::where('PackageID', $pkg->id)
    ->update(['SessionCount' => $newTotal, 'PackageTotalSessions' => $newTotal]);

// 禁止（觸發 preserved_delta 重算路徑，會洗掉手動調整的 Charge delta）
// 不可透過 StudentClassController::update() 或傳入 Charge/Rate
```

### remaining_sessions 重算公式

```php
// 冪等公式（NFR-003）：任何時候重算結果都一致
$pkg->remaining_sessions = $newTotal - $pkg->used_sessions;
// 不用 remaining + delta，避免累積誤差
```

### 是否需要 Migration

不需要。`total_sessions`、`SessionCount`、`PackageTotalSessions` 等欄位已存在。

### Todos 執行順序依賴關係

```
preflight-read
  → backend-make-scheduling-public   （解除 private 封鎖）
    → backend-total-sessions-api     （API 實作，依賴上方 public 方法）
      → backend-risk002-verify       （檢查 Charge 欄位隔離）
  → frontend-composable              （獨立，可與後端平行）
  → frontend-studentslist            （依賴 composable 修改完成）
  → frontend-package-modal           （獨立）
→ test-backend-sync                  （後端 API 完成後執行）
→ test-regression-guard              （composable 完成後執行）
→ backfill-artisan                   （獨立，可最後執行）
→ security-access-control            （審查，依賴後端完成）
→ code-review-final                  （全部實作完成後）
→ docs-changelog → deploy
```

---

## 9. 資安與存取控制

**存取控制**：`PUT /api/v1/course-packages/{id}` 現有保護（`director` / `admin` / `super_admin` role + campus 隔離）適用於新增的 `total_sessions` 修改，不需新增 middleware。

**PII 與敏感資料**：`total_sessions` 屬於堂數管理資料，不含個人身份資料。

**稽核 log**：`total_sessions` 修改建議加入 `Log::info`，記錄修改前後的值與操作者，供後續稽核（參照現有 `rebuildLedger` 的 log 格式）。

**STRIDE 快評**：

| 威脅 | 評估 |
|------|------|
| Spoofing | 低：沿用現有 role + campus 驗證 |
| Tampering | 中：`total_sessions` 減少可取消排課，需 422 guard（< used_sessions 不允許）防止惡意清空排課；二次確認 dialog 為 UX 輔助，不替代後端驗證 |
| Repudiation | 中：建議加 Log（見上方稽核 log） |
| Information Disclosure | 低：API 回應不新增敏感欄位 |
| Denial of Service | 低：單一操作最多 ~100 筆 DB 寫入，不構成 DoS 風險 |
| Elevation of Privilege | 低：沿用現有 role 體系 |

---

## 10. QA 驗收標準與測試計畫

### FR-001 — 方案 API total_sessions 修改

| 路徑 | 測試案例 | 預期結果 |
|------|---------|---------|
| Happy Path | 方案 4 堂 → 8 堂（加購） | 方案 `total_sessions`=8；兩科 `SessionCount`=8；各科補排 4 堂 |
| Happy Path | 方案 8 堂 → 5 堂（縮減） | `total_sessions`=5；各科取消最後 3 堂 scheduled 排課；已上課不受影響 |
| Edge Case | 傳入相同 `total_sessions`（no-op） | 200 OK，DB 無變更，排課不變 |
| Edge Case | 新值 < 已用堂數（e.g., 已用 5 堂，改為 3） | 422，訊息「新總堂數不可小於已使用堂數（5 堂）」 |
| Edge Case | 方案有 3 科，加購後每科都補排 | 三科均補排，不遺漏 |
| Error Case | 非方案成員呼叫（role = teacher） | 403 Forbidden |
| 回歸測試 | 參考 AI_REGRESSION_LESSONS.md「增加購買堂數後第 N+1 堂起未自動產生」 | extendSessionsIfNeeded 不得整刪重建，只補差額 |
| 回歸測試 | 參考 AI_REGRESSION_LESSONS.md「固定排課契約與堂次一致」 | 排課延伸不覆蓋契約星期以外的排課 |

### FR-002 ~ FR-005 — 前端顯示

| 路徑 | 測試案例 | 預期結果 |
|------|---------|---------|
| Happy Path | 方案池 8 堂，已上 0，學生列表 | 顯示「8 / 8 堂（方案共用）」，無次要小字 |
| Happy Path | 方案池 8 堂，已上 3，學生列表 | 顯示「5 / 8 堂（方案共用）」 |
| Edge Case | 同方案兩科，學生列表 | 兩科均顯示相同數字 |
| Edge Case | 方案池剩 2 堂 | 加購按鈕橘色「續報加購」；進度條綠色（非紅色） |
| Edge Case | `package_remaining_sessions` 為 null | 顯示「0 / N 堂（方案共用）」，不報 JS 錯誤 |
| Error Case | 非方案課程 | 顯示邏輯與修改前完全一致 |

### FR-006 — sessionCountWarning 修正

| 路徑 | 測試案例 | 預期結果 |
|------|---------|---------|
| Edge Case | 方案課程 SessionCount=4，排課 8 堂，package_total_sessions=8 | 無「排程列數與購買堂數不一致」警告 |
| Happy Path | 非方案課程 SessionCount=4，排課 6 堂 | 警告正常顯示（邏輯不變） |

### UI/UX 驗收清單

- [ ] 方案 modal「總堂數」欄位存在，可輸入正整數
- [ ] 輸入小於已用堂數時出現 inline 橘色警告，submit 按鈕 disabled
- [ ] 減少堂數送出前出現二次確認 dialog
- [ ] 儲存成功後 toast「方案已更新，排課同步完成」
- [ ] 「（方案共用）」tag 視覺層次低於主要數字
- [ ] 移除次要「方案池 X/Y 堂」小字，無重複資訊
- [ ] 方案課程進度條一律綠色
- [ ] 加購 modal 標籤文字「目前剩餘（方案池）」
- [ ] 非方案課程 UI 與修改前完全一致（無視覺回歸）

---

## 11. 上線與維運

### 部署步驟

1. 後端無 migration（欄位已存在），直接部署 Laravel
2. 執行 `cd frontend && npm run deploy`，確認 `index.html` 與 `assets/` 同輪同步
3. 驗證：開啟有方案課程的學生，確認剩餘堂數顯示方案池值；加購後確認排課補排

### 監控

FR-001 的排課延伸操作建議加入 `Log::info`（含修改前後的 `total_sessions`、成員數、補排數），供排查異常時追蹤。

### 回滾方案

後端：`git revert <commit>` 後重新部署，DB 已更新的 `SessionCount` 和 `ClassSession` 需手動比對（低風險，因操作前後 ledger 不變）。前端：`git revert` 後 `npm run deploy`，業務無損失。

---

## 12. 里程碑與優先級

| 優先級 | 功能項目 | 預估工期 | 執行 Agent |
|--------|---------|---------|-----------|
| P0 | FR-001 後端 total_sessions 同步（API + SessionCount + 排課延伸/取消） | 2h | `[FEATURE]` |
| P0 | FR-002 學生列表主要剩餘數字修正 | 0.5h | `[FEATURE]` |
| P0 | FR-006 sessionCountWarning 比較基準修正 | 0.25h | `[FEATURE]` |
| P1 | FR-003/004/005 進度條、按鈕警示、modal 修正 | 0.75h | `[FEATURE]` |
| P1 | FR-006 課程管理頁剩餘數字修正 | 0.5h | `[FEATURE]` |
| P1 | UI/UX 精緻化（5b 節）確認 | 0.5h | UI/UX Designer |
| P2 | Feature Test 補充 | 1h | `[TEST]` |

---

## 13. 風險、假設、開放問題

### 13a. 風險登錄（Risk Register）

業界標準的風險登錄表包含：風險描述、可能性（Likelihood）、影響（Impact）、殘留風險、負責人、緩解行動、驗收標準。

---

#### RISK-001 — `extendSessionsIfNeeded` 觸發排課 Regression ★★★ 高
| 項目 | 內容 |
|------|------|
| **可能性** | 高 |
| **影響** | 高（歷史出缺勤 / 評量記錄被意外清除） |
| **業界參照模式** | **Margin-Only Extension**（Append-Only State Machine）：狀態機僅在尾端追加，禁止整刪重建。Google SRE 手冊「縮小爆炸半徑」原則：每次只修改最小必要 row 數。 |
| **本系統歷史教訓** | AI_REGRESSION_LESSONS §2026-04-12「增加購買堂數後第 N+1 堂起未自動產生」：`extendSessionsIfNeeded` 必須「只補差額，不整刪重建」，`currentCount` 必須排除 `cancelled/leave/leave_adjusted`，否則補建數偏多。 |
| **具體緩解措施** | (1) **呼叫前快照**：記錄 `before_count = ClassSession::whereNotIn(Status, cancelled/leave/leave_adjusted).count()`<br>(2) **差額補建**：`to_create = new_count - before_count`，僅補 `to_create` 筆，不刪舊資料<br>(3) **過去日期的補建堂次**：狀態設 `completed`（非 `scheduled`），並建立 `Status=pending` 的 LearningRecord，與新建課程行為一致<br>(4) **Transaction Savepoint**：所有成員課程的補排包在同一個 `DB::transaction()`，任一失敗全部回滾<br>(5) **`[REVIEW]` 必須對照 AI_REGRESSION_LESSONS §2026-04-12 與 §2026-04-15**，確認 `SESSION_NOT_OCCUPYING_QUOTA` 常數與後端口徑一致 |
| **回歸守護測試** | 現有：`SessionCountWarningTest`（5 案例）；本次補充：多科加購後現有出缺勤記錄不消失（`PackageTotalSessionsSyncTest::test_extend_does_not_clear_existing_attendance`） |
| **殘留風險** | 低 — 若嚴格遵守 Append-Only + Transaction |

---

#### RISK-002 — `Rate/Charge delta` 被 SessionCount 同步覆寫 ★★☆ 中
| 項目 | 內容 |
|------|------|
| **可能性** | 中 |
| **影響** | 高（帳單金額異常，財務不符） |
| **業界參照模式** | **Immutable Audit Trail + Delta Preservation**（同 AWS Cost Explorer 的費用增量記錄）：每次改動只記錄差量（delta），而非覆寫原值。 |
| **本系統歷史教訓** | AI_REGRESSION_LESSONS §2026-04-17「Rate/SessionCount 異動保留 delta」：`StudentClassController::update` 在 `SessionCount` 異動時**必須**計算 `preserved_delta = 舊 Charge − 舊 Rate × 舊 Count`，新 `Charge = Rate_new × Count_new + preserved_delta`。**禁止直接 `Rate × Count` 覆寫**，否則單堂時間調整累積的手動金額全部洗掉。 |
| **具體緩解措施** | (1) `CoursePackageController::update` 在同步各成員 `SessionCount` 時，**不觸發** `Charge` 重算（不傳 `Rate` 或 `Charge` 給 `StudentClass::update`）<br>(2) 若需同步 `SessionCount`，使用 `StudentClass::whereIn('PackageID', ...)->update(['SessionCount' => $new, 'PackageTotalSessions' => $new])` 僅更新這兩欄，不碰 `Charge`<br>(3) `[REVIEW]` 確認 bulk update 語句不包含 `Charge`、`Rate`、`RemainingSessions`（這些欄位各自有保護邏輯） |
| **回歸守護測試** | `test_package_session_count_sync_does_not_alter_charge`（驗證加購堂數後各成員課程 Charge 不變） |
| **殘留風險** | 低 — 若 bulk update 嚴格限定兩欄 |

---

#### RISK-003 — 多科進度不一導致補排數量偏多 ★☆☆ 低
| 項目 | 內容 |
|------|------|
| **可能性** | 低 |
| **影響** | 中（排課多出，需手動刪除） |
| **業界參照模式** | **Per-Resource Idempotency Check**：每個資源（每科）獨立計算自己的差額，不假設所有資源進度一致（類似 Kubernetes reconcile loop 的 per-object desired state）。 |
| **具體緩解措施** | (1) 對每個成員 `StudentClass` 分別計算 `currentCount`，各自補差額<br>(2) 若某科 `currentCount >= new_count`，對該科為 no-op，不補也不刪<br>(3) `[TEST]` 驗證場景：方案 4→8 堂，A 科已上 5 堂（currentCount=5），B 科已上 1 堂（currentCount=1）→ A 科補 3，B 科補 7 |
| **殘留風險** | 極低 |

---

#### RISK-004 — `package_remaining_sessions` 為 null 的舊資料導致前端顯示錯誤 ★☆☆ 低
| 項目 | 內容 |
|------|------|
| **可能性** | 低（只影響方案建立日期早於 FR-001 上線的舊資料） |
| **影響** | 低（顯示 0/0，不影響實際扣堂） |
| **業界參照模式** | **Defensive Null Handling + Data Backfill Script**：前端防禦顯示 0；同時提供一次性 backfill script 補齊舊資料（類似 Stripe 的 data migration playbook）。 |
| **具體緩解措施** | NFR-002 前端降級為 0；後端 `rebuildLedger` 可用來修復舊方案的計數欄位 |
| **殘留風險** | 極低 |

---

#### RISK-005 — 月結方案誤觸 total_sessions 修改 ★☆☆ 低
| 項目 | 內容 |
|------|------|
| **可能性** | 低（API guard 防護） |
| **影響** | 中（月結方案沒有 SessionCount 語意，若被修改會造成資料不一致） |
| **具體緩解措施** | 後端 guard：`if ($pkg->billing_mode !== 'count') return 422 '月結方案不支援修改總堂數'`；前端 modal 在 billing_mode = date 時隱藏「總堂數」欄位 |
| **殘留風險** | 極低 |

---

### 13b. 假設與驗證機制

業界做法：每條假設都應附帶一個**可機器驗證的 Invariant**（不變式），讓系統能自我監控假設是否仍然成立。

---

**假設 A — 方案的 `total_sessions` 是所有成員科目 `SessionCount` 的唯一真實來源**

- **驗證方式（Materialized Invariant Check）**：新增健康檢查 query：`SELECT * FROM student_classes WHERE PackageID IS NOT NULL AND SessionCount != (SELECT total_sessions FROM course_packages WHERE id = PackageID)`，結果應永遠為空集合
- **自動監控**：可加入 `nightly-backup.sh` 或 Artisan command `php artisan packages:check-invariants`，發現違反則 slack/log 警告
- **當前狀態**：此假設目前在部分舊資料中**已被違反**（截圖的根本原因），FR-001 的 total_sessions 同步將修復這一點

**假設 B — 加購方案堂數的入口為方案編輯 modal，不另建新頁面**

- **前提條件**：主任的加購操作頻率低（估計每月 < 10 次），對工作流程的中斷可接受
- **若假設不成立**：若 PM 決定需要專屬的「加購記錄」頁面（例如未來需要對應收費明細），升級路徑為：新增 `POST /api/v1/course-packages/{id}/purchase`，並在 PackageManagement 頁新增「加購」按鈕；本次設計不阻礙此升級

**假設 C — 月結方案（billing_mode = date）不涉及 SessionCount，本次修改不影響月結方案**

- **驗證方式**：`course_packages WHERE billing_mode='date'` 的 `total_sessions` 應全為 0；`student_classes WHERE PackageID IN (月結方案ID)` 的 `SessionCount` 應全為 0
- **guard 保護**：後端 422 + 前端隱藏欄位雙重保護（見 RISK-005）

**假設 D — 扣堂邏輯（PackageDeductionService）不受本次修改影響**

- **根據**：扣堂從 ledger 扣，不讀 SessionCount；FR-001 只修改 `SessionCount` 和 `ClassSession`，不動 ledger
- **驗證方式**：部署後對現有方案執行 `POST /api/v1/course-packages/{id}/recompute`，確認 remaining_sessions 與 used_sessions 與手算一致

---

### 13c. 開放問題（附業界解決方案）

---

**Q1 — 加購後費率調整：新堂數適用哪個單價？**

`[決策需要 PM]`

**業界參照**：
- **Stripe Proration 模型**：升級訂閱時，剩餘天數按舊價比例退款，新堂數按新價計算，產生一筆「調整項（proration item）」
- **Unit Price Lock 模型**：原有購買堂數不動，加購的堂數另建一筆獨立合約，各自有自己的單價（適合一次性加購，不影響原合約）
- **Blended Rate 模型**：加購後重算混合單價（`(舊堂 × 舊價 + 新堂 × 新價) / 新總堂`），適合月結或折扣階梯定價

**本系統建議**：
> 採用 **Unit Price Lock**（單價鎖定）：`total_sessions` 增加時，`rate` 不自動變更；主任可在同一 modal 手動修改 `rate`（`PUT /api/v1/course-packages/{id}` 現已支援 `rate` 欄位更新）。此方案實作最簡單，且保留主任的定價彈性。

**決策選項**：

| 選項 | 優點 | 缺點 | 推薦場景 |
|------|------|------|---------|
| Unit Price Lock（建議） | 實作簡單，主任自行決定費率 | 加購後費率與原合約分離，主任需手動更新 | 本期採用 |
| Proration | 財務精確 | 需新增 proration 計算與帳單調整欄位 | 未來帳單系統成熟後升級 |
| 新增加購明細記錄 | 完整稽核記錄 | 需新增 purchase_addendum 表 | 未來課程包管理模組 |

---

**Q2 — 減少堂數取消排課時，是否通知家長（LINE push）？**

`[決策需要 PM + CTO]`

**業界參照**：
- **GDPR / 消費者保護最佳實踐**：對消費者不利的變動（如取消已排課）應主動通知，並提供理由
- **事件驅動通知架構（Event-Driven Notification）**：系統發出 `SessionCancelled` 事件，通知服務訂閱並根據用戶偏好（LINE/Email/SMS）發送；通知服務與業務邏輯解耦（類似 AWS SNS 模式）
- **操作分類**：區分「系統校正通知」（系統 bug 修復，不通知）vs「業務操作通知」（主任主動減少方案堂數，應通知）

**本系統建議**：
> **本次不實作推播**，理由：現有 `cancelExcessScheduledSessions` 已有取消排課邏輯且無推播，行為一致；新增推播屬獨立功能，不應捆綁在此 PR 中（避免 scope creep）。
>
> **未來通知架構建議**：新增 `SessionCancelled` 事件，在 `ClassSession::save()` 觀察器（Observer）發出；通知服務訂閱後根據 `cancelled_by`（system/admin）決定是否推播，「主任主動減少」應推播，「系統 bug 修復性取消」可不推播。

**短期風險緩解**：在 API response 中返回「被取消的排課列表」（`cancelled_sessions: [...]`），讓前端在儲存成功後顯示摘要 dialog（「已取消 N 堂排課，請手動通知家長」），把通知責任交還主任。

---

**Q3 — 現有「SessionCount 脫鉤」的舊資料如何修復？**

`[執行時機需確認]`

**業界參照**：**Zero-Downtime Data Migration**（Stripe / GitHub 的 backfill 策略）：新功能上線前，先以 dry_run 模式執行 backfill script 確認影響範圍，再分批次執行修復，避免單次大量 UPDATE 鎖表。

**本系統建議**：
```bash
# Step 1 (dry-run): 找出所有 SessionCount ≠ total_sessions 的方案成員
php artisan packages:sync-session-counts --dry-run

# Step 2 (正式執行): 批次修正
php artisan packages:sync-session-counts

# Step 3: 執行 extendSessionsIfNeeded 補排缺少的 ClassSession
php artisan packages:extend-sessions-for-synced
```

此 Artisan 指令可作為本次 FR-001 的附屬工具，讓主任或工程師在部署後一次性修復歷史資料，而不需要手動操作每個方案。

---

**Q4 — `sessionCountWarning` 修改是否會影響現有的「請假假陽性」修正？**

`[工程內部確認，非業務問題]`

**背景**：AI_REGRESSION_LESSONS §2026-04-15「請假調課後堂次警示假陽性」記錄了一次 `sessionCountWarning` 的修正：已引入 `SESSION_NOT_OCCUPYING_QUOTA` 常數與 `effectiveSessionCount()`，警示改用這兩個值計算，禁止回歸到 `sessionUnits().length !== purchased`。

**本次修改的影響範圍**：本次只修改 `sessionCountWarning` 的**比較基準**（方案課程改用 `package_total_sessions`，非方案課程維持現有邏輯），不修改 `effectiveSessionCount` 的計算方式。

**驗收守護**：`[REVIEW]` 必須確認修改後 `SessionCountWarningTest`（5 案例：CaseA~E）仍全部通過，確保請假假陽性修正未被回歸。

---

## 14. Definition of Done

- [ ] 所有 FR（FR-001 ～ FR-007）通過 QA 驗收
- [ ] UI/UX 驗收清單（第 10 節）全部打勾，**UI/UX Designer sign-off**
- [ ] 資安 STRIDE 快評確認無阻擋風險，`total_sessions` 修改有 Log 稽核
- [ ] `[REVIEW]` 確認排課延伸邏輯對照 AI_REGRESSION_LESSONS.md 無 regression 風險
- [ ] `npm run deploy` 執行成功，`index.html` 與 assets 同輪同步，API health 正常
- [ ] `docs/CHANGELOG.md` 已更新
- [ ] PM sign-off
- [ ] CTO / 工程 Lead sign-off
