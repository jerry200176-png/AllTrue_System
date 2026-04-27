# Bug Fix Plan: 賴本航月結帳單期別與堂數第 9 堂防呆

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P1 |
| 根因類型 | 邏輯錯誤 / UI 語意不清 / 堂數上限守門不足 |
| 根因摘要 | 月結續報目前延長原 `StudentClass`，付款狀態與帳單期別混在同一課程；堂數制詳情會列出所有有效 `ClassSession`，但未把「購買堂數上限」與「合法例外堂」做清楚分流。 |
| 錯誤行為 | 主任看不出哪一期月結已繳、哪一期未繳；購買 8 堂已上 7 堂時，若 DB 存在第 9 筆占額度堂次，畫面仍顯示第 9 堂。 |
| 預期行為 | 月結續報建立新一期課程，舊課程可結算；堂數制最多只有購買堂數內的堂次占額度，超出堂次必須被標示為補課/加課例外或異常，不可偽裝成原 8 堂的一部分。 |
| 影響範圍 | 主任課程管理、課程詳情、月結續報、堂數制補課/加購、繳費記錄。 |
| B1 偵查來源 | 本計畫整合 B1：`StudentClassController::renewMonthly`、`sessionDates`、`CourseManagement.vue`、`useCourseSessionsDisplay`、`SessionDeductionService`。 |

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | Course Billing Period & Session Quota Guardrails |
| 版本 | 2026-04-28 BFP |
| 狀態 | Draft，待 CEO 批准進 DEV |
| 嚴重度 | P1 |
| 目標角色 | 主任 / 行政 |
| 關聯 Bug | 木柵賴本航：月結帳單期別混淆；購買 8 堂已上 7 堂卻顯示第 9 堂 |

## 2. 業務背景與影響

補習班的課程帳務以「一期一期結清」最符合行政直覺。若月結續報仍掛在原課程底下，主任很難判斷舊期是否已結算、新期是否未繳。堂數制若允許第 9 堂混在 8 堂包內，則可能造成少收費、誤排課、家長對帳爭議。

修復後預期行為：
- 月結續報會形成新一期課程，舊課程進入已結算或歷史狀態。
- 每一期課程都有清楚的繳費與服務期間。
- 堂數制畫面清楚區分「購買內堂次」、「請假/取消不占額度」、「補課/加課例外」、「超排異常」。

## 3. 範圍

In Scope:
- 月結續報從「延長原課程」改為「建立新一期課程 + 舊課程結算」。
- 課程詳情顯示帳單期別、課程批次、舊期/新期狀態。
- 堂數制詳情與 API 對超出 `SessionCount` 的有效 `ClassSession` 給出明確異常或例外標示。
- 補課/加課若合法超出固定契約，必須使用既有 `IsContractException` 或等價語意，避免被誤算成原購買堂。
- 新增 regression tests，覆蓋月結期別與第 9 堂。

Out of Scope:
- 不改主任繳費提醒既有商業條件。
- 不做直接 production DB 手動修補。
- 不重寫整個課程管理 UI 視覺系統。
- 不改家長入口付款流程。
- 不新增公開無認證端點。

## 4. RACI

| 項目 | R | A | C | I |
|---|---|---|---|---|
| Bug plan | AI Agent | AI Agent | CEO | CEO |
| 後端修復 | AI Agent | AI Agent | CEO | CEO |
| 前端修復 | AI Agent | AI Agent | CEO | CEO |
| Regression tests | AI Agent | AI Agent | CEO | CEO |
| Review / Docs / Ops | AI Agent | AI Agent | CEO | CEO |

## 4b. Dependencies

- 無新 migration 前提；優先使用既有 `Invoice.billing_period`、`StudentClass.closed_reason`、`ClassSession.IsContractException`。
- 若 DEV 期間確認現有欄位不足，必須停下回報，另開 DBA/migration 設計，不可直接加欄位。
- 需要 GitHub Actions CI 跑 PHPUnit / frontend build；本地 WSL 不跑 Pi production 測試。

## 5. Acceptance Criteria

### AC-001：月結續報建立新一期
- AC-001-a：主任對月結課按續報，系統建立新的 `StudentClass`，新課程有自己的起訖日、帳單與未繳狀態。
- AC-001-b：原課程不再被延長，並標示為已結算或歷史課程。
- AC-001-c：課程管理可同時看出舊期已結算、新期待繳費。

