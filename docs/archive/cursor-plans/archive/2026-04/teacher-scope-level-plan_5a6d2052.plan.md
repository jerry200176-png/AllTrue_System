---
name: teacher-scope-level-plan
overview: Implement teacher subject+school-level scoping end-to-end with soft enforcement first, then add reporting and migration/backfill support, while preserving existing API compatibility.
todos:
  - id: schema-teacher-subject-levels
    content: 新增 teacher_subject_levels migration 與回填腳本，建立索引與唯一鍵
    status: completed
  - id: backend-scope-service
    content: 新增 TeacherScopeService，實作 subject+level 匹配與 warning 回傳
    status: completed
  - id: backend-enrollment-hook
    content: 在 EnrollmentService 與 StudentClassController update 掛入 scope 檢查
    status: completed
  - id: backend-profile-api
    content: 擴充 ProfileController store/update/index 支援 subject_level_scopes
    status: completed
  - id: frontend-teacher-settings
    content: 在 TeachersList.vue 提供科目×學段設定介面與儲存
    status: completed
  - id: frontend-course-soft-warning
    content: 在 StudentsList/CourseEditForm/UniversalClassScheduler/EnrollmentWizard 顯示軟限制警示
    status: completed
  - id: report-level-breakdown
    content: 擴充 FinanceController subjectUnits include_level 並更新 SubjectUnitsPage/DirectorDashboard
    status: completed
  - id: tests-and-rollout
    content: 補齊 API/流程測試、執行回歸清單，按分階段上線
    status: completed
isProject: false
---

# 老師授課學段細分實作計畫（軟限制 + 完整版）

## 目標與原則
- 目標：老師可設定「科目 × 學段（國小/國中/高中）」授課能力，並在建課/排課時進行軟限制提示（可繼續儲存），同時補上報表維度與資料回填。
- 原則：
  - 不破壞既有流程（現有 `subject_units` 回傳維持相容）。
  - 先中央化後端檢查邏輯，再前端呈現提示。
  - 允許舊資料平滑過渡（無設定者採 fallback 規則）。

## 受影響檔案與切入點
- 路由與教師管理：[`/home/admin/backend/routes/api.php`](/home/admin/backend/routes/api.php)、[`/home/admin/backend/app/Http/Controllers/ProfileController.php`](/home/admin/backend/app/Http/Controllers/ProfileController.php)
- 建課/排課入口：[`/home/admin/backend/app/Services/EnrollmentService.php`](/home/admin/backend/app/Services/EnrollmentService.php)、[`/home/admin/backend/app/Http/Controllers/StudentClassController.php`](/home/admin/backend/app/Http/Controllers/StudentClassController.php)、[`/home/admin/backend/app/Http/Controllers/ClassSessionController.php`](/home/admin/backend/app/Http/Controllers/ClassSessionController.php)
- 前端老師與建課 UI：[`/home/admin/frontend/src/pages/TeachersList.vue`](/home/admin/frontend/src/pages/TeachersList.vue)、[`/home/admin/frontend/src/pages/StudentsList.vue`](/home/admin/frontend/src/pages/StudentsList.vue)、[`/home/admin/frontend/src/components/CourseEditForm.vue`](/home/admin/frontend/src/components/CourseEditForm.vue)、[`/home/admin/frontend/src/components/UniversalClassScheduler.vue`](/home/admin/frontend/src/components/UniversalClassScheduler.vue)、[`/home/admin/frontend/src/components/EnrollmentWizard.vue`](/home/admin/frontend/src/components/EnrollmentWizard.vue)
- 報表：[`/home/admin/backend/app/Http/Controllers/FinanceController.php`](/home/admin/backend/app/Http/Controllers/FinanceController.php)、[`/home/admin/frontend/src/pages/SubjectUnitsPage.vue`](/home/admin/frontend/src/pages/SubjectUnitsPage.vue)、[`/home/admin/frontend/src/pages/DirectorDashboard.vue`](/home/admin/frontend/src/pages/DirectorDashboard.vue)

## 資料模型設計
- 新增資料表（建議）：`teacher_subject_levels`
  - 欄位：`id`, `teacher_id`, `subject_id`, `level`(`elementary|junior|high`), `created_at`, `updated_at`
  - 唯一鍵：`(teacher_id, subject_id, level)`
  - 索引：`teacher_id`, `subject_id`, `level`
