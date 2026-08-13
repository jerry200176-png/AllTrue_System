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
| 狀態 | Done |
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
| 狀態 | Done |
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
| 狀態 | **Done** 2026-04-23 |
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
| 狀態 | Done |
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
| 狀態 | **Done** 2026-04-23 |
| 優先級 | P2 |
| 發現日期 | 2026-04-23 |
| 發現來源 | [REVIEW] 出缺勤邊界分析 |
| 影響模組 | `Student` 表、`SwipeRfidController` |
| 描述 | `Student.RFID` 欄位沒有 `UNIQUE` 約束（已確認 migration 無此條件）。若管理員誤將同一張卡綁兩個學生，刷卡時 `Student::where('RFID', $rfid)->where('CampusID', $campusId)->where('enable', 1)->first()` 只取第一筆，沈默地跳過另一個學生，不觸發任何錯誤或警告。 |
| 建議做法 | 加 DB migration 建立 `unique index`（`Student.RFID` + `CampusID`）；綁定入口加後端唯一性驗證，返回明確錯誤訊息。 |
| 清償成本估計 | 低（< 2hr） |
| 不做的代價 | 資料錯誤時系統不警告，某個學生的刷卡永遠不被記錄，直到人工發現才知道卡片被佔用。 |
| 清償記錄 | migration `2026_04_23_300000`：DROP 舊單欄位 `student_rfid_unique`，ADD composite `students_rfid_campus_unique (RFID, CampusID)`；`StudentController::bindCard` 加唯一性驗證回傳 422；3 個 feature tests (PR #18 CI green) |

---

### TD-011：findMatchingClass 30 分鐘固定窗口不考慮課程時長 — 短課可能永遠無法刷卡匹配

| 欄位 | 內容 |
|---|---|
| 狀態 | **Done** 2026-04-23 |
| 優先級 | P3 |
| 發現日期 | 2026-04-23 |
| 發現來源 | [REVIEW] 出缺勤邊界分析 |
| 影響模組 | `SwipeRfidController::findMatchingClass` |
| 描述 | 匹配窗口固定為 `$windowMinutes = 30`（刷卡時間距課程 StartTime ≤ 30 分鐘才匹配）。30 分鐘課程中，若學生遲到 31 分鐘，完全無法匹配，被歸類為 self_study。另外，多堂連排時（如 10:00-11:00、11:00-12:00），學生 11:25 到，距第一堂 StartTime 85 分鐘（不匹配），距第二堂 StartTime 25 分鐘（匹配），行為正確；但若學生 10:35 到，距第一堂 35 分鐘（不匹配），也距第二堂 25 分鐘（匹配），會被算入第二堂而非第一堂。 |
| 建議做法 | 窗口可改為「課程時長的 50%」或改成「StartTime 前後 N 分鐘 OR StartTime 至 EndTime 之間均可」的課中刷卡匹配邏輯。需配合 one-in-one-out 架構評估。 |
| 清償成本估計 | 中（半天） |
| 不做的代價 | 短課或連排課學生遲到時，刷卡記錄歸入錯誤課堂或變成 self_study，扣錯堂數。 |

---

---

## TD-012 — AttendanceController 私有方法與 AttendanceEffectsService 重複

| 欄位 | 內容 |
|---|---|
| 發現來源 | [REVIEW] bugfix_swipe_attendance_sync_2026-04-23 Code Review |
| 影響模組 | `AttendanceController::resolveSwipeStatus()`, `AttendanceController::applyAttendanceEffects()` |
| 描述 | 修復學生刷卡未同步 ClassSession.Status 時，新建了 `AttendanceEffectsService` 並將邏輯移至其中。但 `AttendanceController` 仍保留 private `resolveSwipeStatus()` 和 `applyAttendanceEffects()` 方法，形成邏輯重複。目前 AttendanceController 內的手動點名路徑仍使用自己的 private 方法，未切換到 Service。 |
| 建議做法 | 將 `AttendanceController` 的手動點名路徑也改為呼叫 `AttendanceEffectsService`，然後移除兩個 private 方法。 |
| 清償成本估計 | 小（2小時） |
| 不做的代價 | 未來如需調整 grace period（目前 15 分鐘）或狀態對應表，需同步修改兩處，有漏改風險。 |

---

*（區段標記，已非檔尾；最新登記見 TD-059/TD-060，最後更新 2026-05-31）*

### TD-013：個人設定頁自填 LINE User ID

| 欄位 | 內容 |
|---|---|
| 狀態 | Open |
| 優先級 | P2 |
| 發現日期 | 2026-04-26 |
| 發現來源 | 開發中 |
| 影響模組 | ProfileCenterPage.vue / AuthController |
| 描述 | LINE push 後端已實作（NotificationLineDispatcher），但老師/主任無法在個人設定自填 LINE User ID，目前只能由主任透過老師管理頁代填 |
| 建議做法 | 基本資料 tab 加 LINE User ID 輸入欄 + `PUT /api/v1/me` 支援 `line_id` + 通知偏好說明移除「暫不影響」字樣 |
| 清償成本估計 | 低（< 2hr）|
| 不做的代價 | 老師無法自助綁定，LINE 通知功能雖已上線但使用率低 |

### TD-014：Laravel 8 安全修補需規劃 major upgrade

| 欄位 | 內容 |
|---|---|
| 狀態 | Open |
| 優先級 | P1 |
| 發現日期 | 2026-04-27 |
| 發現來源 | [ARCH] 技術債月度評估 |
| 影響模組 | backend composer dependencies / Laravel framework |
| 描述 | Composer audit 顯示 `laravel/framework` 8.x-dev 存在多筆無 8.x patch 的 advisory（2026-06 新增 **HIGH** CRLF email rule GHSA-5vg9-5847-vvmq、MEDIUM signed URL GHSA-crmm-hgp2-wgrp、MEDIUM file validation CVE-2025-27515）；修補版本需 Laravel **10.48.29+**（file validation）或 **12.60.0+**（CRLF）／**12.61.1+**（signed URL），Laravel 8 無低風險 patch path。CI `composer audit` gate 曾誤判（advisory dict 非 list 時漏掃 HIGH，見 #security-quality-remediation 2026-06-29） |
| 建議做法 | 另開 Laravel major upgrade 專案，先盤點 PHP 版本、Sanctum、Pest/PHPUnit、middleware、filesystem validation 與部署相容性，再分階段升級 |
| 清償成本估計 | 高（> 1天）|
| 不做的代價 | CI 會持續出現 Composer audit warning，且涉及檔案上傳驗證的安全風險無法在 Laravel 8 內完全修補 |

### TD-015：MySQL PITR / binlog 還原能力

| 欄位 | 內容 |
|---|---|
| 狀態 | Deferred（2026-05-09） |
| 優先級 | P1 |
| 發現日期 | 2026-04-27 |
| 發現來源 | [SRE] 工程成熟度補強 |
| 影響模組 | DB backup / restore / Pi ops |
| 描述 | 目前已有 nightly、sixhour 與 Google Drive 異地備份，RPO 可控制在數小時等級；但尚未建立正式 MySQL binary log / point-in-time recovery SOP，若資料誤刪發生在兩次 sixhour 備份之間，仍可能損失該區間資料 |
| 建議做法 | 由 DBA/OPS 評估啟用 MySQL binlog、retention、磁碟壓力、binlog 異地同步與「full backup + binlog replay」演練；所有測試只可還原到 drill DB，不可觸碰 production `AllTrue` |
| 清償成本估計 | 中（半天）|
| 不做的代價 | 下一次資料破壞事件仍只能回到最近快照，無法精準還原到事故前一刻 |
| 2026-05-09 決策 | 先 defer，不在當前維運窗口直接啟用 production binlog；觸發條件與 pre-enable checklist 已寫入 `docs/OPERATIONS_RUNBOOK.md` §P |

### TD-016：停用課程殘留 future scheduled 堂次掃描與自動修復

| 欄位 | 內容 |
|---|---|
| 狀態 | Done（2026-05-09 PR #270）|
| 優先級 | P1 |
| 發現日期 | 2026-04-29 |
| 發現來源 | [BUG] |
| 影響模組 | `StudentClass` / `ClassSession` / `AttendancePage.vue` |
| 描述 | 停用舊課程若仍殘留未來或當日 `ClassSession.Status='scheduled'`，今日點名總表會顯示重複學生；2026-04-29 大直周宏謙由舊課 `StudentClass#527 Stop=1` 的 `ClassSession#6239` 造成。 |
| 建議做法 | 補一個只讀診斷/管理修復工具，列出 `Stop=1` 且未來仍 `scheduled` 的堂次；正式清償時讓所有停用/結案入口共用取消未來堂次邏輯，並加 regression tests。 |
| 清償成本估計 | 中（半天） |
| 不做的代價 | 每次新舊課程銜接都可能在今日點名或老師工作台重複出現，需臨時查 DB 單筆取消，增加 production 操作風險。 |

### TD-017：軍階升階門檻表前端與後端重複

| 欄位 | 內容 |
|---|---|
| 狀態 | Open |
| 優先級 | P2 |
| 發現日期 | 2026-05-10 |
| 發現來源 | 開發中（#326 前端進度條）|
| 影響模組 | `frontend/src/lib/engagementRankProgress.js`、`App\Support\EngagementRankProgression` |
| 描述 | 升階所需 XP 門檻在 PHP 與前端各有一份，日後調整軍階表需手動同步兩處，易漂移。 |
| 建議做法 | 由 `GET /api/v1/me` 的 `engagement` 一併回傳 `next_rank_min_xp`、`xp_to_next` 或完整 thresholds 版本號；前端僅顯示。追蹤：Issue #331。 |
| 清償成本估計 | 低（< 2hr）|
| 不做的代價 | 營運若調整門檻，前端進度條與實際晉階可能短期不一致。 |

### TD-018：`/api/v1/class-sessions` 慢查詢／N+1 系統性審查（Sentry #341/#342/#343/#374/#375）

| 欄位 | 內容 |
|---|---|
| 狀態 | In Progress（2026-05-31：Offender A/B 已批次化清償；主查詢相關 subquery 重寫 → 拆出 TD-058）|
| 優先級 | P1 |
| 發現日期 | 2026-05-13 ~ 2026-05-16（陸續被 Sentry 自動建單） |
| 發現來源 | Sentry production monitor (`PHP-LARAVEL-14`～`PHP-LARAVEL-18`) + `perf-2026-05-17.log` |
| 影響模組 | `ClassSessionController`、`ProfileController`、`SchedulesController`、`UserCampus`/`Subject`/`schedules` lookup paths |
| 描述 | `GET /api/v1/class-sessions` 在 production 經常 1–3.5s（SLO 800ms），對應 Sentry 報告的 N+1（`UserCampus.CampusID`、`Subject.Subject_Name`、`schedules.teacher_id`）與慢 count(*)/ClassSession 主查詢。實際 trace 範例：`trace_id 4ccbe11bc093cc1c → 3574ms`、`aa443a26b60342ce → 3265ms`。 |
| 建議做法 | 1) 用 Sentry full payload 對齊 N+1 來源（需要瀏覽器 access）；2) 在 `ClassSessionController::index` 加 `with(['studentClass.subjectRecord', 'studentClass.student.campus', 'teacher'])` 或對應 eager load；3) `Subject` lookup 改 cached map；4) `UserCampus` 解析改 single batch query；5) `schedules.teacher_id` 對應的迴圈處改 join。 |
| 清償成本估計 | 中（半天～一天，含實測前後對比）|
| 不做的代價 | 行事曆／教師工作台載入時間波動大，使用者體感差；Pi 資源與 SLO 都被 burn。 |
| 對應 GitHub | Sentry auto-issues #341 / #342 / #343 / #374 / #375 已 close 並指向此 TD；GitHub #546。需要 AI 有 Sentry 瀏覽器 access 才能精準下藥。 |
| 清償紀錄（2026-05-31 / PR fix/546）| 實測 code review 後發現主查詢的 `Subject`/`schedules`/campus 早已改為單一多 join（非 N+1），主查詢用的複合索引 `idx_sched_course_date_time_status`、`cs_scid_sessiondate_idx` **均已存在**。真正剩下的 N+1 為兩處迴圈：**Offender A** `autoMaterializeTeacherMonthlySessionsForRange`（老師當日載入時每堂 2 次 `exists()`，隨課數線性成長＝TeacherHome/SmartCalendar 熱路徑）→ 改為 2 次批次 SELECT + in-memory set；**Offender B** `logSessionCountMismatches`（flag-gated，每課程一次 `SessionCount` 查詢）→ 改為單次 `whereIn pluck`。回歸測試 `ClassSessionsTeacherAutoMaterializeMonthlyTest`（query-count 不隨課數成長 + 無重複建立）。輸出 JSON 合約未變。主查詢的相關子查詢（Offender C：`MAX(sub2.id)` correlated subquery + `DATE()/SUBSTRING()` 去索引化）重寫風險高（牽動代課老師解析），拆 TD-058 待 Sentry payload 對齊後處理。 |

