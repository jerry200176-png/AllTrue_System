---
owner: jerry (CEO)
status: Draft — investigation only, no code/schema changed
review_cycle: on next founder review
last_reviewed: 2026-07-30
---

# AllTrue 非標準課程時長調查報告

> **Status:** Investigation only. No production code, migration, or billing behavior was changed to produce this report. All claims below are cited to file:line; anything not directly read in code is explicitly labeled as a documentation claim or an open assumption.
> **Related:** `#613` A1（既有分鐘制地基）、`TD-059`、`docs/AI_REGRESSION_LESSONS.md §R59`、`docs/SYSTEM_TECH_GUIDE.md §5`、`docs/PRICING_CONTRACT.md`、`docs/ADR_006_prepaid_session_horizon_and_commitment.md`（不同問題，見 §8）

---

## 1. Executive conclusion

**AllTrue 已經有一半的答案，但只接到「補課」這條窄路上。**

1. 系統目前是**兩套模型並存**：
   - **舊制（主流，所有讀取路徑的預設）**：1 `ClassSession`／1 次點名 = 1 個整數「堂」，堂數欄位（`SessionCount`/`RemainingSessions`/`UsedSessions`）全部是 DB `integer`。
   - **新制（`#613 A1`，已 merge、有測試，但範圍極窄）**：`StudentClass.PurchasedMinutes`/`RemainingMinutes` + `session_deduction_ledger.minutes` 是一套**已經存在、可運作**的分鐘制權威餘額引擎，用整數安全的 `ROUND_HALF_UP` 把分鐘換算回「堂數顯示值」（可為 5.25 堂 → 顯示 5 堂）。
2. **但這套分鐘引擎只在「補課」（`schedules.type='extra'`）且時長 ≠ 契約標準時才會啟動**（`SessionDeductionService::resolvePartialMakeupMinutes()`，`backend/app/Services/SessionDeductionService.php:468-499`，強制規則寫在同檔案 466 行註解：「正常課堂一律整堂」）。這一點有測試直接證明：`PartialMakeupDeductionTest::test_normal_longer_session_not_prorated`（`backend/tests/Feature/PartialMakeupDeductionTest.php:125-142`）——用 180 分鐘、非 `type='extra'` 的**正常**堂次點名，斷言 `RemainingSessions` 仍然只扣 1 堂，不是 1.5 堂。**這就是本案例（案例 2）在現有程式碼下無法自動運作的直接證據**，不是猜測。
3. **更關鍵的根因**：契約「標準堂長」欄位 `StudentClass.SessionDuration`（分鐘引擎換算堂數用的分母）**本身在新增課程時，就是從使用者實際排的時段時長算出來的**——`EnrollmentService::store()` 把 `SessionDuration` 設為該科目所有時段中最長者（`$groupGlobalDur = max($groupGlobalDur, (int) ($rr['duration_minutes'] ?? 0))`，`backend/app/Services/EnrollmentService.php:621-626,710`），更新流程中 `StudentClassController::mapFrontendPayload()` 也會用第一個時段的 `duration_minutes` 覆寫 `SessionDuration`（`backend/app/Http/Controllers/StudentClassController.php:3542-3547`）。
   結果是：**現在的資料模型裡，「1 堂的標準分鐘數」跟「這個學生實際排的時段分鐘數」是同一個欄位**，沒有獨立表達空間。若主任照現有 UI 幫這名學生排「每週二、六各 180 分鐘」，系統會直接把 `SessionDuration` 設成 180，而不是維持 120 並把 180 分鐘記成 1.5 堂——也就是說，本案例要的「8 堂＝16 小時的標準額度，但每次上 3 小時＝1.5 堂」這個語意，**目前無法透過新增課程 UI 表達**，只能事後用「補課」機制繞出一次性的分鐘差額。
4. **第六次課的「跨期缺口」，現有系統目前完全偵測不到**——因為只要不是走補課路徑，扣堂永遠是「1 次點名 = 1 整堂」，系統根本不會知道「這堂其實該值 1.5 標準堂」，遑論偵測「這期只夠付 5 次半」。
5. **退款（refund）在程式碼裡完全不存在**——全庫搜尋 `Refund`/`退費`/`退款` 只有 2 個非退款用途的檔案命中，測試 0 筆；唯一相關機制是發票作廢（`voidInvoice`/`exceptionVoidInvoice`），且 `SessionDeductionService.php:393-397` 明確註解「Paid/PayDate must NOT be touched here. Session counting is independent of payment status」——金流與堂數餘額是完全獨立、互不影響的兩套系統。
6. **扣堂沒有單一 choke point**：權威計算邏輯集中在 `SessionDeductionService::recomputeCounters()`，但**呼叫它的入口至少有 9 處**（RFID 刷卡、手動點名、舊版刷卡端點、自習轉正式、評量核准、堂次狀態手動切換等），各自獨立判斷「這次算不算要扣堂」。這代表任何時長相關的改動，必須同時審視全部 9 個入口，否則會產生像 `TD-059`（共用課程包池未分鐘化，已有專案追蹤 `#1343`）這樣的分裂。
7. **好消息**：排課／materialization 層（`day_time_slots[].duration_minutes`、`StudentClass.duration1..duration6`、`ClassSessionMaterializationService`）早就支援「每個星期時段可以有不同時長」，且已被 UI／驗證／測試完整覆蓋（30 分鐘至 480 分鐘皆合法）。缺的不是「能不能排出 3 小時的課」，而是「排出來的 3 小時，能不能被記成 1.5 個標準堂」。這讓最小可行方案的範圍縮小到：**扣堂引擎 + 新增課程時對「標準堂長」與「排課時長」的解耦**，而不必動排課／行事曆本身。

**一句話結論**：AllTrue 目前的權威餘額是「堂數」（整數），`#613 A1` 已經打好分鐘制的地基，但只通到補課這個側門；本案例要的是把同一套引擎的正門也打開——這是漸進式擴充，不是重寫。

---

## 2. Current authoritative model

### 2.1 資料表與欄位（已由程式碼確認）

| 概念 | 表／欄位 | 型別 | 來源 |
|---|---|---|---|
| 購買堂數 | `StudentClass.SessionCount` | `integer`, nullable | `2026_02_07_000004_create_student_classes_table.php:51` |
| 剩餘堂數（顯示值） | `StudentClass.RemainingSessions` | `integer`, nullable | `2026_02_07_000015_add_remaining_sessions_to_student_class_table.php:15` |
| 已用堂數 | `StudentClass.UsedSessions` | `integer`, default 0 | `2026_02_13_000007_add_used_sessions_to_student_class_table.php:16` |
| 契約標準堂長（分鐘） | `StudentClass.SessionDuration` | `integer`, nullable | 同 create 表 line 52 |
| 每週各時段自訂時長 | `StudentClass.duration1..duration6` | `integer`, nullable ×6 | `2026_04_10_000001_add_per_day_duration_to_student_class.php` |
| 計費單位 | `StudentClass.rate_unit` | `string(16)` default `'session'` | 同上 migration |
| **購買總分鐘（權威，#613）** | `StudentClass.PurchasedMinutes` | `integer`, nullable | `2026_05_31_000001_add_minutes_balance_to_student_class.php:23` |
| **剩餘分鐘（權威，#613）** | `StudentClass.RemainingMinutes` | `integer`, nullable | 同上 line 26 |
| 扣堂事件帳本 | `session_deduction_ledger`（`event_type`, `minutes`, `source`, `class_session_id`） | `minutes` 為 `integer` nullable | `2026_05_31_000002_add_minutes_to_session_deduction_ledger.php` |
| 共用課程包堂數 | `CoursePackage.total_sessions/remaining_sessions/used_sessions` | `unsignedInteger`/`integer` | `CoursePackage.php` |
| 共用包帳本差量 | `package_session_ledger.delta` | **`tinyInteger`，硬編碼只允許 ±1** | migration `2026_04_15_300001_create_package_session_ledger.php`（見 §8 風險）|
| 單次課堂 | `ClassSession.StartTime/EndTime/Status/session_charge` | `time`/`time`/`string(16)`/`integer` | `2026_02_07_000009_create_class_sessions_table.php` + `2026_04_17_100000_add_session_charge_to_class_session.php` |

