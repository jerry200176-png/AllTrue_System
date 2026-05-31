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

*最後更新：2026-04-27（chore/ops-hardening-2026-04 — TD-015 登記）*

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
| 描述 | Composer audit 顯示 `laravel/framework` 8.x-dev 存在 file validation bypass（MEDIUM）；修補版本在 Laravel 10.48.29+ / 11.44.1+ / 12.1.1+，Laravel 8 無低風險 patch path |
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
| 狀態 | Open |
| 優先級 | P2 |
| 發現日期 | 2026-05-31 |
| 發現來源 | [SEC] 家長回饋雙向回覆（System A）開發中順手審查 |
| 影響模組 | `ParentFeedbackController::{forTeacher,markReadByTeacher,reply,replies}`、`routes/api.php` |
| 描述 | System B 的 `parent-feedback/{for-teacher,read,reply,replies}` 原本在任何 `role`/`require_campus` 群組外（等同未強制認證），本次已收斂進 `role:teacher,director,super_admin`+`require_campus`，移除未認證暴露。但 `{id}/read`、`{id}/reply`、`{id}/replies` 仍只做群組層級 role+campus 檢查，未驗證該筆 feedback 是否屬於呼叫者的分校／老師（per-row ownership）。目前這四個端點前端 0 引用，實際風險低。|
| 建議做法 | 在 controller 內鏡像 System A `authorizeStaffFeedback` 的 per-row 檢查（teacher 限自己 teacher_id、director 限 feedback 的 campus_id ∈ auth_campus_ids）；或評估 System A/B 合併後直接下架 System B 未用路由。|
| 清償成本估計 | 低（< 2hr，含測試）|
| 不做的代價 | 若日後接上 System B 前端，跨校員工可能讀／回他校意見箱；與 System A 隔離標準不一致 |

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
| 狀態 | Open |
| 優先級 | P2 |
| 發現日期 | 2026-05-31 |
| 發現來源 | [SRE/ARCH] #546 / TD-018 清償時拆出 |
| 影響模組 | `ClassSessionController::index`（代課老師 `sub_sched` leftJoin）、`teacherTrust`（同款 subquery）|
| 描述 | 主查詢以 `sub_sched` leftJoin 解析代課老師，ON 條件含 per-row correlated subquery `sub_sched.id = (SELECT MAX(sub2.id) ...)`，且 `DATE(schedule_date)`、`SUBSTRING(start_time,1,5)` 包裹欄位使既有複合索引 `idx_sched_course_date_time_status` 無法命中。這是主查詢 1–3.5s 慢的主因（非 transform N+1，transform 已是純記憶體）。|
| 建議做法 | 將 correlated `MAX(sub2.id)` 改為「每 (student_course_id, schedule_date, start_time) 取最新代課 schedule」的單一 derived-table join（鏡像現有 `lr`/`si` 衍生表 join），保持 `substitute_teacher_id`/COALESCE 老師名稱與 `effective_status` 結果 byte-identical；評估改存正規化 `HH:MM` 以移除 `SUBSTRING()`。先以 Sentry full payload + golden-output 快照保護再下藥。|
| 清償成本估計 | 中（半天，含 golden 快照與 EXPLAIN 前後對比）|
| 不做的代價 | 行事曆／點名主查詢持續慢、SLO burn；代課解析邏輯複雜，貿然改易回歸（曾有 schedules.id=611 HH:MM:SS 遺留坑）|

### TD-059：共用課程包（PackageDeductionService）尚未支援部分時數扣堂（#613 後續）

| 欄位 | 內容 |
|---|---|
| 狀態 | Open |
| 優先級 | P3 |
| 發現日期 | 2026-05-31 |
| 發現來源 | [DEV] #613 A1 落地 |
| 影響模組 | `App\Services\PackageDeductionService`（共用池 ledger 鏡像）|
| 描述 | #613 讓單一 `StudentClass` 支援分鐘制部分扣堂，但共用課程包的池鏡像仍以 `delta=±1`（整堂）同步。若部分時數補課發生在**共用包**成員身上，池餘額與個別課的分鐘餘額會漂移。目前單人課程（多數情境）已正確。|
| 建議做法 | 將 `PackageDeductionService` 的池 ledger 改為分鐘感知（鏡像 `session_deduction_ledger.minutes`），或在包成員觸發部分扣堂時換算池分鐘。需配 golden 包測試（`CoursePackageTest`/`PackageE2EFlowTest`）。|
| 清償成本估計 | 中（半天）|
| 不做的代價 | 共用包 + 部分補課的罕見組合會使池餘額不準；多數單人課不受影響 |

### TD-060：`ClassSessionController::recalculateSessionCounters` 為死碼（無 caller）且非分鐘感知

| 欄位 | 內容 |
|---|---|
| 狀態 | Open |
| 優先級 | P3 |
| 發現日期 | 2026-05-31 |
| 發現來源 | [REVIEW] #613 調查 |
| 影響模組 | `ClassSessionController::recalculateSessionCounters`（private）|
| 描述 | 此方法以 count-based（completed+attended）重算 `RemainingSessions`，與權威引擎 `SessionDeductionService::recomputeCounters` 並存。調查確認目前**無任何 caller**（死碼），故不會覆寫 #613 的分鐘衍生值；但保留會誤導，且若日後被誤用會與分鐘制分歧。|
| 建議做法 | 移除該方法，或改為薄包裝委派 `SessionDeductionService::recomputeCounters`，統一單一扣堂權威路徑。|
| 清償成本估計 | 低（< 1hr）|
| 不做的代價 | 死碼誤導後續開發者；潛在被誤用導致與分鐘制不一致 |
