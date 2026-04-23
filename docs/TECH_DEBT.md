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

---

### TD-004：請假後刷卡 — 同一堂課產生兩筆記錄，堂數補回後又被扣回

| 欄位 | 內容 |
|---|---|
| 狀態 | Done |
| 優先級 | P1 |
| 發現日期 | 2026-04-23 |
| 發現來源 | [REVIEW] 出缺勤邊界分析 |
| 影響模組 | `SwipeRfidController::findMatchingClass`、`SessionDeductionService` |
| 描述 | `findMatchingClass` 查詢 `ClassSession` 時不過濾 `Status = 'leave'` 的堂次。學生請假後（leave `StudentSignIn` 已建立、堂數已補回），當天若又到補習班刷卡，系統仍會比對到那堂 leave session，再建一筆 `Memo='swipe-rfid'` 的 `StudentSignIn`，並執行 `deductOnAttendance`。淨效果：leave 補回一堂，swipe 再扣一堂 = 兩相抵消，堂數「看起來」正確，但同一堂出現兩筆記錄（一筆 leave、一筆 present），前端顯示混亂，查帳困難。 |
| 建議做法 | 在 `findMatchingClass` 的 ClassSession query 加 `->where('Status', '!=', 'leave')` 過濾，讓請假堂次不被刷卡邏輯重新命中。 |
| 清償成本估計 | 低（< 2hr） |
| 不做的代價 | 請假後補到校的學生，出缺勤頁面同一堂出現兩筆，報表雙重計算，家長查帳時困惑。 |

---

### TD-005：前端修改出缺勤狀態只更新 ClassSession，StudentSignIn.Status 不同步

| 欄位 | 內容 |
|---|---|
| 狀態 | Open |
| 優先級 | P1 |
| 發現日期 | 2026-04-23 |
| 發現來源 | [REVIEW] 出缺勤邊界分析 |
| 影響模組 | `AttendancePage.vue::saveStatusEdit`、`ClassSessionController::update` |
| 描述 | `saveStatusEdit` 發送 `PATCH /api/v1/class-sessions/:id`，只更新 `ClassSession.status`，不更新 `StudentSingIn.Status`。前端本地更新 `record.Status` 讓畫面看起來有效，但 30 秒後 `fetchRecords` 重新拉 API（`si.Status`），顯示又回到修改前的舊值。出缺勤狀態修改對老師來說是「虛假成功」。 |
| 建議做法 | `ClassSessionController::update` 或後端對應路由，在更新 `ClassSession.status` 時一併更新該 ClassSession 對應的 active `StudentSingIn.Status`；或在 `AttendancePage.vue` 的 `saveStatusEdit` 後立即 `fetchRecords()`。 |
| 清償成本估計 | 低（< 2hr） |
| 不做的代價 | 老師修改出缺勤狀態後 30 秒會自動還原，每次都需要手動刷新才能看到「真實狀態」，嚴重影響老師信任度。 |

---

### TD-006：學生刷卡無 debounce — RFID 讀卡機 bounce 可能造成秒速 sign_in + sign_out

| 欄位 | 內容 |
|---|---|
| 狀態 | Done |
| 優先級 | P2 |
| 發現日期 | 2026-04-23 |
| 發現來源 | [REVIEW] 出缺勤邊界分析 |
| 影響模組 | `SwipeRfidController::handleStudentSwipe` |
| 描述 | 老師刷卡有 60 秒 debounce（`TEACHER_SWIPE_DEBOUNCE_SECONDS`），學生刷卡沒有。硬體 RFID 讀卡機偶爾會在一次實體刷卡後連發兩個訊號（RF bounce），第一個訊號建立 `openRecord`，第二個訊號（幾百毫秒後）看到 openRecord 就執行 sign_out，觸發 `backfillPresenceWindow`。整個在場時間幾乎為零，導致 Presence Window 不補建任何課程記錄，等同於這次刷卡完全無效。 |
| 建議做法 | 在 `handleStudentSwipe` 加入和老師相同的 debounce 機制（建議 30～60 秒）：sign_out 前檢查 `openRecord` 的 `SignInDT` 距離 `swipeAt` 是否小於閾值，是則忽略本次刷卡。 |
| 清償成本估計 | 低（< 2hr） |
| 不做的代價 | 讀卡機 bounce 時學生刷卡記錄無效，刷進後立刻刷出，家長收到的到離通知毫無意義。 |

---

### TD-007：老師手動點名後學生又刷卡 — 同一 ClassSession 出現兩筆 StudentSignIn

| 欄位 | 內容 |
|---|---|
| 狀態 | Open |
| 優先級 | P2 |
| 發現日期 | 2026-04-23 |
| 發現來源 | [REVIEW] 出缺勤邊界分析 |
| 影響模組 | `SwipeRfidController::handleStudentSwipe`、`findMatchingClass` |
| 描述 | 老師已手動點名（建立 `StudentSignIn`，`ClassSessionID = X`），學生隨後又刷卡。`findMatchingClass` 找到同一 ClassSession（不檢查是否已有 `StudentSignIn`），建立第二筆 `StudentSignIn`。`deductForSession` 的冪等保護（按 `class_session_id` 去重）防止重複扣堂，但 DB 和前端都出現兩筆記錄。`AttendanceController::swipe`（另一個 endpoint）有 `lockForUpdate` 防重複，但 `SwipeRfidController` 沒有。 |
| 建議做法 | 在 `findMatchingClass` 傳回 session 前，或在 `handleStudentSwipe` sign_in 分支中，先檢查該 ClassSession 是否已有 active（non-voided）`StudentSignIn`；若有則跳過建立，或回傳「已點名」訊息。 |
| 清償成本估計 | 低（< 2hr） |
| 不做的代價 | 出缺勤頁面同一堂課顯示兩筆，老師看不懂，也容易被誤報為 bug。 |