---

### TD-019 ~ TD-027：Enterprise Ops Execution Batch（Epic #469 子項）

| TD | 來源 issue | 標題 | 狀態 | 優先級 | 備註 |
|----|----------|------|------|------|------|
| TD-019 | #475 | Staging / pre-production 環境 | Deferred — 需 CEO 預算決策 | P2 | 設計 → `OPERATIONS_RUNBOOK.md` §U |
| TD-020 | #488 | Feature flags lite framework | Deferred — 等第一個試點 feature 出現再實作 | P2 | 設計 → `OPERATIONS_RUNBOOK.md` §V |
| TD-021 | #479 | Playwright visual regression（5 頁） | Deferred — #461 進維護期再啟動 | P3 | 設計 → `OPERATIONS_RUNBOOK.md` §W |
| TD-022 | #478 | Core API contract / golden tests | Deferred — 等 `route:list` snapshot 工具確定 | P2 | 既有 `QA_GOLDEN_SCENARIOS.md` 已涵蓋部分 |
| TD-023 | #490 | Perception pulse survey 實作 | Deferred — 設計完成，等 #488 flags 後試點 | P2 | 設計 → `PROFESSIONAL_PERCEPTION_SURVEY.md` |
| TD-024 | #492 | Audit log for sensitive admin actions（v1 實作）| Open — 設計完成 | P1 | 設計 → `security/AUDIT_LOG_POLICY.md`；獨立 PRD 待開 |
| TD-025 | #485 | Structured logging + 5xx digest cron | Open | P2 | request_id / campus_id structured field |
| TD-026 | #470 | Branch protection 啟用驗證 | Open — runbook 已寫，等 admin 在 GitHub Web UI 套用 | P1 | `OPERATIONS_RUNBOOK.md` §R |
| TD-027 | #474 | SSH key 季度輪替啟動 + 第一次紀錄 | Open — SOP 已寫，等第一次輪替 | P2 | `OPERATIONS_RUNBOOK.md` §S |

---

### TD-051 ~ TD-054：OWASP ASVS L1 缺口（#491）

| TD | ASVS | 標題 | 優先級 | 備註 |
|----|------|------|------|------|
| TD-051 | 2.1.1 | 密碼最小長度 8 → 12 | P2 | 需評估老師現有帳號遷移 |
| TD-052 | 2.1.3 | 登入錯誤訊息（帳號 vs 密碼）收斂 | P3 | 小工程 |
| TD-053 | 3.4.1 | session idle timeout 全系統統一 | P2 | RFID / parent / staff 三套 TTL 不一致 |
| TD-054 | 13.4.1 | `/swipe-rfid` 端點 rate limit | P2 | 防 RFID reader 異常爆量 |

### TD-055：單堂改時間未標記 `IsContractException`，刻意調課會被誤判為「堂次偏移」並可能被 realign 還原

| 欄位 | 內容 |
|---|---|
| 狀態 | Done（2026-05-31，PR fix/556）|
| 優先級 | P2 |
| 發現日期 | 2026-05-31 |
| 發現來源 | [BUG] #135/#556 根因調查 |
| 影響模組 | `ClassSessionController::update`、`StudentClassController`（schedule_drift / force_partial_rebuild）|
| 描述 | 主任用「單堂編輯」改某堂時間（`PATCH /class-sessions/{id}`）時，不會設 `IsContractException=1`。因此該堂會被 `schedule_drift` 判為偏移；若之後對課程按「編輯→儲存」(force_partial_rebuild)，會把這堂「還原」回固定排課時段，覆蓋主任刻意的調整。這是 #135「固定排課錯時段」歧義的根因之一（無法區分『不小心漂移』與『刻意單堂調課』）。|
| 建議做法 | 單堂改時間且新時段 ≠ 契約時段時，標記 `IsContractException=1`（或新增 `manual_reschedule` 旗標），使其從 drift 偵測與 realign 中排除；並在 UI 標示為「已調整」。需產品確認語意（調課 vs 補課例外）後設計。|
| 清償成本估計 | 中（半天，含測試 + drift 回歸）|
| 不做的代價 | 主任刻意調課顯示為錯誤、或被 realign 還原；#135 類問題會反覆出現 |
| 清償紀錄 | 採「標記 IsContractException」做法：`ClassSessionController::applyTimeAndNoteUpdates` 於有時間異動時呼叫 `syncContractExceptionFlag`，依新時段是否吻合契約（鏡像 `StudentClassController::sessionMatchesContract`）設/清旗標。語意定為「單堂刻意調課＝契約例外」（沿用既有 add-session 例外語意，非補課）。回歸測試見 `StudentClassScheduleDriftExceptionTest`（3 新案）。|

---

### TD-056：System B（意見箱 `parent_feedback`）回覆端點缺 per-row 分校 ownership

