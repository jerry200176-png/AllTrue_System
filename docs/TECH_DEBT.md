# AllTrue 技術債追蹤（Tech Debt Backlog）

> 每次 `[REVIEW]` 發現 Minor 問題但本次不修 → 記錄到此檔。
> 每月由 `[ARCH]` 評估一次，挑 CP 值最高的排入下一個開發週期。
> 清償時走「內部優化流程」，不需要完整 PRD，只需要 `[ARCH] → [DEV] → [TEST] → [REVIEW]`。

---

## 使用方式

### 新增一筆技術債

```
[ARCH] 技術債登記

請將以下技術債加入 docs/TECH_DEBT.md：
- 項目：[描述]
- 發現來源：[REVIEW / SRE / 開發中發現]
- 影響：[描述現在的痛點]
- 建議優先級：P1 / P2 / P3
```

### 月度評估

```
[ARCH] 技術債月度評估

請讀 docs/TECH_DEBT.md，
評估每筆 Open 項目的 CP 值（影響 / 成本），
挑出本月最值得做的 1-2 筆，說明理由。
```

---

## 優先級定義

| 級別 | 定義 |
|---|---|
| **P1** | 影響開發速度或穩定性（每次改這邊都怕壞其他地方） |
| **P2** | 有改善空間但不緊急（命名不一致、重複邏輯） |
| **P3** | 美化型（過時寫法、可以但不優雅） |

---

## 技術債清單

<!-- 格式：
### TD-[編號]：[標題]
| 欄位 | 內容 |
|---|---|
| 狀態 | Open / In Progress / Done |
| 優先級 | P1 / P2 / P3 |
| 發現日期 | YYYY-MM-DD |
| 發現來源 | [REVIEW] / [SRE] / 開發中 / [BUG] |
| 影響模組 | [Controller / Vue 頁面 / DB] |
| 描述 | [現在的問題是什麼] |
| 建議做法 | [大方向，不需要細節] |
| 清償成本估計 | 低（< 2hr）/ 中（半天）/ 高（> 1天）|
| 不做的代價 | [如果繼續放著會怎樣] |
-->

### TD-001：BillingController 超過 400 行，職責混雜

| 欄位 | 內容 |
|---|---|
| 狀態 | Done |
| 優先級 | P2 |
| 發現日期 | 2026-04-21 |
| 發現來源 | 系統建立時已知 |
| 影響模組 | `BillingController`、`FinanceController` |
| 描述 | 帳單計算、繳費提醒判斷、統計報表混在同一個 Controller，每次改繳費邏輯都需要全檔搜尋 |
| 建議做法 | 抽出 `BillingService`（計算）和 `AlertService`（提醒判斷），Controller 只做路由分發 |
| 清償成本估計 | 高（> 1 天）|
| 不做的代價 | 繼續放著每次改繳費都容易 regression，AI 也容易改錯地方 |
| 清償日期 | 2026-04-22（已確認 BillingController 現為 320 行，已精簡）|

---

### TD-002：SubjectUnitsPage API 格式不符

| 欄位 | 內容 |
|---|---|
| 狀態 | Done |
| 優先級 | P1 |
| 發現日期 | 2026-04-21 |
| 發現來源 | 系統建立時已知（.cursorrules 有記錄） |
| 影響模組 | `FinanceController::subjectUnits`、`SubjectUnitsPage.vue` |
| 描述 | 後端回傳 `{TeacherID, Subject, unit_count}` 但前端期待 `{ teachers: [...], totals: {...} }`，導致科目數統計頁面顯示空白 |
| 建議做法 | 修正 `FinanceController::subjectUnits` 回傳格式，或在前端做格式轉換層 |
| 清償成本估計 | 中（半天）|
| 不做的代價 | 科目數統計頁面永遠空白，主任無法查看老師科目數 |
| 清償日期 | 2026-04-22（已確認後端回傳 `{ teachers, totals }` 格式，前端正確讀取，頁面正常顯示）|

---

### TD-003：前端三個頁面仍使用 Options API

| 欄位 | 內容 |
|---|---|
| 狀態 | Done |
| 優先級 | P3 |
| 發現日期 | 2026-04-21 |
| 發現來源 | 開發中發現 |
| 影響模組 | `StudentsList.vue`、`TeachersList.vue`、`ClassroomManagement.vue` |
| 描述 | 其他頁面已升級到 Composition API + `<script setup>`，這三個仍用 Options API，風格不一致 |
| 建議做法 | 逐一遷移，從最簡單的 `ClassroomManagement.vue` 開始，確保功能行為不變 |
| 清償成本估計 | 中（每個頁面約 2-4 小時）|
| 不做的代價 | 風格不一致，未來維護者（AI 或工程師）需要同時理解兩種寫法 |
| 清償日期 | 2026-04-22（已確認三個頁面全部已改為 `<script setup>` Composition API）|

---

*最後更新：2026-04-22*