### AC-002：月結帳單期別清楚
- AC-002-a：每張帳單顯示 `billing_period` 或服務期間。
- AC-002-b：繳費操作能對準指定期別，不會只靠 `student_class_id` 猜目前月份。

### AC-003：堂數制不可靜默超排
- AC-003-a：購買 8 堂的課程，若有 9 筆占額度堂次，API/前端必須顯示「超出購買堂數」異常。
- AC-003-b：合法補課/加課例外必須顯示為例外堂，不列入原購買 8 堂的序號。
- AC-003-c：請假、取消、補請假不占購買堂數。

### AC-004：賴本航情境防呆
- AC-004-a：對應情境「購買 8、已上 7」時，畫面最多只會顯示 1 堂剩餘購買內堂次。
- AC-004-b：若仍存在第 9 筆，畫面必須明確標成「超排異常」或「加課/補課例外」，並提示主任處理。

## 6. 功能需求 FR

- FR-001：月結 `renew-monthly` 修復後應建立新一期課程，而非延長原課程。
- FR-002：舊月結課程結算時，必須取消未來未上 `scheduled` 堂次，避免歷史課殘留待上課。
- FR-003：新一期月結課程必須產生明確帳單期別，付款狀態只代表該期。
- FR-004：付款記錄入口應可指定 invoice / period，避免同課程多期帳單時記錯期別。
- FR-005：堂數制詳情應以 `SessionCount` 作為購買內額度上限，並排除 `cancelled` / `leave` / `leave_adjusted` / `excused`。
- FR-006：超出購買額度的非例外堂次應回傳/顯示異常，不可被序號成第 9 堂。
- FR-007：合法補課/加課例外應維持 `IsContractException` 語意，UI 顯示「例外堂」而非「第 N 堂」。

## 7. 非功能需求 NFR

非效能型 bug，無新增效能 KPI。列表頁不得新增 N+1 API 呼叫；課程詳情仍應使用既有批次載入 `class-sessions` / `session-dates`。

## 8. 技術方向

涉及檔案與方法：
- `backend/app/Http/Controllers/StudentClassController.php`
  - `renewMonthly`
  - `sessionDates`
  - `extendSessionsIfNeeded`
  - close/settle future scheduled session 相關方法
- `backend/app/Http/Controllers/PaymentReportController.php`
  - `directorRecord`
- `frontend/src/pages/CourseManagement.vue`
  - 月結續報 submit / invoice modal / 詳情顯示
- `frontend/src/components/PaymentEntryModal.vue`
  - 付款指定期別或 invoice
- `frontend/src/composables/course-management/useCourseSessionsDisplay.js`
  - 堂次序號、例外堂、超排警示
- `backend/tests/Feature/*`
  - 月結新一期、帳單指定期別、堂數第 9 堂 regression tests

架構取捨：
- 補習班語境採「新一期課程」而非 SaaS 單 subscription 永久累積，因為行政對帳與家長溝通更直覺。
- 堂數制採「credits/pass guard」模式：購買堂數是可占用額度，未來預約也要占額度；超出必須買新批次或標為受控例外。
- 先用既有欄位與既有例外旗標，不先引入新 ledger schema，避免擴大 PR。

## 8b. Decision Log

| 日期 | 決策 | 替代方案 | 選擇理由 |
|---|---|---|---|
| 2026-04-28 | 月結續報建立新一期 `StudentClass` | 原課程累積多期 invoice | 補習班行政更容易按期結算，也符合使用者明確偏好。 |
| 2026-04-28 | 堂數制以購買堂數作為占額度上限 | 前端直接列出所有 `ClassSession` | 防止第 9 堂偽裝成原方案內堂次。 |
| 2026-04-28 | 合法補課/加課用例外語意分流 | 一律取消超出堂次 | 真實營運需要補課與臨時加課，但必須可辨識、可收費。 |

## 9. 資安與存取控制

不新增公開端點，不修改 auth token/session。所有新增或調整 API 必須沿用既有 `auth:sanctum`、`role:admin,director,super_admin`、`require_campus` 或現有路由權限。涉及學生姓名與帳務資料，所有查詢必須維持分校隔離。

## 10. QA 驗收

Happy Path:
- 月結課續報後，舊課程結算、新課程待繳、帳單期別正確。
- 堂數課 8 堂中 7 堂已上、1 堂 scheduled 時，顯示正常。