**`ClassSession` 沒有 `duration` 欄位，也沒有「本堂扣幾堂」欄位**——時長永遠是 `EndTime - StartTime` 現算，扣了幾堂則完全記在 `session_deduction_ledger`，`ClassSession` 本身跟扣堂邏輯無關（`SYSTEM_TECH_GUIDE.md §5.1`：「Session Deduction 與 ClassSession.Status 完全獨立」）。

**堂數欄位是否只能是整數？** 是——`SessionCount`/`RemainingSessions`/`UsedSessions`/`CoursePackage.total_sessions` 全部是 DB `integer`／`unsignedInteger`，寫入驗證也是 `integer` 規則（`StudentClassController.php:1203`：`'SessionDuration' => 'nullable|integer|min:30'`；`ClassSessionController.php` `batchStore` 驗證 `'total_classes' => 'nullable|integer|min:1|max:500'`）。目前唯一能表現「非整堂」語意的地方是**顯示層**——`RemainingSessions` 在有分鐘制事件時，是用整數安全的 `ROUND_HALF_UP` 從 `RemainingMinutes` 算出來的衍生值（可以是任何整數，但代表的實際分鐘可能不是整堂倍數），不是資料庫真的存了 `5.5`。

### 2.2 兩套並行的「堂數如何算出來」邏輯

**`SessionDeductionService::recomputeCounters()`**（`backend/app/Services/SessionDeductionService.php:301-408`）是唯一寫入 `RemainingSessions`/`UsedSessions`/`PurchasedMinutes`/`RemainingMinutes` 的地方，邏輯是「先看有沒有部分時數事件，再決定用哪套公式」：

```php
// SessionDeductionService.php:352-391
if ($isSessionMode && $sessionCount > 0) {
    $purchasedMinutes = $sessionCount * $perSession;
    $hasPartial = SessionDeductionLedger::query()
        ->where('student_class_id', $studentClassId)
        ->whereIn('source', ['attendance', 'retro_leave', 'status_adjust'])
        ->whereNotNull('minutes')
        ->where('minutes', '!=', $perSession)
        ->exists();

    if ($hasPartial) {
        // 分鐘為權威：RemainingSessions = ROUND_HALF_UP(RemainingMinutes / perSession)
        $remainingSessions = max(0, min($sessionCount, self::roundHalfUp($remainingMinutes, $perSession)));
        $sc->RemainingSessions = $remainingSessions;
        $sc->UsedSessions      = $sessionCount - $remainingSessions;
    } else {
        // 完全沿用舊 count-based 邏輯（byte-identical），僅補寫衍生分鐘欄
        $sc->UsedSessions      = min($sessionCount, $usedByAttendance);
        $sc->RemainingSessions = max(0, $sessionCount - $usedByAttendance);
    }
}
```

而「這堂到底算不算部分時數」由 `resolvePartialMakeupMinutes()` 決定（`SessionDeductionService.php:463-499`）：

```php
// 464-466 原文註解：
// #613 A1 + 補課加長：補課（schedules.type='extra'）時長 ≠ 契約每堂分鐘時，
// 回傳實際分鐘（可短於或長於 perSession）。非補課、剛好完整時長、或時間不足
// → null（＝整堂）。正常課堂一律整堂。禁止 clamp 回 perSession。
private static function resolvePartialMakeupMinutes(StudentClass $sc, int $classSessionId): ?int
{
    ...
    $isMakeup = Schedule::query()
        ->where('student_course_id', (int) $sc->getKey())
        ->whereDate('schedule_date', $cs->SessionDate)
        ->where('type', 'extra')
        ->get(['start_time'])
        ->contains(fn ($r) => substr((string) $r->start_time, 0, 5) === $csStart);
    if (!$isMakeup) {
        return null;   // ← 一般排定的堂次，即使時長不同，也回 null
    }
    ...
}
```

**這是本案例的核心事實**：`isMakeup` 只認 `schedules` 表裡 `type='extra'` 的列。一般透過「新增課程」建立的固定週期堂次，其對應的 `ClassSession` 並不會有一列 `type='extra'` 的 `schedules` 紀錄——它們是直接由 `EnrollmentService`/`ClassSessionMaterializationService` 產生的常態排課，因此 `isMakeup` 恆為 `false`，`resolvePartialMakeupMinutes()` 恆回傳 `null`，扣堂恆為整堂。

### 2.3 標準堂長從何而來（`perSessionMinutes()`）

```php
// StudentClass.php:120-126
public const DEFAULT_SESSION_MINUTES = 60;
public function perSessionMinutes(): int
{
    $dur = (int) ($this->SessionDuration ?? 0);
    return $dur >= 1 ? $dur : self::DEFAULT_SESSION_MINUTES;
}
```

- 引擎讀的是 `SessionDuration`（單一數字），fallback 是 **60 分鐘**（不是 2 小時）。
- 但在「新增課程」的實際寫入路徑，`SessionDuration` **不是**一個獨立輸入的「契約標準堂長」，而是取自實際排課時段的最長時長（見 §3、§7）。多數控制器讀取端另外用 **120 分鐘**當 fallback（`ScheduleGuardService.php:293`、`SessionProjectionReadService.php:232/234/244/246`、`StudentClassController.php` 多處 `?? 120`）——**60（模型層）與 120（多數控制器層）是兩個不一致的 hardcoded fallback**，本身就是一個既有的技術債，只是目前尚未造成生產事故（因為多數課程建立時 `SessionDuration` 都會被明確寫入）。

### 2.4 已知限制（自己文件承認的）

- `TD-059`（`docs/TECH_DEBT.md:440-455`）：共用課程包（`PackageDeductionService`）尚未分鐘感知，`package_session_ledger.delta` 仍是整堂 ±1；若部分時數事件發生在共用包成員身上，池餘額會與個別課程的分鐘餘額**漂移**。目前命中數＝0（2026-07-19 稽核），但這是「尚未發作的地雷」，不是「已解決」。
- `docs/AI_REGRESSION_LESSONS.md §R59`：明文寫「禁止擴大到非 `extra`」——這代表 `#613 A1` 的原始設計者**刻意**把分鐘制限制在補課場景，不是遺漏，而是當時範圍內的產品決策（原因未在程式碼或文件中說明，需 Founder 補充，見 §14）。

---

## 3. 新增課程完整資料流

**元件**：`frontend/src/components/UniversalClassScheduler.vue`（唯一的新增課程精靈，`CourseManagement.vue`/`SmartCalendar.vue`/`StudentsList.vue` 都從這裡開啟）。

1. **UI 輸入**（`UniversalClassScheduler.vue`）：
   - 購買總堂數 `form.total_classes`（純數字輸入框 `min="1"`，僅 `payment_type='session'` 時顯示，第 411-414 行）。
   - 每堂費用 `form.price_per_session`。
   - 預設上課時長（小時）`form.duration_hours`（`type=number min=0.5 step=0.5`，第 464-468 行）——這是**全域預設值**，非每個時段強制值。
   - 每個選定星期的時段列 `form.day_time_slots[]`，每一列**各自獨立**有：星期、開始時間、**時長**（`duration_hours`，可與全域預設不同，`updateSlotDur()` 第 1787-1795 行）、可選代課老師。
   - 前端即時預覽（第 613-695 行）：`estimateCreateCharge({ pricePerSession, rateUnit, sessions, avgSessionMinutes })`（第 1307-1312 行），`avgSessionMinutes` 是實際各時段時長的平均值，**不是固定 2 小時**。
   - 前端送出前的合理性檢查（`submit()` 第 2047-2059 行）：拒絕任何時段 < 30 分鐘或 > 480 分鐘（8 小時），**沒有「必須等於 120 分鐘」的檢查**。
   - `hasPerDayDuration`（第 1281-1285 行）：偵測「各時段時長彼此不同」時，自動把 `rate_unit` 切成 `'hour'`（按時計費），**不是**切成「部分堂數」。

2. **Payload**（`frontend/src/lib/universalSchedulerApi.js:22-63`）：`POST /api/v1/class-sessions/batch`，內含 `day_time_slots: [{ day, start_time, duration_minutes, subject, teacher_id? }]`、`total_classes`、`duration_minutes`（全域 fallback）、`rate_unit`。

3. **後端驗證**（`ClassSessionController::batchStore`，`backend/app/Http/Controllers/ClassSessionController.php:40-88`，inline `$request->validate()`，無獨立 Form Request）：
   ```php
   'total_classes' => 'nullable|integer|min:1|max:500',
   'day_time_slots.*.duration_minutes' => 'nullable|integer|min:30|max:480',
   'duration_minutes' => 'required|integer|min:30|max:480',
   'rate_unit' => 'nullable|in:session,hour',
   ```
   → 交給 `EnrollmentService::store()`。

