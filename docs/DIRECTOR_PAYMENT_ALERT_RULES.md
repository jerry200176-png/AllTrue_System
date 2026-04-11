# 主任儀表板「繳費提醒」規則（不可漏）

資料來源：`GET /api/v1/alerts/tuition`（`AlertController::tuition`）。  
前端：`DirectorDashboard.vue` 繳費提醒區塊。

## 堂數制（`StudentClass.ScheduleMode = count`）

- 課程須為進行中：`Stop = 0`。
- 符合**任一**即列入提醒：
  1. **未繳費**：`Paid != 1`
  2. **剩餘堂數不足**：`RemainingSessions <= 2`（**含 0 堂**，需續課／加購）

`alert_type`：`unpaid` 或 `low_sessions`（若同時符合，以前者優先欄位展示時前端以堂數／未繳狀態區分）。

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

## 回歸時請測

- 堂數制：已繳但剩 1～2 堂（或 0 堂）仍應出現。
- 月結：未繳、繳費日前第 4 天出現、第 5 天不出現；未繳且過繳費日仍出現。
- 分校：`branch_id` 與 `CampusID` 過濾正確。

## 曾發生過的錯誤（避免再犯）

- 只查 `ScheduleMode = count` 會**完全漏掉月結**。
- `remaining > 0 && <= 2` 會**漏掉 0 堂**。
- 將 API 改成「只未繳費」會**漏掉低堂數已繳費**。