| 欄位 | 內容 |
|---|---|
| 狀態 | Done（2026-06-29，PR #1056） |
| 優先級 | P2 |
| 發現日期 | 2026-05-31 |
| 發現來源 | [SEC] 家長回饋雙向回覆（System A）開發中順手審查 |
| 影響模組 | `ParentFeedbackController::{forTeacher,markReadByTeacher,reply,replies}`、`routes/api.php` |
| 描述 | System B 的 `parent-feedback/{for-teacher,read,reply,replies}` 已收斂進 `role:teacher,director,super_admin`+`require_campus`；PR #1056 再為 `{id}/read`、`{id}/reply`、`{id}/replies` 補上 feedback row 級 ownership。|
| 建議做法 | 已採 controller `authorizeStaffParentFeedback`：teacher 必須有該學生的 active `StudentClass`，director 的 `auth_campus_ids` 必須包含 feedback `campus_id`，super admin 保留全域權限。System A/B 是否合併仍由 TD-057 追蹤。|
| 清償成本估計 | 低（< 2hr，含測試）|
| 不做的代價 | 若日後接上 System B 前端，跨校員工可能讀／回他校意見箱；與 System A 隔離標準不一致 |
| 清償紀錄 | PR #1056 / commit `a28e439`；`ParentFeedbackTest` 覆蓋非授課老師 read 403 與跨校主任 replies 403，required checks 全綠。|

---

### TD-057：家長回饋 System A / System B 雙軌並存 + Phase 2 KPI

| 欄位 | 內容 |
|---|---|
| 狀態 | Open |
| 優先級 | P3 |
| 發現日期 | 2026-05-31 |
| 發現來源 | [PLAN/REVIEW] 家長回饋雙向回覆 |
| 影響模組 | `learning_record_feedbacks`(+replies) / `parent_feedback`(+replies)、`LearningRecordFeedbackController`、`ParentFeedbackController`、`AdoptionInsightsController` |
| 描述 | 家長回饋有兩套獨立系統：System A（每筆評量回饋，已是主要入口、已雙向回覆）與 System B（意見箱，UI 幾乎未接）。兩套 schema/權限/通知各自為政，長期維護成本高。另外 Phase 2 規劃的 KPI（真實回覆率／平均回覆時效／未回覆積壓）需以本次新增的 replies 資料為基礎，且 `analytics` 目前的 `replied_records` 仍以「有回饋」近似，非「真正有員工回覆」。|
| 建議做法 | (1) Phase 2：以 `learning_record_feedback_replies`（author_role∈teacher/director）計算真實回覆率與時效，更新 `analytics` 與 `AdoptionInsightsController`，並做老師 KPI 儀表板。(2) 評估 System A/B 合併為單一家長訊息中心，下架 System B 未用路由。|
| 清償成本估計 | 高（> 1 天）|
| 不做的代價 | 兩套系統持續分歧；KPI 指標失真（reply_rate 不代表真有回覆）|

---

### TD-058：`/class-sessions` 主查詢代課老師 correlated subquery 去索引化（TD-018 Offender C）

| 欄位 | 內容 |
|---|---|
| 狀態 | Done（2026-06-01，`index()` 已改 derived-table join；`teacherTrust` 同款 subquery 待後續）|
| 優先級 | P2 |
| 發現日期 | 2026-05-31 |
| 發現來源 | [SRE/ARCH] #546 / TD-018 清償時拆出 |
| 影響模組 | `ClassSessionController::index`（代課老師 `sub_sched` leftJoin）、`teacherTrust`（同款 subquery）|
| 描述 | 主查詢以 `sub_sched` leftJoin 解析代課老師，ON 條件含 per-row correlated subquery `sub_sched.id = (SELECT MAX(sub2.id) ...)`，且 `DATE(schedule_date)`、`SUBSTRING(start_time,1,5)` 包裹欄位使既有複合索引 `idx_sched_course_date_time_status` 無法命中。這是主查詢 1–3.5s 慢的主因（非 transform N+1，transform 已是純記憶體）。|
| 建議做法 | 將 correlated `MAX(sub2.id)` 改為「每 (student_course_id, schedule_date, start_time) 取最新代課 schedule」的單一 derived-table join（鏡像現有 `lr`/`si` 衍生表 join），保持 `substitute_teacher_id`/COALESCE 老師名稱與 `effective_status` 結果 byte-identical；評估改存正規化 `HH:MM` 以移除 `SUBSTRING()`。先以 Sentry full payload + golden-output 快照保護再下藥。|
| 清償成本估計 | 中（半天，含 golden 快照與 EXPLAIN 前後對比）|
| 不做的代價 | 行事曆／點名主查詢持續慢、SLO burn；代課解析邏輯複雜，貿然改易回歸（曾有 schedules.id=611 HH:MM:SS 遺留坑）|
| 清償紀錄（2026-06-01）| `index()` 的 `sub_sched` leftJoin 由 per-row correlated `MAX(sub2.id)` subquery 改為預彙總 derived-table join（鏡像 `lr`/`si`）：inner `GROUP BY (student_course_id, schedule_date, SUBSTRING(start_time,1,5))` 取 `MAX(id)`，`teacher_id <> sc2.TeacherID`/`status`/`original_schedule_id` 過濾移入彙總。`schedule_date`=DATE、`start_time`=string → GROUP BY 等同原 DATE()/SUBSTRING() 正規化，無多出列。golden：18 代課測試 + ClassSessionApi/SameDayMultiSlot/Batch/Duplicate/TimeSync/Reschedule 全綠 byte-identical；PHPStan 0。**殘留**：`teacherTrust` 同款 subquery 未改（流量低，另開）。|

### TD-059：共用課程包（PackageDeductionService）尚未支援部分時數扣堂（#613 後續）

