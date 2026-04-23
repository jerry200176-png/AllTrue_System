---
name: parttime-payroll-implementation
overview: 依據 PRD 先完成後端薪資計算與鎖帳基礎，再接前端薪資頁與驗收測試，最後以 Raspberry Pi 容量門檻做上線決策。
todos:
  - id: phase1-backend-core
    content: 完成薪資規則引擎與資料層（migration/model/index）
    status: completed
  - id: phase2-backend-apis
    content: 完成 5 支 parttime-payroll API 與驗證/權限/匯出限制
    status: completed
  - id: phase3-frontend-page
    content: 完成 ParttimePayrollPage 與 App 導覽整合、狀態矩陣與互動
    status: completed
  - id: phase4-tests
    content: 完成 ParttimePayroll Feature tests 與回歸測試
    status: completed
  - id: phase5-pi-rollout
    content: 完成 Pi 壓測、SLO 驗證與 Go/No-Go 上線決策
    status: completed
isProject: false
---

# 兼職薪資實作計劃

## 實作策略
- 先後端、後前端：先把薪資口徑與資料契約固定，再接 UI，避免前後端反覆改欄位。
- 先查詢、後鎖帳：先交付 `parttime-payroll` 查詢與匯出，再補 lock/reopen 狀態流轉。
- 以 Pi 容量為 gate：每階段都驗證 `per_page` 上限與匯出記憶體行為，避免最後才發現效能不過。

## Phase 1: 後端資料層與規則引擎
- 新增月結狀態與稽核 migration/model：
  - [backend/database/migrations](backend/database/migrations)
  - [backend/app/Models](backend/app/Models)
- 在 [backend/app/Http/Controllers/FinanceController.php](backend/app/Http/Controllers/FinanceController.php) 實作共用薪資計算邏輯：
  - only `LearningRecord approved + active`
  - 過濾 `employment_type=part_time`
  - `trial` 排除、`tutoring=200/h`、多人加成 +50/h
  - 小時與金額 rounding 規則
- 補上索引（依 PRD 9.4）：`LearningRecord(TeacherID, Status, SessionDate)` 與必要輔助索引。

## Phase 2: 後端 API 與權限
- 在 [backend/routes/api.php](backend/routes/api.php) 增加 5 支路由：
  - `GET /finance/parttime-payroll`
  - `GET /finance/parttime-payroll/{teacherId}/sessions`
  - `GET /finance/parttime-payroll/export`
  - `POST /finance/parttime-payroll/lock`
  - `POST /finance/parttime-payroll/reopen`
- 契約對齊 PRD 第 8 章：
  - summary + teachers + sessions + meta
  - `month` 驗證（YYYY-MM）、`per_page` 上限 200
- 角色/分校隔離：沿用 `role:director` + `require_campus`，super_admin 才可 reopen。
- 匯出先做 CSV 串流並加筆數上限，避免 Pi 記憶體暴增。

## Phase 3: 前端頁面與體驗
- 新增 [frontend/src/pages/ParttimePayrollPage.vue](frontend/src/pages/ParttimePayrollPage.vue)（若檔案不存在則建立）。
- 更新 [frontend/src/App.vue](frontend/src/App.vue) 新增 `active='parttime-payroll'` 導覽入口與權限顯示。
- 新增 API 呼叫封裝（建議放在 `frontend/src/lib/*`）：
  - list、teacher sessions、export、lock、reopen
- 落地 PRD 第 10 章三層資訊架構：
  - 總覽卡
  - 老師清單
  - 展開式堂次明細（分頁）
- 完成狀態矩陣：loading/empty/error/no-permission/locked/exporting。

## Phase 4: 測試與回歸
- 後端 Feature tests 新檔：
  - [backend/tests/Feature/ParttimePayrollTest.php](backend/tests/Feature/ParttimePayrollTest.php)
- 測試覆蓋（對應 PRD 第 14 章）：
  - 金樣本計算（高中/國中/國小/輔導 + 一對一到一對三）
  - API 契約與分頁上限
  - 分校隔離與角色權限
  - lock/reopen 狀態機
  - 匯出與畫面數字一致性
- 回歸既有高風險區：subject-units、teacher-payroll 舊 API、learning-records 核准流程。

## Phase 5: Pi 壓測與上線
- 依 PRD 第 13 章執行 Pi 壓測（30-60 分鐘），觀測：
  - OOM-killer = 0
  - API P95 < 2s
  - 匯出穩定且無 swap thrashing
- 若不達標，優先啟動降載：
  - 降 `max_per_page`
  - 降 `max_export_rows`
  - 必要時先關閉匯出保留查詢
- 通過後再全量上線，並執行 7 天觀察。

## 里程碑與交付物
- M1 後端可查：API + migration + 基礎測試。
- M2 前端可用：薪資頁 + 匯出 + 鎖帳 UI。
- M3 驗收可簽：QA/UAT/IT 壓測報告與 Go/No-Go 結論。

## 風險優先處理
- `LearningRecord` 與 `ClassSession` 口徑混用風險：實作時僅保留一條計算路徑。
- Pi 記憶體風險：嚴格禁止一次回傳全老師全堂次明細。
- 月結數字漂移風險：lock/reopen 必寫 audit log，且 reopen 僅 super_admin。