4. **`EnrollmentService::store()`**（`backend/app/Services/EnrollmentService.php`）：
   - 依科目分組（一次可能同時建立多科目），第 621-626 行：
     ```php
     $groupGlobalDur = 0;
     foreach (...) { $groupGlobalDur = max($groupGlobalDur, (int) ($rr['duration_minutes'] ?? 0)); }
     if ($groupGlobalDur <= 0) { $groupGlobalDur = $globalDuration; }
     ```
   - 第 692-724 行：`$studentClassPayload` 寫入 `'SessionDuration' => $groupGlobalDur`（**該科目所有時段中最長者**），並把 `week1..week6`/`time1..time6`/`duration1..duration6` 一併寫入 `StudentClass`。
   - `StudentClass::create($studentClassPayload)`。
   - 對每個展開後的實際堂次（依日期+時段），用**該列自己的** `duration_minutes` 算 `EndTime`，呼叫 `ClassSessionMaterializationService::upsertSlot()` 寫入 `ClassSession`。

5. **`StudentClassController::update()`（PUT，既有課程編輯）**：`mapFrontendPayload()`（第 3440-3568 行）——第 3546 行：`$mappedData['SessionDuration'] = (int) $primary['duration_minutes'];`（用**第一個** `day_time_slots` 的時長覆寫契約標準堂長）；第 3556-3557 行對其餘時段寫入對應的 `duration{n}`。

**結論（回答 quality gate 問題 2）**：`SessionCount`（購買堂數）與 `SessionDuration`（契約標準堂長）都是**使用者直接輸入或由排課時段推導**，沒有「上課開始/結束時間」與「標準堂長」的獨立輸入通道——排課時段的時長，會直接變成契約的標準堂長。目前系統**沒有**「總時數預覽 + 跨期缺口警示」的 UI（有「總堂數/總小時」預覽，但沒有「本期涵蓋到第幾次課、第幾次會超出」的預覽——見 §7、§10 UX 建議）。目前也**沒有**堂數必須為整數的額外校驗訊息（因為欄位本身是 `integer`，非整數輸入會被 Laravel 驗證直接拒絕為 422，而不是有意義的產品提示）。

---

## 4. 排課與 ClassSession materialization 流程

- **常態排課（新增課程當下）**：`EnrollmentService::store()` 直接展開日期，呼叫 `ClassSessionMaterializationService::upsertSlot()`（`backend/app/Services/ClassSessionMaterializationService.php:82-149`）寫入 `ClassSession`，`StartTime`/`EndTime` 完全取自呼叫端傳入的值，**沒有** 2 小時的預設，唯一 fallback 是 `EndTime` 完全缺失時用 `'18:00:00'`（第 133 行，僅防禦性，不是業務規則）。
- **補排（手動）**：`schedules:backfill-class-sessions`（`BackfillMissingClassSessionsFromSchedules.php`）直接複製 `schedules` 表的 `start_time`/`end_time`，同樣不假設固定時長。**此指令未排入 `Kernel.php` 排程**（需人工執行），對應 CLAUDE.md G-010 的已知缺口。
- **向前生成**：`ForwardSessionGenerator::planCourse()`（`backend/app/Services/ForwardSessionGenerator.php:36-151`）從該學生近 6 堂**實際** `ClassSession` 多數決推算星期/時段/時長，同樣不寫死時長。此機制與本案例的計費單位問題無直接關聯，屬於 `ADR_006`（预付堂次 horizon）範疇——**兩個問題不要混淆**：`ADR_006` 談「該幫這個學生生成哪些未來 `ClassSession`」，本報告談「生成出來的這一堂，該扣幾堂」。
- **`ClassSessionMaterializationService::upsertSlot()`** 是**唯一** production 寫入 `ClassSession` 的權威路徑（`ADR_006` §5.1 明文要求維持此唯一性），一致性鍵為 `(StudentClassID, SessionDate, StartTime)`。

**結論**：排課層完全支援「同一課程、不同星期不同時長」（`duration1..duration6`、`day_time_slots[].duration_minutes`），這部分不需要重建；缺口在計費／扣堂層，不在排課層。

---

## 5. 點名與扣堂 authoritative write path

**單一計算權威**：`SessionDeductionService::recomputeCounters()`（見 §2.2），但**至少 9 個獨立呼叫點**觸發它（皆已逐一在程式碼中確認，非變數命名推測）：

| # | 呼叫點 | 情境 |
|---|---|---|
| 1 | `SwipeRfidController.php:254-257` | RFID 刷卡點名 |
| 2 | `SwipeRfidController.php:446` | 刷卡簽退回填 |
| 3 | `AttendanceController.php:605-608` | 手動點名 `store()` |
| 4 | `AttendanceController.php:793-799` | 舊版 `swipe()` 端點 |
| 5 | `AttendanceController.php:1169`（`convertToAttended`） | 自習紀錄轉正式出席 |
| 6 | `ApprovalSessionSyncService.php:81` | 評量核准自動建立出席 |
| 7 | `EnrollmentService.php:1587-1594` | 無綁定堂次的已核准評量 |
| 8 | `ClassSessionController.php:908-909` | 主任手動切堂次狀態為 attended/late |
| 9 | `ClassSessionController.php:1699` 附近 | 另一狀態轉換路徑 |

**還原（reverse）** 獨立呼叫點至少 6 處：請假回滾（`ScheduleController.php:729-736`）、調課（`RescheduleSessionService.php:546-555`）、狀態轉換撤銷（`ClassSessionController.php:931,1020`）、評量撤銷（`ApprovalSessionSyncService.php:110`）等。

**寫入機制**：`deductForSession()`（`SessionDeductionService.php:196-232`）寫一列 `session_deduction_ledger`（`event_type='deduct'`，`minutes` 可為 `null`＝整堂），**不是** `UPDATE ... SET RemainingSessions = RemainingSessions - 1`（全庫搜尋確認沒有這種寫法）。`recomputeCounters()` 之後才把彙總結果 `save()` 回 `StudentClass`。

**`usedByAttendance` 的計算本身是 4 個訊號取 max**（`SessionDeductionService.php:309-346`）：`StudentSignIn.SessionDeducted`、`ClassSession.Status IN (completed/attended/late)`、無綁定 `ClassSessionID` 的已核准 `LearningRecord`、`session_deduction_ledger` 淨值。這代表帳本本身**不是**唯一真相來源，而是四個訊號之一——`NightlyReconcile` 指令的存在，證明這四路訊號漂移是已知風險，非理論疑慮。

**餘額歸零／負餘額行為**（回答 quality gate 問題 6 的一半）：
- `RemainingSessions` 在計算式中被 `max(0, ...)` 夾住，**不會顯示負數**，但 `UsedSessions` 若真實出席超過 `SessionCount`，只在**顯示**時被 `min($sessionCount, ...)` 封頂——實際出席仍會被記錄，只是不會讓 `RemainingSessions` 變負。
- 唯一會**擋下**動作的硬性檢查：`AttendanceController.php:1124-1126`（`convertToAttended`）：`剩餘堂數 <= 0` 時回 422「此課程剩餘堂數不足」——**僅此一個端點**。
- **一般點名（RFID／手動）與新增堂次都沒有餘額檢查**——刷卡點名時即使 `RemainingSessions` 已是 0，仍會成功扣堂（產生 `UsedSessions > SessionCount` 的隱性超額，靠 dashboard 提醒與 `NightlyReconcile` 事後發現，不是事前攔阻）。

**請假／補課／調課／退款／終止對餘額的影響**（皆已在程式碼中逐一確認）：

| 動作 | 對餘額影響 |
|---|---|
| 請假（一般，未來） | `ClassSession.Status → 'leave'`，`leave` 不在 `AttendanceStatus::deductibleCodes()` 內，從未寫入 `deduct`；同時在最後補一堂（tail），總堂數守恆 |
| 補請假（已扣堂後才請假） | 明確呼叫 `reverseForSession(..., 'retro_leave', ...)` |
| 補課建立/取消 | 只動 `Schedule.status`/`ClassSession.Status`，**不動餘額**；餘額只在補課「真的點名」時才透過同一條 `deductOnAttendance` 路徑扣（此時才可能觸發 §2 的分鐘制） |
| 調課（reschedule） | 若原堂次已扣堂，先 `reverseForSession()` 再重設為 `scheduled` |
| 退款 | **程式碼中不存在**。`BillingController::voidInvoice()`/`exceptionVoidInvoice()` 只動 `Invoice`/`Payment`，從未觸碰 `StudentClass.RemainingSessions`/`SessionCount`/`session_deduction_ledger`（`SessionDeductionService.php:393-397` 明確註解金流與堂數獨立） |
| 課程終止（`togglePause`） | 取消未來 `scheduled` 堂次（本來就沒扣過堂），**不重算、不歸零** `RemainingSessions` |