Edge:
- 8 堂中含請假/取消，請假不占額度，補課補回後仍不超過 8。
- 有一堂 `IsContractException=1` 的加課，顯示為例外堂，不編號成第 9 堂。
- 原課程已有未來 scheduled，結算時只取消未來未上，不動歷史 attended。

Error:
- 有第 9 筆非例外 scheduled，畫面顯示超排異常，後端測試可驗證 effective count > purchased。
- 付款未指定 invoice/period 時，不可誤套到錯誤期別。

Revert-proof 驗證:
- git stash 後重跑新增測試，至少月結新一期與堂數第 9 堂 case 會失敗。

## 11. 上線與維運

- Migration：預期無。
- 部署：feature branch PR → CI green → merge → `deploy.yml` 自動部署。
- Observability：部署後檢查 health；前端變更需確認 `version.json` 更新。
- 回滾：若出現課程建立或付款異常，使用 `git revert <PR commit>` 走 hotfix PR，不直接改 production。
- 既有資料：不在此計畫直接手動修 DB；若需要修賴本航個案，先由新 UI/API 標示異常，再走受控行政操作或另開資料修復計畫。

## 12. 優先級

| 類別 | Agent | 優先級 |
|---|---|---|
| 後端修復 | `[DEV]` | P1 |
| 前端修復 | `[DEV]` | P1 |
| Regression Tests | `[TEST]` | P1 |
| Revert-proof 驗證 | `[TEST]` | P1 |
| 資安 / 分校隔離 Review | `[REVIEW]` | P1 |
| CHANGELOG + AI_REGRESSION_LESSONS | `[DOCS]` | P1 |
| 部署與 health check | `[OPS]` | P1 |

## 13. 風險 / 假設 / 開放問題

業界參考：
- Stripe Billing / SaaS billing practice：invoice line item 必須有 service period，收入與付款應以 invoice/period 為準，不只看 subscription 或課程主檔狀態。
- Punchpass / class pass 類系統：預約數應受 purchased credits/pass 限制，未來預約也會占用 credits；超出時需購買新 pass 或由 admin 受控 bypass。
- Class package 系統：清楚顯示剩餘 credits、使用歷史、有效期限與 booking rules，避免家長/客戶誤解。

風險：
- 舊資料可能已經有超排堂次；本 PR 應先標示與防止新增，不直接大量修資料。
- 月結新一期會改變主任既有操作習慣，需要 UI 文案清楚告知「舊期結算、新期建立」。
- 若 PaymentEntryModal 改為指定 invoice，需保留舊資料查詢相容，但不可讓新操作再模糊期別。

假設：
- `Invoice.billing_period` 已可作為期別顯示基礎。
- `ClassSession.IsContractException` 已存在且可用於補課/加課例外。
- 賴本航的第 9 堂是有效 `ClassSession` 超出 `SessionCount` 或未標例外造成，DEV 期需以 read-only 查詢/測試資料確認。

開放問題：
- 月結新一期課程的命名是否要在 UI 顯示「2026-05 期」或「第 N 期」？建議先顯示服務期間。
- 超排異常的處理按鈕是否本 PR 只做提示，還是同時做「轉例外堂 / 取消 / 建新批次」？建議第一版至少提供清楚提示與安全取消，轉例外需權限確認。

## 14. Definition of Done

- [ ] FR-001 / FR-003：驗證方式：GitHub Actions PHPUnit 新增月結續報測試回傳 success，確認新舊 `StudentClass` 分離且 invoice period 正確。
- [ ] FR-002：驗證方式：PHPUnit 驗證舊課程結算後 future scheduled 變 `cancelled`，history attended 不變。
- [ ] FR-004：驗證方式：PHPUnit 驗證指定 invoice/period 付款只更新目標帳單。
- [ ] FR-005 / FR-006 / FR-007：驗證方式：PHPUnit + frontend unit/build 驗證購買 8 堂出現第 9 筆時有異常/例外標示，不再編號成第 9 堂。
- [ ] Revert-proof：驗證方式：`git stash` 後新增測試至少 1 case failure。
- [ ] Frontend build：驗證方式：GitHub Actions frontend build success。
- [ ] CHANGELOG：驗證方式：`git diff docs/CHANGELOG.md` 含 2026-04-28 修復條目。
- [ ] AI regression：驗證方式：`git diff docs/AI_REGRESSION_LESSONS.md` 含月結期別/堂數超排防再犯規則。
- [ ] Health check：PR merge deploy 後 `curl -sk https://daan.lifenet.com.tw/api/v1/health` 回傳 HTTP 200 且 `status=ok`。