| 欄位 | 內容 |
|---|---|
| 狀態 | Open — **B monitored risk** — [#1343](https://github.com/jerry200176-png/AllTrue_System/issues/1343) |
| 優先級 | P3（monitor；首次 package 部分分鐘命中 → 升 P1） |
| 發現日期 | 2026-05-31 |
| 發現來源 | [DEV] #613 A1 落地；2026-07-19 Founder closeout 要求獨立 Issue |
| 影響模組 | `App\Services\PackageDeductionService`（共用池 ledger 鏡像）|
| 描述 | #613 讓單一 `StudentClass` 支援分鐘制部分扣堂，但共用課程包的池鏡像仍以 `delta=±1`（整堂）同步。若部分時數補課發生在**共用包**成員身上，池餘額與個別課的分鐘餘額會漂移。目前單人課程（多數情境）已正確。|
| 建議做法 | **Decision B**：不 migration。維持 open；**活監測** `ops-td059-monitor.yml`（週一／四 + dispatch）：partial minutes on package members >0 → 自動留言 #1343、升 P1、去識別 evidence、禁止自動 schema。Clean 不每日噪音。 |
| 清償成本估計 | 監控低；實作視命中 |
| 不做的代價 | 共用包 + 部分補課罕見組合可能漂池；目前 all-time 命中=0 |
| GitHub Issue | [#1343](https://github.com/jerry200176-png/AllTrue_System/issues/1343) |
| 調查結果（2026-07-19）| multi_member=46；partial minutes（deduct/reverse/makeup-tag）=0；null-minutes whole-session 路徑仍為主。FN：舊資料無 minutes、未標記 manual/refund 可能漏。不關單、不 schema。 |
| Monitor owner | platform-ops；頻率 Mon/Thu 10:30+08；blind spot=null-minutes 歷史整堂扣 |

### TD-060：`ClassSessionController::recalculateSessionCounters` 為死碼（無 caller）且非分鐘感知

| 欄位 | 內容 |
|---|---|
| 狀態 | **Done**（2026-07-28，架構稽核備忘 Pattern A follow-up）|
| 優先級 | P3 |
| 發現日期 | 2026-05-31 |
| 發現來源 | [REVIEW] #613 調查 |
| 影響模組 | `ClassSessionController::recalculateSessionCounters`（private，已刪除）|
| 描述 | 此方法以 count-based（completed+attended）重算 `RemainingSessions`，與權威引擎 `SessionDeductionService::recomputeCounters` 並存。調查確認目前**無任何 caller**（死碼），故不會覆寫 #613 的分鐘衍生值；但保留會誤導，且若日後被誤用會與分鐘制分歧。|
| 清償紀錄 | 直接刪除該方法（採建議做法的「移除」選項，而非薄包裝委派——委派到一個沒有 caller 的方法沒有意義）。確認 `SessionDeductionService::recomputeCounters` 已涵蓋同等的 legacy `attended` 相容性（`whereIn('Status', ['completed','attended','late'])`），且更完整（含 `StudentSignIn`/ledger/orphan LearningRecord 訊號、分鐘制衍生）。原本透過 Reflection 呼叫此死碼的測試 `ClassSessionBatchApiTest::test_recalculate_session_counters_counts_legacy_attended_for_compatibility` 已改為直接呼叫 `SessionDeductionService::recomputeCounters()`，斷言不變。同時清掉 `phpstan-baseline.neon` 對應的「unused method」豁免項。與 R83/R84 同一類根因：衍生欄位的重算邏輯有第二份未接線的複製，是還沒發作的地雷。|
| 建議做法 | 移除該方法，或改為薄包裝委派 `SessionDeductionService::recomputeCounters`，統一單一扣堂權威路徑。|
| 清償成本估計 | 低（< 1hr）|
| 不做的代價 | 死碼誤導後續開發者；潛在被誤用導致與分鐘制不一致 |

### TD-061：相依套件已知漏洞待升版（OSV 深掃 #544 首次結果）

| 欄位 | 內容 |
|---|---|
| 狀態 | Open |
| 優先級 | P2 |
| 發現日期 | 2026-05-31 |
| 發現來源 | `osv-scanner.yml` 首次深掃（#544）|
| 影響模組 | `backend/composer.lock`、`frontend/package-lock.json` |
| 描述 | OSV 深掃發現 5 筆已知漏洞（**0 Critical / 0 High**；3 Medium、1 Low、1 Unknown），PR 阻擋型 gate（composer/npm audit on HIGH/CRITICAL）正確未擋。處理進度：<br>1. ✅ **已修** `symfony/routing` v5.4.48 → v5.4.53（PR #649，platform pin 後）。<br>2. ✅ **已修** `symfony/polyfill-intl-idn` v1.33.0 → v1.38.1（PR #649）。<br>3. ⏳ `laravel/framework` 8.x → GHSA-78fx-h6xr-vch4 / CVE-2025-27515（6.9 Medium，fixed 10.x）— 已先 pin 至穩定 `v8.83.29`；根治需 Laravel 8→10 大版遷移（見 **TD-014**）。<br>4. ⏳ `esbuild` 0.21.5 → 0.25.0（dev-only，5.3；vite 連動）。<br>5. ⏳ `vite` 5.4.21 → 6.4.2（dev-only，6.3；vite 5→6 為 major，需驗 `npm run build`）。|
| 建議做法 | symfony patch ✅ 已隨 platform pin 收斂；vite/esbuild dev major 單獨 PR 驗 `npm run build` + `test:calendar`；Laravel 8→10 併入 TD-014 epic。每月 review OSV 深掃（RUNBOOK §Y/§R1c）。|
| 清償成本估計 | symfony ✅ 完成；vite/esbuild 中；Laravel 升級 高 |
| 不做的代價 | 剩餘為 dev-only + Laravel-8 EOL；皆非 Critical/High，無立即生產風險 |

### TD-062：行事曆載入慢（前端換週全量重抓 + 後端主查詢慢）

| 欄位 | 內容 |
|---|---|
| 狀態 | Done（Phase 1–3 + P4-a/b + Step 7 composables 已落地；PRD：`.cursor/plans/calendar-performance-epic_2026-06-01.md`）|
| 優先級 | P1 |
| 發現日期 | 2026-06-01 |
| 發現來源 | [SRE] 行事曆載入慢調查（使用者回報）；對標 Cal.com / FullCalendar |
| 影響模組 | `frontend/src/pages/SmartCalendar.vue`、`frontend/src/lib/calendarLoadPerformance.js`；後端 `ClassSessionController::index`（另見 TD-018/TD-058）|
| 描述 | 換週/換日只重跑 `loadCourses()`，但其為 3 個 await 串行 waterfall，且 `student-classes` 無日期視窗（最多 ~10k 列）、無 client 快取 → 每次導覽都付全量延遲。後端主查詢慢（代課 correlated subquery）另見 TD-058。|
| 建議做法 | ✅ Phase 1–3（視窗快取、ClassSession range index、TD-058 join）。✅ **P4-a**：`Promise.all` 平行抓取。✅ **P4-b（2026-06-07）**：`StudentClassController::index` 可選 `start`/`end` 視窗 + 前端 `calendarCourseLoad` 傳參；`StudentClassIndexCalendarWindowFilterTest`。✅ **Step 7 composables**：`SmartCalendar.vue` 5260→3308 行。⏳ 可選：再剝 `useCalendarCourseEdit` 達 <3000 行。|
| 清償成本估計 | 中（前端 Phase 1）+ 中（後端 P2/P3）|
| 不做的代價 | 行事曆互動體感持續慢；與後端慢查詢疊加放大 |

### TD-063：學習回饋推播 flip flag 前的 fast-follow

| 欄位 | 內容 |
|---|---|
| 狀態 | Open |
| 優先級 | P2 |
| 發現日期 | 2026-06-01 |
| 發現來源 | 開發中（feedback push dark launch，PRD：`.cursor/plans/feedback-push-notifications_2026-06-01.md`）|
| 影響模組 | `frontend/src/pages/ParentPortal.vue`；perfflag `feedback_push_enabled` |
| 描述 | 後端推播機制 + 退出權儲存/端點（`GET/PUT parent/notification-preferences`）已上線但 dark launch（flag 預設 false）。家長端尚無「關閉學習回饋通知」UI toggle。|
| 建議做法 | (1) ParentPortal 設定區加 toggle 串 `parent/notification-preferences`；(2) 確認推播文案/節奏後，設 `PERF_FEEDBACK_PUSH=true` 開啟；(3) 觀察 TD-013（LINE 綁定率）影響觸達上限、補 TD-057 reply-rate KPI。|
| 清償成本估計 | 低（前端 toggle）|
| 不做的代價 | 推播功能停在 dark launch 無法對外；家長無自助退訂 UI（僅後端可改）|

### TD-064：設計系統 semantic token 不足以表達多態功能色

| 欄位 | 內容 |
|---|---|
| 狀態 | Open |
| 優先級 | P3 |
| 發現日期 | 2026-06-06 |
| 發現來源 | 開發中（#691 reference page UI 治理，modal wave C）|
| 影響模組 | `frontend/src/styles.css`（`--ds-*` token）；`SessionEditModal.vue` 出缺勤/計費狀態色；其餘狀態 chip |
| 描述 | 現有 ds semantic token 只有 success(綠)/warning(橘)/danger(紅)/info(=primary 橘)，不足以表達出缺勤多態（scheduled 藍、reschedule 紫、substitute 青）與計費比較（標準/較高/較低）等「功能語意色」。治理 UI 時這些只能保留原始 hex，無法 token 化。|
| 建議做法 | 由 [ARCH] 擴充一組「狀態色票」token（例：`--ds-state-scheduled`/`-reschedule`/`-substitute` + wash 版），定義於 `RULE_DESIGN_SYSTEM.md` §3，再逐頁把功能色 chip 改 token；需確保色盲友善（色 + 文字標籤）。|
| 清償成本估計 | 中（需設計決策 + 多檔替換）|
| 不做的代價 | 狀態 chip 持續用零散原始 hex，hex baseline 降不下去；跨頁狀態色不一致風險 |

### TD-065：`NotificationObserver`（LINE 推播）因 provider 註冊順序而失效

| 欄位 | 內容 |
|---|---|
| 狀態 | Open |
| 優先級 | P2（需使用者決策後再清，涉及 LINE 推播行為） |
| 發現日期 | 2026-06-13 |
| 發現來源 | [BUG] 修 #766 audit observer 時發現同源問題 |
| 影響模組 | `backend/app/Providers/AppServiceProvider.php`、`backend/app/Observers/NotificationObserver.php`（#80 LINE push） |
| 描述 | `config/app.php` 把 `App\Providers\AppServiceProvider` 註冊在 `Illuminate\Database\DatabaseServiceProvider` 之前，導致 `boot()` 執行時 Eloquent event dispatcher 尚未綁定，`Notification::observe(NotificationObserver::class)` 靜默 no-op。推測自 #80 起 LINE staff 推播 observer 從未真正觸發（需以實機/生產佐證）。#766 已用 `app->booted()` 延遲註冊修好 `ClassSessionObserver`，但**刻意未動** `NotificationObserver`，以免在本次 scope 外改變 LINE 推播行為。|
| 建議做法 | 由 [ARCH]+[SEC] 評估：(1) 確認生產 LINE 推播是否確實未經此 observer（可能另有路徑）；(2) 若確為失效，將 `Notification::observe` 一併移入 `app->booted()` 或把 `AppServiceProvider` 移回 config/app.php 慣例位置（框架 provider 之後）；(3) 啟用前先驗證不會對既有 staff 造成突發推播灌爆。|
| 清償成本估計 | 低（程式改動小）/ 中（需驗證 LINE 行為與生產佐證） |
| 不做的代價 | 若 LINE staff 推播確實失效，相關通知持續不送達，且 root cause 隱藏在 provider 順序中難察覺 |

### TD-066：老師頁（teachers）PII 後端 PIN 強制因端點共享而延後

| 欄位 | 內容 |
|---|---|
| 狀態 | **Resolved（2026-06-13）** — 改採控制器層欄位級遮罩（`PinGate::isUnlocked()` + `ProfileController` 遮罩 phone/line_id/rfid），不整路掛 require_pin 故不誤傷共享下拉；soft 零回歸。計畫見 `.cursor/plans/td066_teacher_pii_pin_2026-06-13.md`。 |
| 優先級 | P2（安全縱深；前端 gate 已覆蓋 UX，後端邊界缺口） |
| 發現日期 | 2026-06-13 |
| 發現來源 | [DEV] #769 Phase C 掛 require_pin 時發現 |
| 影響模組 | `backend/routes/api.php`（`GET /api/v1/teachers`）、`frontend`（CourseManagement／StudentsList／LearningRecordsPage 復用同端點） |
| 描述 | #769 D2 把「老師管理」列為受保護敏感頁，但其後端資料端點 `GET teachers`（type=T profiles）被 CourseManagement／StudentsList／LearningRecordsPage 等**非敏感頁**作為老師下拉/篩選共用。若直接掛 `require_pin`，會在已設 PIN 的主任於那些頁取老師清單時被 423 誤擋。故 Phase C **刻意未對 `teachers` 掛 require_pin**；該頁 PII 目前僅由 Phase B 前端 gate 保護（前端可被繞過，非真正邊界）。薪資／財務頁因端點專屬已正常後端強制。|
| 建議做法 | 為「老師管理」頁建立**專屬唯讀端點**（例 `GET teachers/directory` 或帶完整 PII 的 `teachers/{id}` 詳情）僅供該頁使用，於其上掛 `require_pin`；其他頁的輕量下拉改用不含敏感 PII 的精簡端點。或評估以 policy/欄位遮罩在 controller 層按 `pin_verified` 決定回傳欄位。|
| 清償成本估計 | 中（需拆端點 + 前端改呼叫 + 測試） |
| 不做的代價 | 老師個資（聯絡方式等）後端無 PIN 強制，僅靠前端 gate；繞過前端直打 `teachers` 仍可取得，與 #769 KPI「後端 100% 擋下」有缺口 |

### TD-067：#1197 孤兒前端 — BatchInvoiceModal / OverdueBucketsPanel 可觸發必然失敗 API

| 欄位 | 內容 |
|---|---|
| 狀態 | Open |
| 優先級 | P1 |
| 發現日期 | 2026-07-21 |
| 發現來源 | [REVIEW] 收據 404 hotfix closeout（#1363 後） |
| 影響模組 | `BatchInvoiceModal.vue`、`OverdueBucketsPanel.vue`、`TuitionCollectionPage.vue` |
| 描述 | PR #1197 前端-only 上線；後端 `/invoices/batch-preview`、`/invoices/batch`、`/invoices/overdue-summary` 從未進 main。使用者若點到這些入口會固定失敗。收據已改回 payment-reports；這兩塊仍是孤兒 UI。 |
| 建議做法 | 先確認 production 可否觸發 → 可觸發則 hide／feature-flag／disable；禁止半套 stub route。完整 API 另走 PLAN。 |
| 清償成本估計 | 低（隱藏入口）／中（若要真做後端） |
| 不做的代價 | 主任繼續點到必失敗操作，信任再次受損（與收據 404 同族：前端超前後端） |

### TD-068：Receipt Domain T3（immutable snapshot／PDF／void／legal-info）— blocked

| 欄位 | 內容 |
|---|---|
| 狀態 | Open（**Blocked**：未經 Founder 批准不得 DEV） |
| 優先級 | P2（產品意圖）／實作為 T3 |
| 發現日期 | 2026-07-21 |
| 發現來源 | [REVIEW] #1197 殘缺契約；hotfix 僅恢復查看 |
| 影響模組 | 帳務收據、Campus legal-info、PDF、void lifecycle |
| 描述 | #1197 假設的完整 Receipt API 從未落地。目前查看走 `payment-reports/{id}/receipt`；UI 用「電子收據」。完整「正式收據」需 immutable snapshot、編號、分校隔離、void/audit、PDF、歷史/backfill、rollout/rollback。 |
| 建議做法 | 先寫 T3 PLAN/ARCH/SEC；批准後再 DEV。長期加 backend contract test + typed client／OpenAPI + deploy 後 authenticated synthetic。 |
| 清償成本估計 | 高 |
| 不做的代價 | 無法提供法定完整收據／PDF／作廢；但查看路徑已可用，不阻塞日常核帳 |

---

### TD-069：`ScheduleController::retroLeave()` 與 `ClassSessionController::handleRetroLeaveTransition()` 補請假邏輯重複，且尾端補課策略不一致

| 欄位 | 內容 |
|---|---|
| 狀態 | Open |
| 優先級 | P2 |
| 發現日期 | 2026-07-28 |
| 發現來源 | [REVIEW] R55 資源復活政策稽核時順手發現（架構稽核備忘 Pattern A/B 交界） |
| 影響模組 | `ScheduleController::retroLeave()`、`ClassSessionController::handleRetroLeaveTransition()`、`ClassSessionController::voidAttendanceArtifacts()` |
| 描述 | 兩個獨立 endpoint 都實作「已上課堂次補登請假」：作廢 `StudentSignIn`/`LearningRecord`（`VoidReason='補請假：已上課改請假'`）、`SessionDeductionService::reverseForSession(...,'retro_leave',...)` 沖回、`Status='leave_adjusted'`。**不是逐字複製**——經比對後尾端補課策略實際不同：`ClassSessionController` 呼叫 `tryExtendOnLeave()`（count-based，只在「有效堂次數 < SessionCount」時補一堂）；`ScheduleController::retroLeave()` 呼叫 `CourseLeaveCascadeService::appendTailAfterLeave()`（Founder Decision 2026-07-26 的 keep-future-dates-append-tail 政策），且額外在無任何簽到記錄時建立一筆 closed leave `StudentSignIn`（`ClassSessionController` 沒有這步）。與 R83/R84、TD-060、R55 同一類根因（同一決策兩處各自維護），但**consolidation 本身有實質風險**：不是單純刪重複，需先確認兩個尾端補課策略哪個才是現行正確政策（或本來就該依呼叫情境不同），貿然合併可能改變請假/補課的實際行為。 |
| 建議做法 | 不建議直接合併。先確認：(1) 這兩個 endpoint 目前分別被哪些前端流程呼叫、是否有重疊 (2) `tryExtendOnLeave` 是否為舊政策的殘留（`appendTailAfterLeave` 明確引用較新的 Founder Decision），若是則评估汰換 `ClassSessionController` 那份改呼叫 `CourseLeaveCascadeService`。voidAttendanceArtifacts 的部分（作廢 sign-in/LR + reverseForSession）可以先安全抽成共用 helper，這段兩處確實一致。 |
| 清償成本估計 | 中～高（半天以上，需先做行為盤點，不可只看程式碼相似度） |
| 不做的代價 | 兩處補請假邏輯持續各自演進，未來若只改一邊（例如尾端補課規則再變），另一邊會悄悄落後，重演 R55 那種「兩處判斷各自維護、其中一份沒跟上」的缺口 |

### TD-070：`SMOKE_DIRECTOR_USER`/`SMOKE_DIRECTOR_PASS` 未設定，`ui-smoke.yml` 的 director 路徑（含課程管理頁）從未真正執行過

| 欄位 | 內容 |
|---|---|
| 狀態 | Open（建議做法 (2) 已完成——`ui-smoke.yml` 新增「Warn if smoke secrets are missing」步驟，缺 secret 時印出 `::warning::` annotation，讓「這條防線目前是空的」在每次 CI run 都可見；(1) 補真正的測試帳密、(3) 部署後 synthetic check 仍待處理，見下方） |
| 優先級 | P1 |
| 發現日期 | 2026-07-29 |
| 發現來源 | 課程管理頁 P0 整頁空白事故（主任回報，見 CHANGELOG 2026-07-29 fix(course-management)）事後根因鏈追查 |
| 影響模組 | `.github/workflows/ui-smoke.yml`、`frontend/e2e/smoke.spec.js`（`UI smoke — director` describe block） |
| 描述 | `smoke.spec.js` 早就寫了 `director: 課程管理頁與待補課面板載入` 測試（登入主任帳號 → 切到課程管理 → 斷言 0 個 `pageerror`），理論上今早引入 bug 的 PR #1409 應該過不了這條測試。但實測：`ui-smoke.yml` 讀取的 `secrets.SMOKE_DIRECTOR_USER`/`SMOKE_DIRECTOR_PASS` 是空字串，`smoke.spec.js:70` 的 `test.skip(!BASE \|\| !DIRECTOR.account, …)` 因此把整個 `UI smoke — director` describe block 靜默跳過——不是失敗、是「skipped」，PR 頁面顯示綠勾，看起來像測試通過。已在本次修復 PR #1502 的 CI run 裡重新確認：`SMOKE_DIRECTOR_USER: `／`SMOKE_DIRECTOR_PASS: `（空白）、`2 skipped`。這是本次事故完整根因鏈的最後一環：composable 沒被真正測試（R86）+ 沒有 TypeScript/ESLint no-undef 靜態檢查 + 唯一能攔住的 E2E 防線因缺 secret 而從未執行。 |
| 建議做法 | (1) 由 repo owner 在 GitHub repo Settings → Secrets 補上一組**測試用**主任帳密（不要用真人 production 帳號；建議建立一個獨立、無敏感資料存取範圍的「主任角色 QA 帳號」）。(2) 更嚴格的修法：把「因缺 secret 而 skip」與「因程式碼問題而 skip」分開——目前 `test.skip()` 讓兩者外觀一致（都是灰色 skipped），建議 CI 另外加一個 assertion（例如 workflow 層印出 `::warning::SMOKE_DIRECTOR_* not set — director smoke path is not exercised`），讓「這條防線目前是空的」這件事在每次 CI run 都可見，而不是要翻 log 才看得到。(3) 中期可比照大型組織做法：對「頁面完全無法渲染」這類 P0 症狀，不應只靠 PR-time smoke test，應該在 `deploy.yml` 部署後跑一次最小 synthetic check（curl 或無頭瀏覽器打開 3–5 個高流量頁、確認無 JS exception）才把這次 deploy 視為成功，不成功則自動标记/通知，而非等使用者回報。 |
| 清償成本估計 | 低（申請/建立測試帳號 + 補 GitHub secret，約 30 分鐘；CI 層的「可見 skip」改進約 1 小時） |
| 不做的代價 | 這類「整頁完全空白」的 P0 前端 crash 會持續只能靠使用者主動回報才發現（本次事故從部署到主任回報間隔 4 小時以上），而 CI 頁面會持續顯示綠勾造成團隊誤以為有 E2E 防線，形成比「完全沒有測試」更危險的假安全感 |

### TD-071：前端 ESLint 目前只開 `no-undef`，`no-unused-vars`／完整 recommended ruleset 尚未啟用

| 欄位 | 內容 |
|---|---|
| 狀態 | Open |
| 優先級 | P2 |
| 發現日期 | 2026-07-29 |
| 發現來源 | 課程管理頁 P0 事故修復時，順手加了 `frontend/eslint.config.js`（`no-undef` only, blocking, 已接進 `npm run build` 第一步） |
| 影響模組 | `frontend/eslint.config.js`、`frontend/package.json`（`lint:no-undef` script） |
| 描述 | `no-undef` 已驗證能攔住今天這類「引用未宣告變數」的 bug（用今天的實際 diff 反向驗證過：加回壞的那行會被抓到，`git stash` 復原）。但 `no-unused-vars` 試跑時在既有程式碼上噴了 134 個 false positive——原因是這批 `<script setup>` 裡宣告的 function/變數大多是給 `<template>` 用的，而目前 config 沒接 `eslint-plugin-vue` 的 template 分析（需要 `vue/setup-compiler-macros` 或走它的 recommended flat config + `vue-eslint-parser` 完整串接，而不是只用 parser 而已），單純開 `no-unused-vars` 會大量誤判既有程式碼有問題。 |
| 建議做法 | 分階段：(1) 先接上 `eslint-plugin-vue` 的 `flat/recommended`（或至少讓 template 內的識別字使用能正確標記為「已使用」），跑一次看 false positive 是否消失。(2) 若跑出來是「真的未使用」的 dead code（不是 false positive），比照 `phpstan-baseline.neon`／`docs/design-hex-baseline-2026-06-06.json` 的既有模式做 baseline-gate（只擋新增，不強制清歷史債），避免一次性巨大 diff。(3) 之後再視情況評估是否導入完整 `eslint:recommended` 或 TypeScript（後者成本高很多，需要另外立案）。 |
| 清償成本估計 | 中（vue-eslint-parser + eslint-plugin-vue 完整串接約半天；baseline 產生腳本仿造既有 hex/phpstan 模式再半天） |
| 不做的代價 | 目前只防得住「引用未宣告變數」這一種錯誤；同一類「宣告了但沒接上」的殘留變數（例如 import 進來卻沒用、重構後留下的 dead helper）仍然只能靠 code review 肉眼抓 |

### TD-072：非標準時長 Phase 0 — coverage calculator（preview）與 inventory reporter（既有資料盤點）是兩套獨立實作，未交叉驗證會收斂到同一個 `uncovered_minutes` 定義

| 欄位 | 內容 |
|---|---|
| 狀態 | Open |
| 優先級 | P3（Phase 1 開工前建議清償，尚未有任何 production 資料受影響） |
| 發現日期 | 2026-07-30 |
| 發現來源 | `RFC_NONSTANDARD_SESSION_DURATION_BILLING.md` Phase 0 closeout（Phase 0B calculator + Phase 0A reporter 同時實作時發現） |
| 影響模組 | `App\Services\Scheduling\LessonEntitlementCoverageCalculator`（建立前 occurrence-by-occurrence 序列填充）、`App\Services\Scheduling\NonstandardDurationInventoryReporter::buildExistingOverage()`（既有資料 ledger SUM 聚合） |
| 描述 | 兩者都在回答「uncovered_minutes 是多少」，但用兩條不同的計算路徑：calculator 是「依序逐一分配 occurrence」的演算法（用於建課前 preview），reporter 是「對 `session_deduction_ledger` 做 SUM(deduct)−SUM(reverse) 再與 purchased_minutes 相減」的聚合查詢（用於既有資料盤點）。兩者在數學上*應該*對同一組資料收斂到相同數字（因為循序填充與 net-sum 在「沒有 reverse 打亂順序」的情境下是等價的），但目前沒有測試證明兩者在同一組輸入上真的算出一致的 `uncovered_minutes`，尤其是有 `reverse`／`retro_leave` 事件把「淨消耗」打亂成非單調序列時，兩種方法是否仍然一致，尚未驗證。 |
| 建議做法 | 新增 `CalculatorReporterCrossValidationTest`：用同一組（purchased_standard_units、standard_lesson_minutes、occurrence 序列 + 任意 reverse 事件）分別餵給 calculator 與 reporter 的既有資料版本（先寫入對應的 `ClassSession`/`session_deduction_ledger` fixture，再跑 reporter），斷言兩者的 `uncovered_minutes` 一致。若跑出不一致，須在 Phase 1 之前釐清哪一個是「正確」定義，並讓另一個對齊，避免 Phase 2 上線後 preview 顯示的數字與既有資料盤點對不起來造成主任/工程互相懷疑數字有誤。 |
| 清償成本估計 | 低（半天內可寫完交叉驗證測試；若發現真的分歧，修正成本視分歧原因而定） |
| 不做的代價 | Phase 1/2 上線後，若某門課程同時出現在「建課 preview 顯示的 uncovered」與「Phase 0A 報表顯示的 uncovered」，兩個數字不一致會讓人誤以為系統有 bug，實際上只是兩套從未交叉驗證過的獨立實作 |

### TD-073：CI 沒有自動偵測「重複業務邏輯／重複 magic string」的機制

| 欄位 | 內容 |
|---|---|
| 狀態 | Open |
| 優先級 | P1（原 P2，2026-08-06 因第三個實例落在金流顯示邏輯而調升，見下） |
| 發現日期 | 2026-08-06 |
| 發現來源 | 陳禹慈堂數超排案（主任直接回報）根因調查：`CourseLeaveCascadeService::appendTailAfterLeave()` 與 `ClassSessionController::tryExtendOnLeave()` 是同一條業務規則的兩份獨立實作，各自對「已計入堂數」的定義不同步，交替呼叫時會讓已排堂次數悄悄超過購買堂數（PR #1644 修正）；同一批調查也發現 `一般請假` VoidReason 字面值在 `CourseLeaveCascadeService.php` 被重複打了 6 次、`LearningRecordResurrectionPolicy.php` 又獨立抄一份，其中一份被打壞正是 #217/#218 server error 的根因（PR #1645 修正）。詳見 `docs/SYSTEM_TECH_GUIDE.md` §12.4 根因分析。**第三個實例（同日）**：何昀佳帳務中心繳費狀態不一致案（主任直接回報）——`AlertController::computePaymentStatus()` 只認 `StudentClass.Paid` 旗標，沒把「帳單足額收款」算進已繳費判斷，跟課程管理頁面的邏輯不同步（PR #1648 修正，R94）。修復後的盤點掃出這不是單一巧合：`backend/app` 至少 **8 個檔案**（`StudentClassController`、`AlertController` 自身另外兩處、`NotificationSyncService`、`DunningService`、`PaymentReportController`、`ParentPortalController`、`NotificationController`、`AccountingController`、`SendTuitionReminders`）各自獨立重新實作「這筆課程是否已繳費」，且彼此條件互不相同（`Paid` 旗標單一判斷 / `Paid` 或任一筆收款 / `Paid` 或足額收款 / 舊制 `Pay>=Charge` 欄位比較，四種變體並存），`StudentClass`／`Invoice` model 完全沒有任何集中的 `isPaid()`／`isFullyPaid()` 存取器——每個呼叫點都是從零重寫。詳見 `docs/SYSTEM_TECH_GUIDE.md` §12.5。 |
| 影響模組 | 全 `backend/app/` — 這是 CI pipeline 缺口，不是特定模組的問題；歷史上已知至少 **3 類** 實例（本節描述的兩處 + 繳費狀態判斷），其中繳費狀態判斷單一概念就橫跨至少 8 個檔案、4 種互不相同的變體，推測還有未發現的其他實例。`DunningService.php` 中的重複實例已被 `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` 明文凍結、需產品方核准才能改動，尚未一併清償。 |
| 描述 | 目前 CI 的 `PHPStan Advisory` 只抓型別/明顯錯誤，抓不到「兩支函式語意重複」或「同一個業務字串被複製貼上多份」這類問題；這類問題目前完全仰賴 code review 肉眼發現，而本專案是單人 repo（見 `.github/pull_request_template.md` 的「單人 repo Review Gate」說明），沒有第二位人類 reviewer 天然扮演「這是不是已經做過」的守門角色。根因分析（§12.4）認為成熟工程組織能避免這類 bug，關鍵在於把「找找看是不是已經有人做過」從仰賴個人記性的文件建議，變成合併前機器會擋下來的門檻。 |
| 建議做法 | 分階段：(1) 導入 `phpcpd`（PHP Copy/Paste Detector）或等效工具掃 `backend/app/`，先以 advisory 模式跑一輪盤點既有重複，比照 `phpstan-baseline.neon` 模式建 baseline（只擋新增，不強制清歷史債）。(2) 針對「業務字面值被多處比對」這類更窄但更常見的模式（如 `->where('VoidReason', '...')`、`->where('Status', '...')` 等字串比對），寫一個輕量 grep-based presubmit 檢查，偵測同一個中文/業務字面值在 `>=2` 個檔案或 `>=3` 個位置被直接使用（而非引用常數），達門檻即警告或擋下。(3) 中長期評估是否比照 SonarQube/CodeClimate 等重複程式碼偵測服務。 |
| 清償成本估計 | 中（`phpcpd` 導入 + baseline 產生腳本約 1 天；grep-based magic-string presubmit 檢查約半天；SonarQube 等級整合需另外評估） |
| 不做的代價 | 同一類「兩份獨立實作互不知情，各自正確但合起來錯」或「業務字串被複製後其中一份損毀」的 bug，會持續只能等使用者（家長帳單異常、老師操作卡住）回報才發現，且每次都要重新做一次根因調查才找得到，而非在 PR 合併前就被機器攔下來 |

### TD-074：`LearningRecord`（評量記錄）完全沒有審計/歷史版本機制，內容一旦覆蓋或刪除無法還原

| 欄位 | 內容 |
|---|---|
| 狀態 | Open |
| 優先級 | P2 |
| 發現日期 | 2026-08-06 |
| 發現來源 | in-app #219 修復過程中的自我檢討（R100）——一次已徵得使用者同意的資料修正意外觸發 `maybeRebuildSessionsAfterUpdate()` 整批重建，刪除並重建了課程 3153 的 `ClassSession`/`LearningRecord`。事後想找回評量記錄內容才發現：`ClassSession` 有 `ScheduleAuditLog`/`ClassSessionObserver` 這套審計機制（雖然這次因批次刪除繞過而沒發揮作用，已在同一批修復補上），但 `LearningRecord`（老師實際填寫的評量文字）本身完全沒有對應的審計或版本歷史，內容一旦被覆蓋或刪除就是真的、永久地消失，沒有任何補救管道。 |
| 影響模組 | `backend/app/Models/LearningRecord.php`、所有會修改/刪除 `LearningRecord` 的路徑（`StudentClassController::maybeRebuildSessionsAfterUpdate()`、`LearningRecordController` 各更新端點等） |
| 描述 | `ClassSession` 已有前例（`ScheduleAuditLog` + `ClassSessionObserver`）可以直接參考同一套 pattern：`created`/`updating`/`updated`/`deleted` 各自寫一筆快照。`LearningRecord` 承載的是老師手動輸入、無法自動重新產生的內容，遺失後果比 `ClassSession`（結構化欄位，理論上可從排課規則重建）更嚴重，卻是目前唯一沒有任何保護的核心業務資料表。 |
| 建議做法 | 比照 `ClassSessionObserver` 的作法，新增 `LearningRecordObserver`，掛 `updating`/`updated`/`deleted` 三個事件，寫入既有的 `schedule_audit_logs`（或新開一張同構的 `learning_record_audit_logs`，視是否需要跟 `ClassSession` 的審計記錄分開查詢而定）。同時盤點專案裡是否還有其他會呼叫 `LearningRecord::where(...)->delete()`／`->update(...)` 批次操作的地方，確認都會被 Observer 涵蓋到（批次操作一樣要小心繞過 model events 的問題，見 R100）。 |
| 清償成本估計 | 小～中（新增一個 Observer + 註冊 + 補測試，比照既有 `ClassSessionObserver` 模式，約半天到一天） |
| 不做的代價 | 任何未來的資料修正、bug 修復、甚至老師自己手滑覆蓋，只要動到 `LearningRecord`，內容遺失就是無法逆轉的，且無法事後判斷「原本是不是真的有內容」——這次吳宥萱 6/18 試聽課的評量內容遺失就是因為沒有這層保護，最終只能結案放棄追查。 |

### TD-075：`composer.json` 的 `audit.ignore`（accepted-risk 清單）沒有到期/複查機制

| 欄位 | 內容 |
|---|---|
| 狀態 | Open |
| 優先級 | P3 |
| 發現日期 | 2026-08-07 |
| 發現來源 | 追查 `Pi Health Monitor`／`bugs:verify-reproductions` 連續紅燈（#170）過程中，CI 的 `Composer audit (security)` 步驟被 4 個新公開的 `league/commonmark` HIGH 嚴重度 DoS advisory（GHSA-mh25-x5hq-wrqp 等）擋下，經 reachability review 確認本專案完全不觸發該套件（僅為 `laravel/framework` 內建 Markdown 郵件功能的可選 transitive dependency，本專案未使用該功能），比照既有的 `GHSA-5vg9-5847-vvmq` accepted-risk 先例加入 `composer.json` 的 `audit.ignore`。 |
| 影響模組 | `backend/composer.json` 的 `config.audit.ignore` 清單（目前共 5 條：`GHSA-5vg9-5847-vvmq` + 本次新增 4 條 commonmark advisory） |
| 描述 | `audit.ignore` 是「當下判斷不可達、暫時接受」的風險，不是「永久解決」——但目前這份清單沒有任何複查機制：一旦加進去，除非有人手動想起來去看，否則會無限期留著，即使日後情境變了（例如真的加了 Markdown mail 功能，或 `league/commonmark` 因為是 `laravel/framework` 綁定帶入而無法用 `composer remove` 直接移除，只能等框架升級或改版才可能連帶消失）。大公司的漏洞管理平台（如 Snyk／Dependabot 的 waiver 機制）通常強制每條 accepted-risk 要有 owner 與到期日／複查觸發點，而不是靜默的黑名單。目前本專案唯一的先例（`GHSA-5vg9-5847-vvmq`）綁定了 `#977`（Laravel 8 EOL 遷移）作為複查時機，但這是巧合式的，不是制度化的做法。 |
| 建議做法 | (1) 短期：`league/commonmark` 的 4 條新 ignore 比照 `GHSA-5vg9-5847-vvmq` 的模式，同樣綁定 `#977`（Laravel 8 EOL 遷移）——升級/更換框架時一併重新評估是否仍不可達、是否該套件仍被帶入。(2) 中期：`docs-integrity-check.mjs` 或另開一個輕量 CI 腳本，每次 `composer audit` 有新的 ignore 被新增時，強制 commit message／PR body 要包含 reachability review 日期與理由（目前是慣例，非強制）。(3) 長期：評估是否比照 `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` 的模式，開一份 `SECURITY_ACCEPTED_RISK.md` 集中列出所有 accepted-risk 條目 + 複查頻率，而不是分散在各個 `composer.json`／未來可能出現的 `package.json` audit-ignore 裡各自為政。 |
| 清償成本估計 | 低（短期作法本身已在本次一併完成；中長期作法各約半天） |
| 不做的代價 | Accepted-risk 清單只增不減，幾個月後沒人記得當初為什麼要 ignore、是否還成立，新加入的工程師（或未來的 AI agent）看到一長串 ignore 只能照單全收，逐漸變成「反正 CI 會過」的安全放行章，而非真的持續被驗證的風險判斷 |

### TD-077：正職老師「行政加給倍率」尚未實作，總發放金額目前只組合四項多乘標準

| 欄位 | 內容 |
|---|---|
| 狀態 | Open |
| 優先級 | P2 |
| 發現日期 | 2026-08-13 |
| 發現來源 | 建置正職結算「底薪＋總發放金額」（`FulltimeSettlementComposer`）時，對照 115.07 薪資規定公告全文才發現既有 `TeacherEligibilityPolicy` 的六項要件（每週16段、假日16小時、平日下午課、特殊表現、扣除、科目數獎金）沒有涵蓋公告第 3 條第 4 項「行政加給倍率」。 |
| 影響模組 | `backend/app/Services/TeacherEligibilityPolicy.php`、`backend/app/Http/Controllers/TeacherEligibilityController.php`、`backend/app/Support/FulltimeSettlementComposer.php`、`teacher_payroll_events`／`_achievements`／`_deductions` 三張表（需要第四種分類或新表） |
| 描述 | 公告「教師獎金倍率制度」列了 4 個可疊加的倍率項目：假日16小時、平日下午5段課、科目數(≥20科)、**行政加給(行政協助／總導師／副主任，0～10%，主任判定總部審核)**。目前系統只做了前三項＋特殊表現＋扣除，`FulltimeSettlementComposer::compose()` 算出的「教師倍率」因此對有行政職務的老師會少算最多 10 個百分點，總發放金額會偏低。 |
| 建議做法 | 比照 `teacher_payroll_deductions` 的雙階段核准模式（主任確認＋總部審核），新增 `teacher_payroll_admin_allowances`（或擴充 `teacher_payroll_events`/`achievements` 其中一張既有表，需先評估哪個 shape 較合適），並在 `TeacherEligibilityPolicy::evaluate()` 補上第七個 component，`FulltimeSettlementComposer` 一併把它的 rate 疊加進教師倍率。 |
| 清償成本估計 | 中（一張新表或擴充既有表 + policy 新 component + input/approve 端點 + 前端輸入面板一個新分頁，估半天～一天） |
| 不做的代價 | 有行政職務(行政協助/總導師/副主任)的正職老師，總發放金額會系統性偏低最多 10%，需要主任手動加扣款彌補，容易被忽略或算錯 |

### TD-078：正職老師底薪設定（`storeSalaryProfile`）沒有雙階段審核，director 單方就能直接改變總發放金額

| 欄位 | 內容 |
|---|---|
| 狀態 | Open |
| 優先級 | P2 |
| 發現日期 | 2026-08-14 |
| 發現來源 | 自動安全審查（push/commit security review）在 `TeacherEligibilityInputController.php` 點出 separation-of-duties 缺口，經跟使用者確認後記錄為技術債，暫不在這次 PR（#1773）處理。 |
| 影響模組 | `backend/app/Http/Controllers/TeacherEligibilityInputController.php`（`storeSalaryProfile()`）、`fulltime_salary_profiles` 表 |
| 描述 | `teacher_payroll_deductions` 有「主任確認（`director_confirmed_by`）→ 總部核准（`hq_approved_by`，限 `super_admin`）」兩階段才生效；`storeSalaryProfile()` 卻是任何 `role:director` 帶 PIN 就能單方直接寫入並立即生效（`TeacherEligibilityController::index()` 馬上採用最新一筆算總發放金額），沒有第二人核准，也沒有留下「誰改了底薪、改前改後多少」的變更歷史（只有一筆 `created_by`，沒有審核鏈）。底薪直接乘進總發放金額，出錯或被濫用的影響比扣款更大。 |
| 建議做法 | 比照 `teacher_payroll_deductions` 加兩階段：`fulltime_salary_profiles` 新增 `status`（`pending`/`approved`）＋ `approved_by`/`approved_at`，`storeSalaryProfile()` 先寫 `pending`，`salaryProfilesByTeacher()` 只採用 `approved` 的最新一筆；新增一個 `approveSalaryProfile` 端點限 `super_admin`。 |
| 清償成本估計 | 小～中（一個 migration 加欄位 + controller 加一個 approve 端點 + 前端加一個待審核狀態顯示，估半天） |
| 不做的代價 | 單一 director 帳號（或被盜用的 PIN）可以無審核地改變任何老師的總發放金額，且改動沒有留痕，事後難以稽核或還原 |

### TD-079：正職老師底薪 `effective_from` 沒有限制，可回溯覆蓋已結算/已發放月份的總發放金額

| 欄位 | 內容 |
|---|---|
| 狀態 | Open |
| 優先級 | P2 |
| 發現日期 | 2026-08-14 |
| 發現來源 | 自動安全審查（push/commit security review）在 `TeacherEligibilityInputController.php` 點出 retroactive-write-past-period 缺口，經跟使用者確認後記錄為技術債，暫不在這次 PR（#1773）處理。 |
| 影響模組 | `backend/app/Http/Controllers/TeacherEligibilityInputController.php`（`storeSalaryProfile()` 的 `effective_from` 驗證）、`backend/app/Http/Controllers/TeacherEligibilityController.php`（`salaryProfilesByTeacher()` 依 `effective_from <= 查詢期間結束日` 取最新一筆） |
| 描述 | `effective_from` 目前只驗證 `required, date`，沒有下限。系統沒有「已結算/已發放月份鎖定」的概念（不像 G-011 的 `BillingContractLockGuard`），所以任何時間點都能新增一筆較早的 `effective_from`，讓已經對外呈現、甚至已經實際發放的月份重新算出不同的總發放金額，且沒有鎖定機制也沒有留痕，容易被用來悄悄修改歷史紀錄（不論是惡意還是操作失誤）。 |
| 建議做法 | 需要先決定政策（例如：只允許補登「從未設過底薪」的老師的歷史起薪、或允許但要求連動核准與變更日誌），政策確定後在 `storeSalaryProfile()` 加對應的日期下限驗證，並讓 `fulltime_salary_profiles` 的每筆寫入都留下「異動前/異動後」快照，供稽核用。 |
| 清償成本估計 | 中（政策確認 + 驗證規則 + 稽核欄位/表，估半天～一天，視政策複雜度） |
| 不做的代價 | 已對外呈現或已發放月份的總發放金額可以被悄悄改動，沒有稽核軌跡，出現爭議時無法還原「當時算出來的金額」 |

### TD-076：`schedules` 表用「不可變紀錄鏈」表達改期，二次改期時容易讓已取代的紀錄殘留（連續兩起 production 事故，2026-08-08）

| 欄位 | 內容 |
|---|---|
| 狀態 | Open |
| 優先級 | P2 |
| 發現日期 | 2026-08-08 |
| 發現來源 | 同一晚連續兩起主任回報（木柵吳艾潼 SC#2688、木柵陳宥翰 SC#1249／in-app #225），根因都是同一種資料模型缺陷；下游已各自修好前端 dedupe 規則（見 `AI_REGRESSION_LESSONS.md` R102），但架構本身沒動。 |
| 影響模組 | `backend/app/Http/Controllers/ScheduleController.php`（`store()` 的改期建立/防重複邏輯）、`schedules` 資料表、`frontend/src/lib/calendarExceptionMerge.js`（下游 dedupe） |
| 描述 | `schedules` 表把「這一堂被改期了」表達成一條**不可變紀錄鏈**：每次改期都新增一筆 `status=rescheduled`（標記舊時段作廢）+ 一筆 `status=scheduled`（新時段），用 `original_schedule_id` 串起來。這個模型的問題是：讀者（不管是後端刪除邏輯、前端渲染邏輯，或未來任何新功能）每次都要**正確走訪整條鏈**才能算出「這一堂現在到底在哪」，只要鏈很長、或改期又改到同一個時間、或改期目的地換了日期，任何一處走訪邏輯少考慮一種情況，就會讓已經作廢的舊紀錄看起來仍然有效。這不是實作疏漏，是資料模型本身的形狀在鼓勵這類 bug。 |
| 大廠對標 | RFC 5545（iCalendar）用 `RECURRENCE-ID`（原始時間，永不變）當身分鍵，覆寫同一個 occurrence 是**更新同一筆**，不是疊加；Google Calendar API 的 `recurringEventId`+`originalStartTime` 同款設計，PATCH 同一個 instance 資源。Cal.com（本專案 star repo 名單裡的排程參考）用的是跟 AllTrue 一樣的鏈模型，且[已知在 production 出過同一類 bug](https://github.com/calcom/cal.com/issues/12922)——這不是 AllTrue 特有的失誤，是鏈模型這個選擇本身容易在二次改期時出錯的獨立佐證。詳見 `AI_REGRESSION_LESSONS.md` R102。 |
| 建議做法 | 把 `schedules` 的身分從「最新一筆的 id」改成「`student_course_id` + 原始 `schedule_date`/`start_time`（第一次物化後永久不變）」，每個身分對應**最多一筆**目前有效紀錄；改期是 **UPDATE** 這筆紀錄的 `schedule_date`/`start_time`（`status` 維持 `scheduled`），不是新增 rescheduled/scheduled 紀錄對。歷史移到獨立的 append-only `schedule_change_log`，比照 `bug_report_status_logs` 已經跟 `bug_reports` 目前狀態分離的既有模式。這是資料表結構＋所有讀寫路徑的改動，需要完整遷移計畫（雙寫期、回填既有鏈資料、golden 測試鎖住既有行為），不是一次 PR 能做完。 |
| 清償成本估計 | 大（資料模型遷移 + 所有讀寫路徑改寫 + 回填腳本，估需完整 RFC/PRD 規劃後才能估工） |
| 不做的代價 | 每次「改期的改期」場景出現新的變化（同時間重新提交、跨日期改期、未來可能的批次改期等），下游都要再補一次 dedupe patch——已經發生兩次，且 Cal.com 的先例顯示這個模式在其他成熟產品也會反覆冒出同類 bug，不是修一次就永久免疫 |