---

## 6. 現有整數／固定兩小時假設

**已由程式碼直接確認的假設／不一致**：

1. **堂數欄位型別為整數**：`SessionCount`/`RemainingSessions`/`UsedSessions`/`CoursePackage.total_sessions`/`used_sessions` 全部 `integer`/`unsignedInteger`（§2.1）。共用包帳本 `package_session_ledger.delta` 更嚴格，是 `tinyInteger` 且只允許 ±1，**結構上不可能**記錄 1.5 或任何非整堂差量。
2. **兩個互相衝突的「預設堂長」常數**：模型層 `StudentClass::DEFAULT_SESSION_MINUTES = 60`（`StudentClass.php:120`）vs. 至少 4 處控制器/服務層的 `?? 120`（`ScheduleGuardService.php:293`、`SessionProjectionReadService.php:232/234/244/246`、`ExceptionWorkflowCandidateGenerator.php:147`、`StudentClassController.php` 多處）。兩者從未被統一過，只是目前多數課程建立時 `SessionDuration` 都會被明確寫入，才沒有在生產環境顯現差異。
3. **「1 lesson = 2 hours」不是寫死的常數，而是「新增課程 UI 的預設輸入值」**（`form.duration_hours` 預設 2，多處 `?? 2`/`|| 2`），使用者可自由改成 0.5–8 小時之間任意值，**每個星期時段可各自不同**（`duration1..duration6`，per-day override，已完整支援）。
4. **`SessionDuration`（契約標準堂長）在建立/編輯時永遠等於實際排課時長**（`EnrollmentService.php:621-626,710`；`StudentClassController.php:3546`），沒有獨立於「排課時長」之外的「計費標準堂長」欄位可填——這是本報告認定的**核心資料模型缺口**，不是驗證規則的缺口。
5. **一份文件承認的資料錯位**（G-009）：`StudentClassController::update()` 的 `preservedDelta`（`StudentClassController.php:1523,1532`）會把「舊 Charge − 舊 Rate×舊堂數」的差額當手動微調永久保留，若差額源自錯誤舊資料，UI 改不回。此問題與本案例的計費單位無直接關聯，但同樣位於 `Rate × SessionCount` 這條計費公式上，實作 Option A/B 時必須一併考慮（見 §12 相容性）。
6. **測試層面的偏誤**：`SessionDuration => 120` 出現在 103 個測試檔（vs. `=> 60` 只有 30 個），`createStudentClassForTest()` 共用 helper 硬編碼 `'duration_hours' => 2`——代表既有測試套件對「非 2 小時」課程的覆蓋率遠低於「2 小時」課程，任何只在非標準時長才會出現的 bug，很可能對現有 103 個測試都不可見。

---

## 7. 8 堂 × 2 小時、每次上課 3 小時的實際結果

以下四個案例，皆以程式碼與測試直接推演，非猜測；每個案例會標明「若照現有『新增課程』UI 直接操作」與「若刻意繞道走補課機制」兩種路徑的差異。

### Case 1：標準課程（8 堂 × 120 分鐘）

- `SessionCount=8`，`SessionDuration=120`。8 次點名，每次 `deductOnAttendance` → `resolvePartialMakeupMinutes` 回 `null`（非補課）→ `deductForSession(minutes=null)`。
- `recomputeCounters()`：無 `minutes != perSession` 的 ledger 列 → `hasPartial=false` → 走舊制：`UsedSessions` 逐次 +1，`RemainingSessions = 8 - UsedSessions`。
- 8 次後：`UsedSessions=8`，`RemainingSessions=0`，`PurchasedMinutes=960`（衍生欄，同步補寫），`RemainingMinutes=0`。**與預期完全一致。**

### Case 2：本案例（8 堂、契約標準 120 分鐘、每次實際 180 分鐘）

**路徑 A——照現有「新增課程」UI 直接操作（最可能發生的真實操作）**：
- 主任在 `day_time_slots` 填「每週二、六，各 180 分鐘」，`total_classes=8`。
- `EnrollmentService::store()` 第 621-626/710 行：`groupGlobalDur = max(180, 180) = 180` → `StudentClass.SessionDuration = 180`（**不是** 120）。
- 之後 `perSessionMinutes() = 180`，`PurchasedMinutes = 8 × 180 = 1440`（24 小時，**不是** Founder 預期的 16 小時）。
- 8 次點名皆非補課 → 每次整堂扣 1 → 8 次後 `RemainingSessions=0`。**系統從未偵測到「180≠120」這件事，因為契約標準本身已經被排課時長覆寫成 180。** 若 `Rate` 是照 2 小時單堂定價設定的，公司會用 2 小時的單價，換到 3 小時的教學時數，等於每堂多送 1 小時、8 堂共送 8 小時教學時數而未加價——**這是實質的營收缺口，且系統不會有任何告警**（因為堂數帳目本身「收支相符」：8 堂賣、8 堂用完）。

**路徑 B——刻意繞道走補課機制（理論上可行，但違反 R59 的設計原意，且無法用於常態排課）**：
- 若把 `SessionDuration` 手動維持在 120（例如透過 API 直接寫入，繞過 UI 的自動覆寫），且把每一次 180 分鐘的課都在 `schedules` 表建立對應 `type='extra'` 的列（如 `PartialMakeupDeductionTest::test_longer_makeup_attendance_deducts_actual_minutes` 所驗證），則**每次點名確實會扣 180 分鐘**，`recomputeCounters()` 會在偵測到 `hasPartial=true` 後切換到分鐘權威：
  - 第 1 次後：`RemainingMinutes = 960-180=780`，`RemainingSessions = ROUND_HALF_UP(780/120) = ROUND_HALF_UP(6.5) = 7`（**注意**：ROUND_HALF_UP 是「四捨五入到最近整堂」用於**顯示**，不是題目要的「6.5 堂」精確值；精確值要看 `remaining_minutes=780`，即 6.5 堂）。
  - 依此類推到第 5 次後：`RemainingMinutes=960-900=60`，精確值 0.5 堂，`RemainingSessions` 顯示 `ROUND_HALF_UP(60/120)=ROUND_HALF_UP(0.5)=1`（顯示值捨入為 1，但精確 `remaining_minutes=60` 才是可信數字——`StudentClassController.php:399-410` 的 `hasFractionalBalance` 守門邏輯正是為了避免這種顯示值被 count-based 邏輯覆寫）。
  - 第 6 次需要 180 分鐘，但只剩 60 分鐘可用：**目前程式碼沒有任何地方會攔下這次點名或標記「超額」**。`deductForSession()` 只做 ledger idempotency（防重複扣同一堂），不檢查夠不夠扣；`recomputeCounters()` 的 `usedMinutes = max(0, min($purchasedMinutes, $netMinutes))`（第 376 行）把已用分鐘封頂在購買總額，`remainingMinutes` 因此**下限鎖在 0，不會出現負數**，但這代表「超扣的 120 分鐘缺口」在資料庫裡直接消失、無跡可尋——沒有「本期已透支 120 分鐘」的紀錄。**這對應到題目列出的六種可能行為之一：「餘額歸零」，且沒有任何自動使用下一期額度、也沒有阻止點名、也沒有任何告警。**
  - 此路徑目前**沒有測試覆蓋**「連續扣款超出購買總額、且發生在同一期」的情境（`SessionDeductionMinutesEngineTest::test_partial_minutes_capped_at_purchased` 只驗證單次超扣 200 分（購買 120）會封頂在 0，沒有驗證「先扣 5 次半、第 6 次再超扣」的多堂連續案例，但由程式碼邏輯可合理外推行為一致）。

**兩條路徑的落差本身就是本報告最重要的產品發現**：系統理論上有能力做出題目要的「6.5 堂→5 堂→…→0.5 堂」序列，但**只有故意繞道補課機制**才會觸發；正常操作（路徑 A）反而會靜默地把「3 小時」變成這門課自己的新標準堂長，讓「180≠120」這個訊號永遠不會出現。