- 回填策略：
  - 由既有 `teacher_subjects` 轉入 `teacher_subject_levels`，初期先灌三學段（elementary/junior/high）確保不阻塞。
  - 無 `teacher_subjects` 的老師視為未設定，後端回傳建議警示但不擋。

## 後端實作步驟
1. 建立 scope 服務層（新 service，例如 `TeacherScopeService`）
   - 輸入：`teacher_id`, `subject_id`, `student_grade_or_level`, `campus_id`
   - 輸出：`ok`, `warnings[]`, `matched_scopes[]`
   - 核心：
     - 將學生年級映射到學段（P*→elementary, J*→junior, H*→high）。
     - 檢查老師是否具備對應 `subject + level`。
     - 軟限制模式下僅回 warning，不拋 422。

2. 在建課主流程掛入檢查（單一真相來源）
   - [`EnrollmentService::store`]( /home/admin/backend/app/Services/EnrollmentService.php )：在 teacher/campus 驗證後、建立 `StudentClass` 前執行 scope 檢查。
   - 回應中附加 `scope_warning`（若有），供前端顯示。

3. 在更新流程掛入檢查
   - [`StudentClassController::update`]( /home/admin/backend/app/Http/Controllers/StudentClassController.php )：當 `TeacherID/SubjectID/GradeID` 變更時再驗證並附 warning。

4. 教師管理 API 擴充
   - [`ProfileController@store/update/bulkTeachers`]( /home/admin/backend/app/Http/Controllers/ProfileController.php )支援收/存 `subject_level_scopes`。
   - `GET teachers/profiles` 回傳 `subject_level_scopes`（供前端管理頁與建課頁快取使用）。

## 前端實作步驟
1. 老師管理頁可編輯授課學段
   - [`TeachersList.vue`]( /home/admin/frontend/src/pages/TeachersList.vue )新增「科目 × 學段」設定 UI。
   - 儲存時送 `subject_level_scopes` 至 profiles API。

2. 建課頁做軟限制提示
   - [`StudentsList.vue`]( /home/admin/frontend/src/pages/StudentsList.vue )、[`CourseEditForm.vue`]( /home/admin/frontend/src/components/CourseEditForm.vue )、[`UniversalClassScheduler.vue`]( /home/admin/frontend/src/components/UniversalClassScheduler.vue )、[`EnrollmentWizard.vue`]( /home/admin/frontend/src/components/EnrollmentWizard.vue )：
     - 先依老師 scope 過濾候選（優先排序可教者）。
     - 若使用者選到不匹配，顯示醒目 warning（可送出）。
     - API 回傳 `scope_warning` 時二次提示，避免前端判斷遺漏。

3. 選單與提示一致化
   - 老師選單標註可授課學段（例如：`王老師（數學：國中/高中）`）。
   - 錯誤/警示文案統一，避免不同頁文案分裂。

## 報表擴充（完整版）
1. 後端：在 [`FinanceController::subjectUnits`]( /home/admin/backend/app/Http/Controllers/FinanceController.php )加可選參數 `include_level=1`
   - 維持原 `teachers/totals` 不變。
   - 額外回傳 `level_breakdown`（teacher-level 與 totals-level）。

2. 前端：
   - [`SubjectUnitsPage.vue`]( /home/admin/frontend/src/pages/SubjectUnitsPage.vue )新增學段分解區塊（可折疊）。
   - [`DirectorDashboard.vue`]( /home/admin/frontend/src/pages/DirectorDashboard.vue )新增簡化學段統計卡（可選）。

## 驗證與回歸
- API 測試（Pest）：
  - 老師 scope CRUD。
  - 建課時匹配/不匹配皆可建立，但不匹配返回 `scope_warning`。
  - 更新課程改老師/科目/年級可觸發 warning。
  - `subject-units?include_level=1` 回傳相容且含新欄位。
- 手動回歸：
  - 老師管理設定 -> 建課頁選師 -> 儲存警示 -> 報表顯示。
  - 多分校權限隔離不破壞。

## 上線策略
- Phase 1（本次）：軟限制 + 報表 + 回填。
- Phase 2（未來可選）：將特定分校或全域切換為硬限制（feature flag / system setting 控制）。