---

### TD-008：學生跨日忘刷退 — 昨日孤兒 SignIn 永不自動關閉

| 欄位 | 內容 |
|---|---|
| 狀態 | Open |
| 優先級 | P2 |
| 發現日期 | 2026-04-23 |
| 發現來源 | [REVIEW] 出缺勤邊界分析 |
| 影響模組 | `SwipeRfidController::handleStudentSwipe`、`StudentSingIn` |
| 描述 | `openRecord` 查詢條件是 `whereDate('SignInDT', $today)`，只找今日開放記錄。學生昨天刷進沒刷退，今天刷卡走 sign_in 流程（因昨日 openRecord 不在查詢範圍），昨日那筆 `SignOutDT = null` 的記錄永遠懸空。`Presence Window` 基於當日 `SignInDT～SignOutDT` 窗口，也不會回溯昨日。老師手動補點名是目前唯一 fallback，但需要人工發現。 |
| 建議做法 | 新增 Laravel Scheduler 每日凌晨掃描「昨日 `SignOutDT = null` 且超過 N 小時」的孤兒記錄，自動設定 `SignOutDT = 當日最後一堂課 EndTime`（或補習班關門時間），並觸發 `backfillPresenceWindow`。 |
| 清償成本估計 | 中（半天） |
| 不做的代價 | 忘刷退的學生，昨日出缺勤記錄中 `SignOutDT` 永遠 null，報表無法計算在場時長，課程也不會被 Presence Window 補建。 |

---

### TD-009：backfillPresenceWindow 中 ClassSession.EndTime 為 null 時 SignOutDT 靜默錯誤

| 欄位 | 內容 |
|---|---|
| 狀態 | Open |
| 優先級 | P2 |
| 發現日期 | 2026-04-23 |
| 發現來源 | [REVIEW] 出缺勤邊界分析 |
| 影響模組 | `SwipeRfidController::backfillPresenceWindow` |
| 描述 | `Carbon::parse($today . ' ' . $session->EndTime)` 在 `EndTime = null` 時，解析結果為 `$today 00:00:00`（午夜）。補建的 `StudentSignIn.SignOutDT` 會是當天 00:00，語義完全錯誤，但不丟 exception，靜默寫入 DB。 |
| 建議做法 | 在 `backfillPresenceWindow` 的 foreach 中，加入 `if (!$session->EndTime) { continue; }` 跳過 EndTime 為 null 的 session；或 fallback 為 `$session->StartTime + 1 小時`。 |
| 清償成本估計 | 低（< 2hr） |
| 不做的代價 | EndTime 缺值的堂次補建後，`SignOutDT = 00:00`，報表在場時長負值，家長 Telegram 通知顯示異常時間。 |

---

### TD-010：RFID 欄位無 DB 唯一性約束 — 同一 RFID 可綁多個學生

| 欄位 | 內容 |
|---|---|
| 狀態 | Open |
| 優先級 | P2 |
| 發現日期 | 2026-04-23 |
| 發現來源 | [REVIEW] 出缺勤邊界分析 |
| 影響模組 | `Student` 表、`SwipeRfidController` |
| 描述 | `Student.RFID` 欄位沒有 `UNIQUE` 約束（已確認 migration 無此條件）。若管理員誤將同一張卡綁兩個學生，刷卡時 `Student::where('RFID', $rfid)->where('CampusID', $campusId)->where('enable', 1)->first()` 只取第一筆，沈默地跳過另一個學生，不觸發任何錯誤或警告。 |
| 建議做法 | 加 DB migration 建立 `unique index`（`Student.RFID` + `CampusID`）；綁定入口加後端唯一性驗證，返回明確錯誤訊息。 |
| 清償成本估計 | 低（< 2hr） |
| 不做的代價 | 資料錯誤時系統不警告，某個學生的刷卡永遠不被記錄，直到人工發現才知道卡片被佔用。 |

---

### TD-011：findMatchingClass 30 分鐘固定窗口不考慮課程時長 — 短課可能永遠無法刷卡匹配

| 欄位 | 內容 |
|---|---|
| 狀態 | Open |
| 優先級 | P3 |
| 發現日期 | 2026-04-23 |
| 發現來源 | [REVIEW] 出缺勤邊界分析 |
| 影響模組 | `SwipeRfidController::findMatchingClass` |
| 描述 | 匹配窗口固定為 `$windowMinutes = 30`（刷卡時間距課程 StartTime ≤ 30 分鐘才匹配）。30 分鐘課程中，若學生遲到 31 分鐘，完全無法匹配，被歸類為 self_study。另外，多堂連排時（如 10:00-11:00、11:00-12:00），學生 11:25 到，距第一堂 StartTime 85 分鐘（不匹配），距第二堂 StartTime 25 分鐘（匹配），行為正確；但若學生 10:35 到，距第一堂 35 分鐘（不匹配），也距第二堂 25 分鐘（匹配），會被算入第二堂而非第一堂。 |
| 建議做法 | 窗口可改為「課程時長的 50%」或改成「StartTime 前後 N 分鐘 OR StartTime 至 EndTime 之間均可」的課中刷卡匹配邏輯。需配合 one-in-one-out 架構評估。 |
| 清償成本估計 | 中（半天） |
| 不做的代價 | 短課或連排課學生遲到時，刷卡記錄歸入錯誤課堂或變成 self_study，扣錯堂數。 |

---

*最後更新：2026-04-23*