### Case 3：90 分鐘課程（0.75 標準堂）

- 若走補課路徑（`PartialMakeupDeductionTest::test_partial_makeup_attendance_deducts_prorated_minutes`，90 分鐘 vs 120 分鐘契約）：扣 90 分鐘，`RemainingMinutes = 480-90=390`，`RemainingSessions` 顯示 `ROUND_HALF_UP(390/120)=ROUND_HALF_UP(3.25)=3`。**證實系統的 ROUND_HALF_UP 換算不是為 3 小時（1.5 倍）特化寫死的，而是通用的 `minutes/perSession` 整數運算**（`intdiv($minutes*2+$perSession, $perSession*2)`），任何比例都適用。
- 若走一般（非補課）排課：`test_normal_short_session_not_prorated` 證實 30 分鐘的正常堂次一樣整堂扣 1——0.75 標準堂的語意同樣只在補課路徑上成立。

### Case 4：同一課程不同時長（週二 180 分鐘、週六 120 分鐘）

- **資料模型層可以表達**：`StudentClass.duration2`（週二）＝180、`duration6`... 更精確地說 `week*/duration*` 依 ISO 星期對應（見 `StudentClass::resolveSessionDurationForWeekday()`，`StudentClass.php:97-113`），`ClassSession` 各自的 `StartTime`/`EndTime` 也會忠實反映 180／120。
- 但 `resolveSessionDurationForWeekday()` 目前**唯二**的呼叫端是 `FinanceController.php:524` 與 `ClassSessionController.php:1220`，皆用於「單堂改時段時的費率換算」（對應 `docs/AI_REGRESSION_LESSONS.md §R76`），**完全沒有被 `SessionDeductionService` 引用**——扣堂引擎只認 `perSessionMinutes()`（單一 `SessionDuration`），不認每週各異的 `duration1..6`。也就是說：**扣堂目前綁定在「課程層級」的單一 `SessionDuration`，不是「週期排課層級」的 per-weekday duration，更不是「單一 ClassSession 層級」**——即使資料表已經有能力紀錄週二 180、週六 120，扣堂仍然只會用（建課當下取最大值算出的）同一個 `SessionDuration` 去除，導致週六 120 分鐘的課也會被當成「180 分鐘的 1 堂」處理，而非額外去區分「這天只值 1 堂、那天值 1.5 堂」。
- 且因為兩個時段時長不同，`hasPerDayDuration` 會在前端把 `rate_unit` 切成 `'hour'`——即整個課程改成「按小時計費」，**完全繞開「堂數」概念**，Founder 若仍希望維持堂數制的直覺（家長看得懂「還剩幾堂」），這個既有自動切換行為需要重新評估（見 §14）。

---

## 8. 受影響功能與風險

| 功能 | 目前是否受「標準堂長 vs 排課時長」耦合影響 | 證據 |
|---|---|---|
| 新增課程 | 是——`SessionDuration` 在建立當下即被排課時長覆寫，無法獨立設定 | `EnrollmentService.php:621-626,710` |
| 修改課程 | 是——`mapFrontendPayload()` 用第一個時段覆寫 `SessionDuration` | `StudentClassController.php:3542-3547` |
| Recurring schedule | 排課層已支援 per-weekday 時長，但扣堂引擎不讀取 | `StudentClass::resolveSessionDurationForWeekday()` 只用於計費、不用於扣堂 |
| ClassSession materialization | 不受影響（本身不假設固定時長） | `ClassSessionMaterializationService.php:82-149` |
| 點名 | 受影響——只有補課路徑會傳真實分鐘 | `resolvePartialMakeupMinutes()` |
| 扣堂 | 核心受影響（本報告主題） | `SessionDeductionService.php` |
| 請假 | 不受影響（leave 從不寫 deduct） | `AttendanceStatus::deductibleCodes()` |
| 補課 | **是現有唯一可行路徑**，但只對單次 makeup 生效，不能用於常態週期 | `PartialMakeupDeductionTest.php` |
| 調課 | 間接受影響——調課會 reverse 再重扣，若原本是分鐘制事件需確認 reverse 沖回同一 `minutes`（已有機制，見 `reverseForSession()` 的 matched-minutes 邏輯） | `SessionDeductionService.php:255-265` |
| 課程終止 | 不受影響（終止不重算餘額） | `togglePause()` |
| 退款 | **不存在**，不受影響也無法受益 | 全庫搜尋 0 命中 |
| 餘額顯示（`StudentClassController::index`） | 已有 `hasFractionalBalance` 守門，避免分鐘制精確值被 count-based 覆寫——**這部分基礎設施已就緒** | `StudentClassController.php:395-410` |
| 家長端顯示 | 未直接調查（超出四個 agent 分工範圍），需在實作前確認家長 App/LIFF 是否讀 `remaining_sessions` 或 `remaining_minutes` |
| 主任端報表／繳費提醒 | `AlertController::tuition` 明確用 `RemainingSessions <= 2` 判斷續課提醒（`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`），**全部是整數比較**，換成分鐘權威後需確認顯示值換算不會讓提醒邏輯誤判（例如 2.4 堂 vs 2 堂的邊界） |
| 共用課程包（`CoursePackage`） | **高風險**——`package_session_ledger.delta` 是 `tinyInteger` 硬編碼 ±1，結構上無法承載部分堂數，已知技術債 `TD-059`／`#1343` |
| Charge／計費快照 | `preservedDelta`（G-009）與 `rate_unit='hour'` 自動切換都跟 `SessionDuration`/`SessionCount` 共用同一批欄位，Option A/B 實作時必須同 PR 檢視，避免重蹈 G-009 覆轍 |

---

## 9. Option A／B／C 比較

### Option A：分鐘作為 authoritative balance（`purchased_minutes`/`consumed_minutes`/`remaining_minutes`）

- **優點**：`#613 A1` 已經把地基打好——`PurchasedMinutes`/`RemainingMinutes` 欄位、`session_deduction_ledger.minutes` 欄位、`ROUND_HALF_UP` 整數換算、`hasFractionalBalance` 讀取端守門，全部已存在且有測試。真正要做的不是「新建一套系統」，而是**把觸發條件從「只認補課」放寬到「常態排課的每堂實際時長」**。
- **Migration 成本**：**低**——欄位已存在（additive、nullable，`2026_05_31` 系列 migration 早已 merge），不需要新 migration 即可開始擴大讀取範圍；如果要讓「新增課程」時就能設定「契約標準堂長」與「排課時長」分離，需要新增一個獨立欄位（例如 `billing_unit_minutes`，與 `SessionDuration` 分開，因為 `SessionDuration` 目前身兼二職）——這是唯一必要的新 migration，且是 additive/nullable，向後相容。
- **對既有 2 小時課程的影響**：**零**——`recomputeCounters()` 的 `hasPartial` 判斷天生具備「無分鐘制事件時 byte-identical」的安全網（`SessionDeductionMinutesEngineTest::test_whole_session_path_unchanged_and_minutes_derived` 已驗證），2 小時標準課程從未產生 `minutes != perSession` 的 ledger 列，永遠走舊制公式，行為不變。
- **報表與 API 變更**：`AlertController::tuition`、`DirectorDashboard` 等目前吃 `RemainingSessions` 整數比較的邏輯，需要明確決定「比較顯示堂數」還是「比較剩餘分鐘」，這是 Founder 決策點（見 §14）。
- **Rounding 規則**：沿用既有 `ROUND_HALF_UP`（`intdiv($minutes*2+$perSession, $perSession*2)`），已是整數安全實作，不需重新設計。
- **共用包（package）風險**：`package_session_ledger.delta` 的 `tinyInteger ±1` 限制必須連動處理（`TD-059`），否則 Option A 只解決個人課程，共用包仍會漂移。

### Option B：允許 decimal lesson units（例如直接扣 1.50 堂）

