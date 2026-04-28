# 主任儀表板「繳費提醒」規則（不可漏）

資料來源：`GET /api/v1/alerts/tuition`（`AlertController::tuition`）。  
前端：`DirectorDashboard.vue`（區塊標題為「繳費／續課提醒」等，與本規則一致）。

## 一頁摘要（給 AI／工程師快速對齊）

| 模式 | 列入提醒的條件（皆須 `Stop = 0`，且堂數制／月結制各自還有欄位前提） |
|------|-------------------------------------------------------------------|
| **堂數制** `ScheduleMode = count` | **未繳費**（`Paid != 1`）**或** **剩餘堂數不足**（`RemainingSessions <= 2`，**含 0 堂**；已繳費也會列，屬續課／加購提醒）。 |
| **月結制** `ScheduleMode = date` | 須有有效 **`settlement_day`（1–31）**。未繳且**已過**當月繳費日 → **一律**列入（逾期至標記已繳為止）。其餘：不論已繳或未繳，僅當「今天 ↔ 本次計算之繳費日」相隔 **0～4 天**（**小於 5 天**）才列入；**第 5 天整不算**。 |

## 變更管制（AI／工程師）

上述規則為**產品確認後之行為**（含「已繳但低堂數仍提醒」）。  
**任何 AI 或工程師**在修改 `AlertController::tuition`、`GET /api/v1/alerts/tuition` 之查詢條件／回傳欄位，或主任總覽與其連動之顯示邏輯前，**必須先取得使用者（產品方）明示同意**，並同步更新本檔與相關測試（如 `TuitionAlertsApiTest`、`LargeBranchDataHandlingTest`）。**禁止**為了「畫面看起來合理」而擅自改成只提醒未繳、漏月結、或漏掉 0 堂。

防再犯與沿革敘述：`docs/AI_REGRESSION_LESSONS.md`（搜尋「繳費／續課提醒」或 `AlertController::tuition`）。

## 堂數制（`StudentClass.ScheduleMode = count`）

- 課程須為進行中：`Stop = 0`。
- 符合**任一**即列入提醒：
  1. **未繳費**：`Paid != 1`
  2. **剩餘堂數不足**：`RemainingSessions <= 2`（**含 0 堂**，需續課／加購）

`alert_type`：`unpaid` 或 `low_sessions`（若同時符合，以前者優先欄位展示時前端以堂數／未繳狀態區分）。

### 續課抑制（2026-04-16 新增）

若一筆堂數制課程觸發 `low_sessions`，但**同一學生、同一科目**已存在另一筆進行中（`Stop=0`）且 `RemainingSessions > 2` 的課程（代表已續課），則該 `low_sessions` 提醒會被**自動抑制**，不再出現在催繳名單。`unpaid` 類型不受此邏輯影響。

## 月結制逐期帳單（2026-04-27 起）

月結課程「月結續約」後，系統自動：
1. 建立新一期 `StudentClass`，新課程 `Paid=0`、`PayDate=null`
2. 舊期課程 `Stop=1`、`closed_reason='settled'`，保留原本 paid 歷史
3. 在新課程底下建立新期 `Invoice`（`billing_period = YYYY-MM`，`Status='unpaid'`）

`directorRecord` 核帳時，優先找 `billing_period = 當月` 的未繳 Invoice；
主任收費後 Invoice.Status 更新為 `paid`，同時新一期 `StudentClass.Paid=1`。

舊月結課程（無 Invoice）行為不變，fallback `StudentClass.Paid` 欄位。

## 月結制（`StudentClass.ScheduleMode = date`）

- 課程須為進行中：`Stop = 0`。
- 須設定 **`settlement_day`**（1–31）；無設定則不由此 API 推論月結提醒。
- 繳費日：每月該日；若該月無該日（如 2/31），則取該月最後一日。
- 時區：與 `config/app.php` 的 `timezone`（預設 `Asia/Taipei`）之「今天」為準。

### 未繳費（`Paid != 1`）

1. **尚未超過本月繳費日**（含當天）：僅當「今天距繳費日」**小於 5 天**時列入（即 0～4 天；第 5 天整不算）。
2. **已超過本月繳費日**：一律列入（視為逾期催繳），直到標記已繳。

### 已繳費（`Paid = 1`）

- 若今天 **尚未超過本月繳費日**（含當天）：以「本月繳費日」為下一截止日，距該日 **小於 5 天**（0～4 天）才列入。
- 若今天 **已超過本月繳費日**：以下一個月的繳費日為準，同樣 **小於 5 天** 才列入。

`alert_type`：`monthly_due_soon`；`days_until_settlement`：負數表示已逾期天數；`due_date`：本次計算的繳費日（Y-m-d）。

## 結案與不再提醒

提醒**僅列 `Stop = 0`（進行中）**的課程。課程如何離開提醒清單：

1. **加購新批次**（`POST /api/v1/student-classes/{id}/purchase-batch`）：若來源為堂數制、已繳（`Paid=1`）、剩餘 0 堂，系統**自動**將來源設 `Stop=1`（結案），不再出現在提醒中。
2. **不續報**：主任在「課程管理」或「學生課程」對已繳 + 0 堂的課程按**「結案（不續報）」**，實質寫 `Stop=1`（與暫停相同後端 API），該課程從提醒消失。之後仍可手動恢復。
3. 既有**暫停課程**（`togglePause`）同樣寫 `Stop=1`，效果一致。

## 回歸時請測

- 堂數制：已繳但剩 1～2 堂（或 0 堂）仍應出現。
- 加購後：舊約自動 `Stop=1`，`alerts/tuition` 僅列新約。
- 結案 UI：已繳 + 0 堂 → 結案後不再列。
- 月結：未繳、繳費日前第 4 天出現、第 5 天不出現；未繳且過繳費日仍出現。
- 分校：`branch_id` 與 `CampusID` 過濾正確。

## 曾發生過的錯誤（避免再犯）

- 只查 `ScheduleMode = count` 會**完全漏掉月結**。
- `remaining > 0 && <= 2` 會**漏掉 0 堂**。
- 將 API 改成「只未繳費」會**漏掉低堂數已繳費**。

## payment_status 補充欄位與列入規則的分離關係（2026-04-16）

`alerts/tuition` 回傳的 `payment_status`（六種值）為**顯示用補充欄位**，由 `AlertController::computePaymentStatus()` 計算。此欄位**不影響列入條件**——即使 `payment_status = paid`，只要課程符合上述堂數制或月結制的列入條件，仍會出現在名單中。

修改 `computePaymentStatus()` 時，不得連帶修改列入條件的 query；反之亦然。兩者為獨立邏輯，以避免互相干擾。

## CoursePackage 月結方案的 payment_status（2026-04-22 Bug Fix）

`AlertController::tuition` 的月結多科方案（`CoursePackage.billing_mode = 'monthly'`）路徑中，`payment_status` 與 `outstanding` 需正確反映 `$pkg->paid`：

| `pkg->paid` | `payment_status` | `outstanding` |
|---|---|---|
| `false`（未繳）| `unpaid` | `$charge`（應收金額）|
| `true`（已繳）| `monthly_due_soon` | `0` |

**曾犯錯誤**：`payment_status` 被硬編碼為 `'unpaid'`，導致已繳費方案仍顯示在「未繳費」tab（`paid = true` 但 `payment_status = 'unpaid'` 衝突）。  
**測試**：`CoursePackageMonthlyBillingTest::test_paid_monthly_package_shows_monthly_due_soon_not_unpaid`。
