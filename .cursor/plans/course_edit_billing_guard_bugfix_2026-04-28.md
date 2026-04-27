# Course Edit Multi-Day + Billing Duplicate Guard Bug Fix Plan

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P1 |
| 根因類型 | 前後端契約同步錯誤 + 付款防重邏輯缺失 |
| 根因摘要 | `CourseEditForm` 開啟編輯時可能以既有 `day_time_slots` 覆蓋新勾選的 `days_of_week`；`PaymentReportController::directorRecord` 在已繳課程找不到未繳帳單時會新建 Invoice/Payment |
| 錯誤行為 | 編輯選週三+週日只保留週三；同一課程可被再次核帳，畫面出現多筆繳費 |
| 預期行為 | 勾選星期是固定課表契約來源；已繳且無未繳帳單的課程不可再次核帳 |
| 影響範圍 | 主任課程編輯、月結/堂數核帳、課程帳單 Modal |
| B1 偵查來源 | 本次 B1：課程編輯 payload 與 `directorRecord` invoice fallback |

## 1. 文件資訊

- 功能名稱：課程編輯多日補齊與重複核帳防護
- 版本：2026-04-28
- 狀態：B2 實作
- 目標角色：director / admin / super_admin
- 關聯 Bug：吳艾潼歷史課程帳單與課程固定時段編輯

## 2. 業務背景與影響

主任需要可靠地編輯正班老師與固定課表，且核帳只能對真實未繳帳單入帳。錯誤會造成課表少排、家長/主任對帳混亂與重複收款風險。

## 3. 範圍

In Scope：課程編輯 `days_of_week/day_time_slots` 補齊、主任核帳重複付款 guard、帳單有效付款筆數、對應 regression tests。

Out of Scope：歷史 production 資料清理、付款 UI 重設計、App Store/Capacitor 上架工作。

## 4. RACI

- Responsible：AI Agent
- Accountable：AI Agent
- Consulted：User
- Informed：User

## 4b. Dependencies

無 migration。依賴現有 `Invoice`、`Payment`、`payment_reports`、`StudentClass` schema。

## 5. Acceptance Criteria

### AC-001：課程編輯多日補齊
- AC-001-a：PUT 課程時 `days_of_week=[3,7]` 但 `day_time_slots` 只有週三，系統仍保存週三與週日兩個固定時段。
- AC-001-b：開啟編輯表單時，既有 slot 不得覆蓋 parent 傳入的 selected days。

### AC-002：重複核帳防護
- AC-002-a：已繳課程且沒有未繳 Invoice 時，`directorRecord` 回 422 `course_already_paid`。
- AC-002-b：帳單付款筆數只計有效正向付款，void/負數沖銷不算「繳費次數」。

## 6. 功能需求 FR

- FR-001：`CourseEditForm` parent sync 必須合併 selected days 與 slots days。
- FR-002：`StudentClassController::mapFrontendPayload` 必須以 `days_of_week` 補齊缺漏 slot。
- FR-003：`PaymentReportController::directorRecord` 必須拒絕已繳且無未繳帳單的重複入帳。
- FR-004：`StudentClassController::invoices` 必須排除 void/負數付款計數。

## 7. 非功能需求 NFR

不適用，這是低流量表單與核帳邏輯修復；不新增重查詢或背景工作。

## 8. 技術方向

- `frontend/src/components/CourseEditForm.vue`：調整 parent sync 合併策略。
- `frontend/src/pages/CourseManagement.vue`：只有繳費日期被改動時才送 `paid_at`。
- `backend/app/Http/Controllers/StudentClassController.php`：補齊 missing selected day slots，並計算有效付款筆數。
- `backend/app/Http/Controllers/PaymentReportController.php`：新增重複核帳 guard。

## 8b. Decision Log

- 2026-04-28：選擇前後端雙層補齊，而非只修 UI，避免任何舊前端或同步延遲再次吃掉星期。
- 2026-04-28：選擇 422 guard，而非自動建立新帳單，因為同一課程已繳後再次核帳必須先人工作廢或指定未繳帳單。

## 9. 資安與存取控制

不新增端點，不改 auth middleware。既有 `require_campus` / role guard 維持。

## 10. QA 驗收

- Happy Path：週三+週日課程編輯後兩日皆保存；未繳帳單可正常核帳。
- Edge：`day_time_slots` 漏週日仍補齊；void payment 不計入有效付款筆數。
- Error：已繳課程重複核帳回 422。
- Revert-proof：還原任一修復後，新增測試至少一個失敗。

## 11. 上線與維運

無 migration。走 feature branch PR → CI → merge → deploy.yml。回滾用 `git revert <commit>`；若已有錯誤付款資料，本 PR 不自動清資料，需另行人工對帳/作廢。

## 12. 優先級

P1，由 `[DEV]`、`[TEST]`、`[REVIEW]`、`[DOCS]`、`[OPS]` 處理。

## 13. 風險 / 假設 / 開放問題

WebSearch 參考：Stripe duplicate payment/idempotency 建議以唯一交易識別與付款防重控制避免 double charge；recurring schedule UX 建議用自然語言/多時段列與模板化方式管理 recurrence。

風險：歷史已重複建立的 Invoice/Payment 不在本次自動清理範圍，需後續依主任確認作廢。

## 14. Definition of Done

- [ ] FR-001/FR-002：驗證方式：`php artisan test --filter=StudentClassUpdateScheduleReconcileTest` CI 回傳 pass。
- [ ] FR-003/FR-004：驗證方式：`php artisan test --filter=PaymentReportApiTest` CI 回傳 pass。
- [ ] Revert-proof：驗證方式：還原修復後新增 case 至少 1 failure。
- [ ] CHANGELOG：驗證方式：`git diff docs/CHANGELOG.md` 含 2026-04-28 修復條目。
- [ ] Health check：部署後 `GET /api/v1/health` 回傳 HTTP 200。