- **Precision/Rounding**：需要把 `SessionCount`/`RemainingSessions`/`UsedSessions` 從 `integer` 改成 `decimal`，直接衝擊全部依賴這些欄位的 API 回傳型別（前端 `sessions_purchased`/`remaining_sessions` 目前都當整數處理，見 `frontend/src/lib/studentClassDisplay.test.js` 的 `'16 堂'` 字串組裝邏輯）。
- **資料庫欄位**：需要新 migration 把三個欄位型別改掉，這是**破壞性 schema 變更**（非 additive），且會直接影響 `package_session_ledger.delta`（目前 `tinyInteger`，改 decimal 影響範圍更廣，因為多個課程共用同一個池）。
- **前後端格式**：金額／堂數的顯示慣例（`docs/RULE_DESIGN_SYSTEM.md` 提到金額需 tabular 對齊）需要重新設計「1.5 堂」這種數字要怎麼呈現，容易產生「0.1+0.2 浮點數誤差」類 bug（題目也明確要求不可用 binary float）。
- **報表加總**：多筆課程加總「1.5+0.75+…」若真的存 decimal，長期累積誤差風險比 Option A（先累加分鐘再一次換算）更高，因為 Option A 的加總永遠在整數分鐘域，只在最後顯示時才做一次除法。
- **本質問題**：Option B 仍然是「把計費單位跟實際時間耦合在一起」——1.5 堂這個數字本身不帶時間單位資訊，換算規則（1 堂=幾分鐘）若日後調整（例如漲價、改堂長），舊資料的 1.5 堂無法回推當時代表幾分鐘；Option A 因為存的是分鐘，任何時候都能用當下的 `standard_lesson_minutes` 重新換算成堂數顯示，可稽核性更好。

### Option C：只允許自訂方案堂數（例如改收 6 堂=12 小時 或 9 堂=18 小時）

- 6 堂×3 小時=18 小時、9 堂×3 小時=27 小時——題目給的例子（6 堂=12 小時，4 個 3 小時 session）之所以「剛好整除」，是因為刻意選了「總時數 ÷ 3 小時 = 整數」的堂數；本質上是**把「一堂」的定義從全公司統一的 120 分鐘，改成幫這個學生量身訂做的另一個整堂單位（180 分鐘）**。
- **為什麼只能緩解、不能真正解決**：
  1. **只解決「固定時長、固定堂數」的單一組合**，題目案例 4（同一課程週二 180 分鐘、週六 120 分鐘混合）用 Option C 完全無法表達——不存在一個「一堂」的定義能同時整除 180 與 120 又維持整數堂數語意（除非退化成「找最大公因數 60 分鐘＝1 堂」，那又回到 Option A 的分鐘制精神，只是換了個名字）。
  2. **調課／請假時一旦跨到不同時長的補課，堂數單位又會錯位**——例如這學生某次請假、改約到 120 分鐘的補課時段，用「1 堂=180 分鐘」的自訂方案去扣，同樣會產生「這堂到底算不算 1 堂」的爭議，Option C 沒有解決跨堂長換算，只是把問題從「AllTrue 全公司標準」換成「這個學生的客製標準」，換算爭議依然存在。
  3. **跨期／續期時，若下一期方案改回標準 8 堂×2 小時，兩期之間的「堂」定義不同，家長與主任的認知會斷裂**（本期 1 堂=180 分鐘，下期 1 堂=120 分鐘），對帳與續費提醒都需要額外標記「這是哪個版本的堂數定義」，複雜度並未真正降低，只是把複雜度從系統轉嫁到人工溝通。
  4. 任意時長（例如題目要求的「不要假設一定是 3 小時」）在 Option C 下每出現一種新時長組合，就要新開一種自訂方案，長期會產生大量「for this student only」的特例邏輯，與 CLAUDE.md 明文禁止的「不應為單一學生建立硬編碼特例」精神直接衝突。

**建議方向**：Option A 作為長期正確模型，理由是**現有 `#613 A1` 已經是 Option A 的雛型**，遷移成本遠低於從零設計；Option B 的「本質問題」在題目本身也已點名（「不要直接使用 binary float 作為帳務真相」），不建議採用；Option C 僅適合作為「主任暫時繞道的操作建議」（見 §10 跨期處理選項 5），不建議當成產品的正式解法。

---

## 10. 建議產品模型

### Authoritative truth

- 沿用並擴大 `#613 A1` 既有欄位：`StudentClass.PurchasedMinutes`/`RemainingMinutes` + `session_deduction_ledger.minutes` 作為權威。
- **新增**一個獨立欄位（暫名 `StudentClass.standard_lesson_minutes`，語意＝「這門課的計費標準堂長」，預設沿用公司慣例 120），使其**與** `SessionDuration`（目前身兼「排課預設時長」與「計費標準」二職）**脫鉤**。這是本報告認定唯一必要的新增欄位／migration（additive、nullable，向後相容；未設定時 fallback 到現行 `SessionDuration`，行為不變）。
- 扣堂時，`resolvePartialMakeupMinutes()` 的判斷條件從「只認 `schedules.type='extra'`」擴大為「任何 `ClassSession` 實際時長 ≠ `standard_lesson_minutes` 時都記錄真實分鐘」，不論是否為補課——常態排課的 180 分鐘課，點名時就會記 `minutes=180`，跟現在補課路徑的處理方式完全相同，只是觸發條件從「是不是補課」改成「時長是否等於標準堂長」。

### Derived values

- `lesson_equivalent = minutes / standard_lesson_minutes`，沿用既有整數安全 `ROUND_HALF_UP`（無需重新設計）。
- `RemainingSessions`/`UsedSessions` 維持現有「顯示用衍生欄」定位，讀取端維持現有 `hasFractionalBalance` 守門邏輯（`StudentClassController.php:399-410`），只需要把判斷來源從「有沒有補課事件」改成「有沒有任何非整堂事件」（邏輯本身不用改，因為它已經是通用的 `minutes % perSessionMin != 0` 檢查）。

### UX preview

新增課程頁「固定上課星期」卡片旁，新增一段即時試算文字（可用純前端計算，公式對照 §7 Case 2 的分鐘表）：

```
每週上課 2 次，每週共 6 小時，每週消耗 3 堂（標準堂長 120 分鐘）
本期共 16 小時（8 堂 × 120 分鐘）
可完整涵蓋 5 次 3 小時課程；第 5 次後剩餘 1 小時
第 6 次課程將超出本期額度 2 小時（相當於 1 堂）
```

此文字純粹是「標準堂長」與「使用者輸入的排課時長」兩個數字相除後的提示，不需要等後端引擎擴大範圍就可以先做（前端已有 `avgSessionMinutes`/`estimateCreateCharge` 的計算基礎，見 §3）。

### Cross-period handling（產品決策點，不由本報告代答，見 §14）

五個選項對應影響（依題目要求逐一列出，不替 Founder 決定）：

1. **允許本期剩餘 + 下一期額度**：教務操作簡單（系統自動跨期扣），但需要「下一期額度」這個概念在系統裡先存在（目前續約是建立全新一期 `StudentClass`，兩期之間沒有共用池，需額外設計"跨期借用"帳本，類似 `CoursePackage` 但方向相反）；對帳複雜度最高（一次點名可能同時影響兩期發票）。
2. **下一期未繳費時允許負額度**：排課連續性最好（不中斷），但欠費風險最高（等於系統主動墊款），且與現有「金流與堂數獨立」設計哲學（`SessionDeductionService.php:393-397`）衝突——目前系統刻意讓扣堂不依賴繳費狀態，允許負額度等於進一步放大這個解耦，需要 Founder 明確承擔風險。
3. **下一期未繳費時只顯示 warning**：家長理解成本最低（照常上課，只是提醒），但對帳仍會出現「已上但未計入任何一期發票」的堂次，需要額外報表欄位追蹤「待歸屬」堂次。
4. **下一期未繳費時禁止完成點名或扣堂**：欠費風險最低、會計對帳最乾淨，但排課連續性最差——老師/家長會在教室現場卡住（家長認知：學生已經到校，為何不能點名，這與 `ADR_006` §1.1 描述的「有 entitlement 卻不能點名」現場痛點是同一類使用者體驗問題，需一併評估）。
5. **建議主任改用能整除的方案（如 12 小時或 18 小時）**：教務操作最省事（不需碰引擎），但如 §9 Option C 分析，只是把問題往後延一期，且與「不應假設所有課都湊得出整除方案」的現實衝突（家長臨時決定加課/減課次數時，方案又會不整除）。

---

## 11. 最小 vertical slice

**目標**：讓 Case 2（常態排課、非補課）也能走分鐘權威，且對現有 2 小時課程 byte-identical。

1. **新增欄位**（additive、nullable，不改變任何現有讀寫）：`StudentClass.standard_lesson_minutes`。未設定時，`perSessionMinutes()` fallback 到現行 `SessionDuration`（完全不影響現有課程）。
2. **`resolvePartialMakeupMinutes()` 擴大判斷條件**：新增一個判斷分支——若 `ClassSession` 的實際時長（`durationMinutes(StartTime, EndTime)`）≠ `standard_lesson_minutes`（不論是否為補課），回傳實際分鐘；只有兩者相等時才回 `null`（整堂）。**注意**：命名需要調整（該方法現在的名字 `resolvePartialMakeupMinutes` 隱含「僅限補課」，擴大範圍後應改名或新增一個涵蓋常態排課的方法，避免誤導後續開發者，同一類根因見 `TD-060` 的「死碼未接線」教訓）。
3. **`recomputeCounters()` 不需要改**——`hasPartial` 判斷已經是通用的 `minutes != perSession` 查詢，不管 minutes 是從補課還是常態排課寫入的。
4. **新增課程 UI**：在「預設上課時長」旁邊新增「標準計費堂長」欄位（預設等於前者，但可分開填），寫回 `standard_lesson_minutes` 而非覆寫 `SessionDuration`。
5. **共用課程包排除在此 slice 之外**——`package_session_ledger.delta` 的 `tinyInteger ±1` 限制（`TD-059`）需要獨立評估是否要在此 slice 一併擴大，或明確排除「非標準時長課程不可加入共用包」作為過渡期限制（建議後者，降低本次改動範圍）。
6. **讀取端**：`StudentClassController::index` 的 `hasFractionalBalance` 守門邏輯已經通用，理論上不需要改；但需要新增測試驗證「常態排課觸發的分鐘制」也不會被 count-based self-heal 覆寫。

**刻意不做**（維持題目 Non-scope）：不動 `SessionDuration` 既有語意（保留給「排課預設時長」用途，供 `EnrollmentService`/`day_time_slots` 沿用）；不改變任何既有課程的 `RemainingSessions` 計算結果；不動 `CoursePackage`/`package_session_ledger`；不動繳費提醒（`AlertController::tuition`）的整數比較邏輯。

---

## 12. Migration 與 backward compatibility

- **新增欄位** `StudentClass.standard_lesson_minutes`：`integer nullable`，additive，`down()` 直接 drop，符合 `docs/RULE_MIGRATION_COMPAT.md` 的 Expand/Contract 慣例（先 expand，行為不變，待前端全面切換後才考慮 contract 掉 `SessionDuration` 身兼二職的舊用法——但預期短中期仍會保留 `SessionDuration` 供排課使用，不會 contract）。
- **`resolvePartialMakeupMinutes()` 擴大範圍是行為變更，不是純 additive**——任何目前「常態排課但實際時長 ≠ SessionDuration」的既有課程（例如因為 G-010/G-009 等歷史資料問題已經存在時長不一致的課程），一旦此欄位擴大生效，下一次點名就會從「整堂扣 1」變成「扣實際分鐘、顯示值可能變成非整數」——**這對既有資料是行為變更，必須先跑一次全庫掃描**，找出「`SessionDuration` 已設定，但存在 `ClassSession` 實際時長不同、且從未被標記為補課」的課程數量，評估影響範圍後才能決定要不要用 feature flag 分階段開啟（比照 `ADR_006` 的 `Ensure` 預設關閉模式）。
- **共用包（`CoursePackage`）**：本 slice 建議明確排除，避免 `TD-059` 提前發作；若日後要涵蓋，`package_session_ledger.delta` 需要新增 `minutes` 欄位（比照 `session_deduction_ledger` 已有的做法），是額外的 migration，不在本次最小 slice 內。
- **舊資料是否已存在小數堂數或異常餘額**：本次調查**未執行**任何 production 查詢（Non-scope 禁止），因此**無法確認**現有資料庫是否已有 `RemainingMinutes % SessionDuration != 0` 的既有課程——這是實作前必須先用**唯讀**查詢（例如比照 `docs/runbooks/1062-track-a-pcr.md`／`sessions:report-prepaid-horizon-phase0` 的唯讀報表模式）盤點的項目，若發現既有異常餘額，依 Stop condition 應立即停止並回報，不可在同一個 PR 裡順手修掉。

---

## 13. 測試計畫

沿用既有測試檔案的模式（`SessionDeductionMinutesEngineTest`、`PartialMakeupDeductionTest`），新增：

1. **`RecurringNonStandardDurationDeductionTest`**（新檔）：
   - 常態排課（無 `schedules.type='extra'`）、`standard_lesson_minutes=120`、`ClassSession` 實際 180 分鐘 → 點名後斷言 `RemainingMinutes` 精確扣 180、`RemainingSessions` 顯示值符合 `ROUND_HALF_UP`。
   - 連續扣款到第 6 次、購買總額不足 180 分鐘的情境（§7 Case 2 路徑 B 目前未覆蓋的多堂連續超扣案例）——斷言目前行為（`RemainingMinutes` 封頂於 0、無告警），作為「現況 golden」，供 Founder 決定要不要在此測試上改變斷言（改成擋下點名、或允許負額度時再更新此測試）。
   - Case 3（90 分鐘＝0.75 標準堂）與 Case 4（同課程週二 180／週六 120）比照既有 `PartialMakeupDeductionTest` 命名慣例補齊。
2. **`StandardLessonMinutesDecouplingTest`**（新檔）：驗證新增/編輯課程時，`standard_lesson_minutes` 與 `SessionDuration`（排課時長）可以分開設定且互不覆寫——防止重蹈 `EnrollmentService.php:710`／`StudentClassController.php:3546` 目前的耦合 bug。
3. **既有 golden 測試必須全綠、斷言不變**：`SessionDeductionMinutesEngineTest`、`PartialMakeupDeductionTest`（尤其 `test_normal_short_session_not_prorated`/`test_normal_longer_session_not_prorated` 兩個舊測試的斷言需要明確更新為「新行為」或保留作為「補課路徑仍維持獨立行為」的對照組——這是需要與 Founder / 原始 `#613` 設計者確認的關鍵決策，因為這兩個測試目前的名字與註解明確主張「正常課堂一律整堂」是**刻意的設計**，不是待修的 bug，貿然翻轉斷言前必須先確認 §14 的決策）。
4. **共用包回歸**：新增測試明確驗證「非標準時長課程若嘗試加入共用包，應如何處理」（依 §11 建議先擋下或明確標記為不支援，需相應測試）。
5. **繳費提醒回歸**（`docs/DIRECTOR_PAYMENT_ALERT_RULES.md` 要求的既有測試 `TuitionAlertsApiTest`）：確認 `RemainingSessions <= 2` 的整數比較邏輯，在課程存在分鐘制事件、顯示值為非整堂 rounding 結果時，仍然如 Founder 決策的方式觸發提醒（此為修改 `AlertController::tuition` 前需先取得使用者明示同意的項目，見文件變更管制條款）。

---

## 14. Founder 必須拍板的決策

1. **是否要打開 `resolvePartialMakeupMinutes()` 的範圍到常態排課**——`docs/AI_REGRESSION_LESSONS.md §R59` 目前明文「禁止擴大到非 `extra`」，本報告建議的最小 vertical slice 正是要打破這條既有紅線，需要 Founder 明確覆核並更新該條規則（而非工程師片面決定繞過既有防再犯規則）。
2. **「標準堂長」是否要拆成公司層級的全域常數，還是維持課程層級可個別設定**——目前 `SessionDuration` 是逐課程設定，本報告 §11 建議新增 `standard_lesson_minutes` 亦為逐課程欄位；若 Founder 希望「標準堂長＝公司唯一常數（如 120 分鐘）」，則應改為系統設定值而非逐課程欄位，影響 migration 設計方向。
3. **跨期處理五選項**（§10 Cross-period handling）——本報告刻意不代答，需 Founder 從教務操作／家長理解／會計對帳／欠費風險／排課連續性五個面向拍板。
4. **第 6 次課程缺口的即時行為**——是否要新增「阻止點名」或「阻止排課」的攔阻（目前完全沒有，見 §7 Case 2 路徑 B），或維持現況「餘額歸零、不告警」但補上告警（不阻擋，只提醒主任/家長）。
5. **是否要同步涵蓋共用課程包（`CoursePackage`）**，或明確接受「非標準時長課程暫不支援共用包」作為過渡限制（本報告建議後者，但需 Founder 確認此限制對現有主任操作習慣的影響）。
6. **既有 103 個測試檔硬編碼 `SessionDuration=120` 是否需要系統性抽樣改成多元時長**，以避免未來類似 bug 因測試套件偏誤而不可見（技術債層級決定，建議記錄進 `docs/TECH_DEBT.md`，非本次 slice 必須項）。
7. **`preservedDelta`（G-009）與 `rate_unit='hour'` 自動切換是否要在本次一併重新檢視**——兩者都跟 `SessionDuration`/`SessionCount`/`Charge` 共用欄位，但屬於既有已知問題（G-009 已有 GitHub #798/#799 追蹤），建議明確排除在本次 slice 之外，待獨立時程處理，避免範圍蔓延。

---

## 15. 建議 implementation sequence

1. **Phase 0（唯讀盤點，本報告已完成大半）**：完成 §12 提到的「既有資料是否已存在時長不一致但未走分鐘制」的唯讀查詢，量化影響範圍；同步請 Founder 就 §14 七項決策逐一拍板。
2. **Phase 1（地基擴充，additive-only）**：新增 `standard_lesson_minutes` 欄位；新增課程/編輯課程 UI 讓兩個時長欄位脫鉤（不影響任何既有課程，因為未設定時 fallback 到現行 `SessionDuration`）。
3. **Phase 2（引擎擴大，feature-flag 保護）**：擴大 `resolvePartialMakeupMinutes`（或新增平行方法）判斷條件；比照 `ADR_006` 的 `Ensure` 模式，先以 feature flag 預設關閉，僅在測試/沙盒環境驗證 byte-identical 安全網與新行為皆正確後，才對特定分校/課程開放。
4. **Phase 3（UX 預覽 + 告警）**：新增課程頁即時試算文字（§10 UX preview）；主任/家長端補上「本期即將超出額度」告警（依 §14 決策 4 的拍板結果決定告警強度）。
5. **Phase 4（依 Founder 跨期決策拍板實作）**：實作 §10 五選項中被選中的跨期處理機制；此階段範圍完全取決於 Founder 決策，無法在拍板前預先設計。
6. **Phase 5（共用包涵蓋，視 §14 決策 5 決定是否執行）**：若決定涵蓋 `CoursePackage`，比照 `session_deduction_ledger.minutes` 的既有做法為 `package_session_ledger` 新增 `minutes` 欄位，解決 `TD-059`。

每個 Phase 之間都應該是可獨立回滾的（additive migration + feature flag），符合題目「本階段不要...改變現有扣堂結果」與公司既有 Expand/Contract 慣例。

---

## 16. Evidence appendix

**HEAD SHA**：`66788a8701886c110c11a339ae6eddb2099c3903`（branch `claude/alltrue-non-standard-duration-4jjfn2`，working tree clean，未做任何 production 程式碼／migration／資料變更）

**關鍵檔案**（皆已直接讀取或由本次調查的 Explore agent 交叉確認）：
- `backend/app/Models/StudentClass.php`（`perSessionMinutes()` L120-126、`resolveSessionDurationForWeekday()` L97-113、`$fillable` L14-28）
- `backend/app/Services/SessionDeductionService.php`（`recomputeCounters()` L301-408、`deductForSession()` L196-232、`reverseForSession()` L238-283、`resolvePartialMakeupMinutes()` L463-499、`roundHalfUp()` L521-527）
- `backend/app/Services/PackageDeductionService.php`（`deductForSession()` L20-59）
- `backend/app/Models/CoursePackage.php`（`computeRemainingFromLedger()`、`recomputeCounters()`）
- `backend/app/Services/EnrollmentService.php`（`groupGlobalDur` L621-626,710）
- `backend/app/Http/Controllers/StudentClassController.php`（L260-420 讀取端組裝、L1180-1330 `store()`／validation、L1490-1545 `update()` preservedDelta、L3440-3568 `mapFrontendPayload()`、L5190-5387 `mapScheduleSlots`/`buildSessionsFromWeeklySchedule`/`buildSessionsForCount`）
- `backend/app/Http/Controllers/ClassSessionController.php`（`batchStore()` 驗證規則）
- `backend/app/Services/ClassSessionMaterializationService.php`（`upsertSlot()`）
- `backend/app/Console/Commands/BackfillMissingClassSessionsFromSchedules.php`
- `backend/app/Services/ForwardSessionGenerator.php`
- `frontend/src/components/UniversalClassScheduler.vue`（表單欄位、`submit()` L2047-2059/2199-2226、`hasPerDayDuration` L1281-1285、`updateSlotDur()` L1787-1795）
- `frontend/src/lib/universalSchedulerApi.js`
- Migrations：`2026_02_07_000004_create_student_classes_table.php`、`2026_02_07_000009_create_class_sessions_table.php`、`2026_02_07_000015_add_remaining_sessions_to_student_class_table.php`、`2026_02_13_000007_add_used_sessions_to_student_class_table.php`、`2026_04_10_000001_add_per_day_duration_to_student_class.php`、`2026_05_31_000001_add_minutes_balance_to_student_class.php`、`2026_05_31_000002_add_minutes_to_session_deduction_ledger.php`、`2026_04_15_300000_add_package_fields_to_student_class.php`、`2026_04_15_300001_create_package_session_ledger.php`

**測試**：
- `backend/tests/Feature/SessionDeductionMinutesEngineTest.php`（全部 5 個測試）
- `backend/tests/Feature/PartialMakeupDeductionTest.php`（全部 6 個測試，尤其 `test_normal_short_session_not_prorated`/`test_normal_longer_session_not_prorated`）
- `backend/tests/Feature/MinutesBalanceFoundationTest.php`
- `backend/tests/Feature/AttendanceRemainingSessionsRegressionTest.php`
- `backend/tests/Feature/RateUnitChargeCalculationTest.php`
- `backend/tests/Feature/ScheduleLeaveCascadeTest.php`
- `backend/tests/Feature/PackageTotalSessionsSyncTest.php`
- ADR-006 測試群（`AuditStrandedPaidSessionsTest`、`EnsureSessionHorizonTest`、`PrepaidHorizonPhase0ReportTest`、`PoolCoveragePlanTest` 等）——確認與本案例是不同問題，不重複列出全部斷言

**文件**（引用其聲稱內容，非 runtime 事實，已在正文標明）：
- `docs/PRICING_CONTRACT.md`
- `docs/ADR_006_prepaid_session_horizon_and_commitment.md`
- `docs/DIRECTOR_PAYMENT_ALERT_RULES.md`
- `docs/TECH_DEBT.md`（`TD-059`、`TD-060`）
- `docs/AI_REGRESSION_LESSONS.md`（`§R59`、`§R76`、`§R77`）
- `docs/SYSTEM_TECH_GUIDE.md`（§5 堂次扣除系統）
- `CLAUDE.md`（G-009、G-010）

**執行的指令**（全部唯讀，未寫入任何資料庫或 production 環境）：
`git status`、`git rev-parse HEAD`、`git log --oneline`、多次 `Read`/`Grep`/`Glob` 對上述檔案、四個 Explore agent 的獨立程式碼搜尋（資料模型、新增課程流程、扣堂 write path、既有測試）。

**尚未確認的假設 / 本次調查範圍外**：
1. 家長端（App/LIFF）與主任端報表是否直接讀 `remaining_sessions` 或另有快取／投影邏輯，未逐一走查（§8 已標記為待確認）。
2. 現有生產資料庫是否已存在「`RemainingMinutes` 非整堂倍數但未被標記為補課」的既有課程——本次未執行任何 production 查詢（依 Non-scope 要求），此為 Phase 0 必須先做的唯讀盤點（§12、§15）。
3. `docs/AI_REGRESSION_LESSONS.md §R59`「禁止擴大到非 extra」背後的原始產品理由未見於程式碼或文件註解，僅能確認「這是刻意設計」，無法確認「為何刻意如此設計」——需 Founder 或原始需求提出者補充（§14 決策 1）。
4. 共用課程包（`CoursePackage`）在「非標準時長」情境下的正確產品行為（禁止加入／需要新欄位／其他）未經 Founder 決策，本報告僅指出風險與建議排除範圍（§11、§14 決策 5）。
5. Case 2 路徑 B「連續多堂超扣、非單次超扣」的行為，是由程式碼邏輯合理外推（`max(0, min($purchasedMinutes,...))` 的封頂算式），但**沒有直接測試驗證多堂連續案例**，建議列入 §13 測試計畫第 1 項優先補齊，作為分析結論的獨立驗證。
