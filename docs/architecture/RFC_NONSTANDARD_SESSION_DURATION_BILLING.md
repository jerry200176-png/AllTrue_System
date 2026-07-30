---
owner: jerry (CEO)
status: Draft — RFC only, no code/schema/test changed (amendment v2)
review_cycle: on next founder review
last_reviewed: 2026-07-30
---

# AllTrue 非標準課程時長調查報告

> **Status:** RFC only. No production code, migration, test, or billing behavior was changed to produce this report or its amendment. All claims are cited to file:line; anything not directly read in code is explicitly labeled as a documentation claim or an open assumption.
> **Amendment note (this version):** incorporates Founder decisions D1–D4 and 5 required corrections requested after the first draft was reviewed. The first draft's core investigation (§2–§8, evidence) stands; sections that assumed the first draft's proposed model was implementation-ready have been corrected. See the amendment report delivered alongside this commit for a section-by-section diff summary.
> **Related:** `#613` A1（既有分鐘制地基）、`TD-059`、`docs/AI_REGRESSION_LESSONS.md §R59`、`docs/SYSTEM_TECH_GUIDE.md §5`、`docs/PRICING_CONTRACT.md`、`docs/ADR_006_prepaid_session_horizon_and_commitment.md`（不同問題，見 §8、§10）

---

## 1. Executive conclusion

**AllTrue 已經有一半的答案，但只接到「補課」這條窄路上——且「購買堂數」跟「排出幾個 ClassSession」目前是同一個數字，這是比分鐘引擎更前面的一層耦合。**

1. 系統目前是**兩套模型並存**：
   - **舊制（主流，所有讀取路徑的預設）**：1 `ClassSession`／1 次點名 = 1 個整數「堂」，堂數欄位（`SessionCount`/`RemainingSessions`/`UsedSessions`）全部是 DB `integer`。
   - **新制（`#613 A1`，已 merge、有測試，但範圍極窄）**：`StudentClass.PurchasedMinutes`/`RemainingMinutes` + `session_deduction_ledger.minutes` 是一套**已經存在、可運作**的分鐘制權威餘額引擎，用整數安全的 `ROUND_HALF_UP` 把分鐘換算回「堂數顯示值」。
2. **分鐘引擎只在「補課」（`schedules.type='extra'`）且時長 ≠ 契約標準時才會啟動**（`SessionDeductionService::resolvePartialMakeupMinutes()`，`backend/app/Services/SessionDeductionService.php:468-499`）。測試直接證明：`PartialMakeupDeductionTest::test_normal_longer_session_not_prorated`（`backend/tests/Feature/PartialMakeupDeductionTest.php:125-142`）——180 分鐘、非 `type='extra'` 的**正常**堂次點名，仍斷言只扣 1 堂。
3. **根因不只是引擎範圍，還有一層更前面的耦合，本次修訂新增確認**：`EnrollmentService::store()` 目前**強制驗證**「購買堂數」（`total_classes` → `plannedSessions`）必須**恰好等於**「要建立的 `ClassSession` 筆數」（`count($sessionRows)`），不相等會直接 422（`backend/app/Services/EnrollmentService.php:197-222`，尤其第 210-222 行的等式檢查）。也就是說，**現行系統不只是「假設」SessionCount＝要物化的堂次數，而是用驗證規則把兩者鎖死相等**——這代表就算只加 `standard_lesson_minutes` 跟修改扣堂引擎，仍無法解決「買 8 個標準單位、實際只該排出約 5.3 次 180 分鐘課」這件事，因為建課當下系統會強迫你排出剛好 8 個 `ClassSession`，而不是依額度算出的 occurrence 數。本修訂版把這個缺口獨立列為必須先解的問題（見 §2.5、§7、§11 Phase 0B）。
4. **Founder 已就四項核心產品語意拍板**（本修訂版把決策內建進模型）：
   - **D1**：新增 `standard_lesson_minutes`（計費標準堂長），與排課時長脫鉤；系統/分校可設預設值（例如 120），課程層級可 override；**不假設全公司永遠只有 120 分鐘**。
   - **D2**：常態課程分鐘扣除**只能 explicit opt-in**，且**不批准**把既有正常課程整批切成分鐘扣除；opt-in 機制選用**明確的 `deduction_basis` 欄位**（`fixed_session` | `actual_duration`），理由見 §10；`R59` 改寫為「禁止未經 explicit opt-in 擴大常態課程分鐘扣除」，不刪除。
   - **D3**：第一版**不做**自動跨期拆帳／借用下一期／負餘額自動轉移／跨發票分攤／自動 debt settlement；改採「建課時預測 coverage → 超額前 warning → 點名不中斷 → ledger 保留完整實際扣除分鐘 → 顯示/回報 derived `uncovered_minutes`」，跨期分配留給後續獨立 workflow。
   - **D4**：第一版 `actual_duration` 課程**不得**加入 `CoursePackage`；本 RFC 的 implementation slice **不擴大** `package_session_ledger`。
5. **退款（refund）在程式碼裡完全不存在**——全庫搜尋 0 測試命中；金流（`Invoice`/`Payment`/voidInvoice）與堂數餘額是完全獨立的兩套系統（`SessionDeductionService.php:393-397`）。與本次修訂無直接關聯，維持原結論。
6. **扣堂沒有單一 choke point**：`SessionDeductionService::recomputeCounters()` 是唯一計算權威，但至少 9 個獨立入口觸發它。維持原結論。
7. **超扣「無跡可尋」的敘述本次已修正**：`session_deduction_ledger` 其實**保留了每一筆完整的實際扣除分鐘**，理論上可以推導出「淨扣分鐘超過購買分鐘多少」，但目前**沒有任何地方把這個超額值持久化、暴露成 API 欄位、或觸發告警**——`recomputeCounters()` 內部算出的 uncapped `netMinutes` 是一個**用完即丟的區域變數**，從未存回資料庫或回傳給前端（`SessionDeductionService.php` 第 366-376 行一帶）。正確講法是「超額分鐘存在於 ledger aggregation 中，但被 floor/cap 隱藏，沒有 first-class uncovered balance／alert／workflow」——不是「資料真的消失」。本 RFC 定義了可從既有 ledger 推導、不需要新表的 `uncovered_minutes`（見 §10、§13 Q5）。
8. **排課／materialization 層**（`day_time_slots[].duration_minutes`、`StudentClass.duration1..duration6`）已支援任意時段時長，這部分結論不變；缺口在「entitlement 單位 vs occurrence 數量 vs 扣堂引擎」三者的耦合，不在排課本身。

**一句話結論（修訂版）**：AllTrue 的權威餘額是「堂數」（整數），`#613 A1` 已打好分鐘制地基但只通補課側門；本案例要打開的不只是引擎的「正門」，還有更前面「購買額度＝要排幾堂」這道目前被驗證規則鎖死的耦合。兩者都需要以 additive、opt-in、可獨立回滾的方式擴充，而不是重寫，也不是本次就把所有課程切過去。

---

## 2. Current authoritative model

### 2.1 資料表與欄位（已由程式碼確認，本次未變動）

| 概念 | 表／欄位 | 型別 | 來源 |
|---|---|---|---|
| 購買堂數（現況：同時身兼 entitlement 與 occurrence 數量，見 §2.5） | `StudentClass.SessionCount` | `integer`, nullable | `2026_02_07_000004_create_student_classes_table.php:51` |
| 剩餘堂數（顯示值） | `StudentClass.RemainingSessions` | `integer`, nullable | `2026_02_07_000015_add_remaining_sessions_to_student_class_table.php:15` |
| 已用堂數 | `StudentClass.UsedSessions` | `integer`, default 0 | `2026_02_13_000007_add_used_sessions_to_student_class_table.php:16` |
| 契約標準堂長（分鐘，現況：同時身兼排課預設時長，見 §3） | `StudentClass.SessionDuration` | `integer`, nullable | 同 create 表 line 52 |
| 每週各時段自訂時長 | `StudentClass.duration1..duration6` | `integer`, nullable ×6 | `2026_04_10_000001_add_per_day_duration_to_student_class.php` |
| 計費單位 | `StudentClass.rate_unit` | `string(16)` default `'session'` | 同上 migration |
| **購買總分鐘（權威，#613）** | `StudentClass.PurchasedMinutes` | `integer`, nullable | `2026_05_31_000001_add_minutes_balance_to_student_class.php:23` |
| **剩餘分鐘（權威，#613）** | `StudentClass.RemainingMinutes` | `integer`, nullable | 同上 line 26 |
| 扣堂事件帳本 | `session_deduction_ledger`（`event_type`, `minutes`, `source`, `class_session_id`） | `minutes` 為 `integer` nullable | `2026_05_31_000002_add_minutes_to_session_deduction_ledger.php` |
| 共用課程包堂數 | `CoursePackage.total_sessions/remaining_sessions/used_sessions` | `unsignedInteger`/`integer` | `CoursePackage.php` |
| 共用包帳本差量 | `package_session_ledger.delta` | **`tinyInteger`，硬編碼只允許 ±1** | migration `2026_04_15_300001_create_package_session_ledger.php`（見 §8、D4）|
| 單次課堂 | `ClassSession.StartTime/EndTime/Status/session_charge` | `time`/`time`/`string(16)`/`integer` | `2026_02_07_000009_create_class_sessions_table.php` + `2026_04_17_100000_add_session_charge_to_class_session.php` |

**`ClassSession` 沒有 `duration` 欄位，也沒有「本堂扣幾堂」欄位**——時長永遠是 `EndTime - StartTime` 現算，扣了幾堂完全記在 `session_deduction_ledger`。

**堂數欄位是否只能是整數？** 是——維持原結論，`SessionCount`/`RemainingSessions`/`UsedSessions`/`CoursePackage.total_sessions` 全部是 DB `integer`／`unsignedInteger`。

### 2.2 兩套並行的「堂數如何算出來」邏輯

**`SessionDeductionService::recomputeCounters()`**（`backend/app/Services/SessionDeductionService.php:301-408`）是唯一寫入 `RemainingSessions`/`UsedSessions`/`PurchasedMinutes`/`RemainingMinutes` 的地方：

```php
// SessionDeductionService.php:352-391
if ($isSessionMode && $sessionCount > 0) {
    $purchasedMinutes = $sessionCount * $perSession;   // ← 每次呼叫都重新用「目前」的 SessionCount × perSessionMinutes() 算，見 §10 D-immutability
    $hasPartial = SessionDeductionLedger::query()
        ->where('student_class_id', $studentClassId)
        ->whereIn('source', ['attendance', 'retro_leave', 'status_adjust'])
        ->whereNotNull('minutes')
        ->where('minutes', '!=', $perSession)
        ->exists();

    if ($hasPartial) {
        $remainingSessions = max(0, min($sessionCount, self::roundHalfUp($remainingMinutes, $perSession)));
        $sc->RemainingSessions = $remainingSessions;
        $sc->UsedSessions      = $sessionCount - $remainingSessions;
    } else {
        $sc->UsedSessions      = min($sessionCount, $usedByAttendance);
        $sc->RemainingSessions = max(0, $sessionCount - $usedByAttendance);
    }
}
```

而「這堂到底算不算部分時數」由 `resolvePartialMakeupMinutes()` 決定（`SessionDeductionService.php:463-499`），目前**唯一**判斷條件是 `schedules.type='extra'`（補課）：

```php
// 464-466 原文註解：
// #613 A1 + 補課加長：補課（schedules.type='extra'）時長 ≠ 契約每堂分鐘時，
// 回傳實際分鐘（可短於或長於 perSession）。非補課、剛好完整時長、或時間不足
// → null（＝整堂）。正常課堂一律整堂。禁止 clamp 回 perSession。
private static function resolvePartialMakeupMinutes(StudentClass $sc, int $classSessionId): ?int
{
    ...
    if (!$isMakeup) {
        return null;   // ← 一般排定的堂次，即使時長不同，也回 null
    }
    ...
}
```

**本次修訂維持原結論**：一般透過「新增課程」建立的固定週期堂次沒有 `type='extra'` 的 `schedules` 紀錄，`isMakeup` 恆為 `false`，扣堂恆為整堂。§10 說明如何在**明確 opt-in** 下擴大此判斷，同時保留這條路徑給仍選擇 `fixed_session` 的課程（含既有補課機制）完全不變。

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

- 引擎讀的是 `SessionDuration`，fallback 60 分鐘；多數控制器讀取端另用 120 分鐘當 fallback（`ScheduleGuardService.php:293` 等）——**60（模型層）與 120（多數控制器層）兩個不一致的 hardcoded fallback**，維持原結論為既有技術債，非本次修訂範圍。

### 2.4 已知限制（自己文件承認的，維持原結論）

- `TD-059`：共用課程包尚未分鐘感知，`package_session_ledger.delta` 仍是整堂 ±1。**D4 已拍板**：第一版 `actual_duration` 課程直接排除加入 `CoursePackage`，不在本 RFC 範圍內解 `TD-059`。
- `docs/AI_REGRESSION_LESSONS.md §R59`：「禁止擴大到非 `extra`」——**本次修訂不刪除此規則**，改寫為「禁止未經 explicit opt-in（`deduction_basis='actual_duration'`）擴大常態課程分鐘扣除」，見 §10、§13。

### 2.5（新增）購買額度與排課數量目前是同一個數字——術語修正

**問題**：修訂前的版本只分析了「分鐘扣堂引擎的範圍」，沒有分析「一開始要排幾個 `ClassSession`，這個數字從哪裡來」。實際追查發現，這一層在建課驗證階段就已經把兩者鎖死相等，比扣堂引擎更早卡住本案例。

**證據**（`backend/app/Services/EnrollmentService.php:197-222`）：

```php
$isSessionMode = ($data['payment_type'] ?? 'session') === 'session';
$plannedSessions = $this->resolvePlannedSessions($data, count($sessionRows));   // = (int) $data['total_classes']（session 模式，L962）
if ($plannedSessions <= 0) { /* 422：必須提供購買總堂數 */ }

if ($plannedSessions !== count($sessionRows)) {          // ← 兩者被要求「恰好相等」
    // 422：'堂數（session_plan 或日期清單總筆數）需與購買總堂數一致'
}
```

`count($sessionRows)` 就是最終會呼叫 `ClassSessionMaterializationService::upsertSlot()` 建立的 `ClassSession` 筆數（§4）。**這代表現行 API 不只是「假設」`SessionCount = 要物化的堂次數」，而是用一條硬性驗證規則把兩者鎖成同一個數字**，使用者連嘗試「輸入 8 個標準單位、但只排 6 個 180 分鐘 occurrence」都會被 422 擋下。

**本 RFC 引入四個獨立術語**，取代「SessionCount 身兼二職」的現況（**這是分析用術語，本次不建立對應的新 DB 欄位**——是否新增獨立欄位屬於 §11 Phase 1 的實作範圍，需另行評估）：

| 術語 | 語意 | 目前對應 |
|---|---|---|
| `purchased_standard_units` | 付費者購買的「標準堂」數量（entitlement，本案例＝8） | 目前＝`StudentClass.SessionCount`（含混）|
| `purchased_minutes` | `purchased_standard_units × standard_lesson_minutes`（entitlement 換算成分鐘） | 目前＝`StudentClass.PurchasedMinutes`，但由 `SessionCount × perSessionMinutes()` **即時重算**，見 §10 immutability |
| `scheduled_occurrence_count` | 依週期排課樣式（weekday/time slots）在某個時間範圍內，實際要物化幾個 `ClassSession` | 目前＝`count($sessionRows)`，且被驗證規則**強制等於** `purchased_standard_units`（`EnrollmentService.php:210-222`）|
| `scheduled_minutes` | `SUM(duration_minutes)`，實際排出的這些 occurrence 加總分鐘 | 目前無此彙總欄位，需要時可由 `ClassSession.StartTime/EndTime` 現算 |

**不得再假設**：`purchased_standard_units`（entitlement）＝`scheduled_occurrence_count`（要排幾堂）。這在 `fixed_session`（現況／未 opt-in）課程可以繼續保留現行等式（因為時長固定時兩者本來就該相等，行為不變）；但 `actual_duration`（opt-in）課程必須**移除**這條等式驗證，改用 §10／§11 Phase 0B 的 coverage preview 機制，讓使用者依實際額度與實際時長決定要排多少個 occurrence，而不是繼續強迫「買 8 個標準單位＝排 8 個 180 分鐘 occurrence」。

**與 ADR-006 對齊、但不擴張其 scope**：`ADR_006_prepaid_session_horizon_and_commitment.md` §1.3 已經提出「Schedule Commitment → materialization → pool coverage」的分層原則（該文件本身仍是 Accepted-but-not-activated，本 RFC 不改變其狀態）。本案例的 `scheduled_occurrence_count` 應該由「週期排課樣式（schedule commitment）+ 涵蓋範圍」決定，而**entitlement**（`purchased_standard_units`/`purchased_minutes`）只用來算出 coverage 夠不夠、在哪裡不夠（§11 Phase 0B），**不是**用來反推應該排幾堂——這與 ADR-006 §1.3 的分層精神一致，但本 RFC 只在「單一課程、建課當下的 occurrence 數量決策」這個範圍內採用同樣的分層邏輯，**不**啟用、不擴張 ADR-006 的 rolling horizon／`Ensure`／pool coverage 等既有 Phase 0–3A 機制或 command。

---

## 3. 新增課程完整資料流

**元件**：`frontend/src/components/UniversalClassScheduler.vue`（唯一的新增課程精靈）。

1. **UI 輸入**（`UniversalClassScheduler.vue`）：購買總堂數 `form.total_classes`（第 411-414 行）、預設上課時長 `form.duration_hours`（第 464-468 行，全域預設）、每時段獨立時長 `form.day_time_slots[].duration_hours`（`updateSlotDur()` 第 1787-1795 行）。前端合理性檢查（`submit()` 第 2047-2059 行）僅拒絕 <30 或 >480 分鐘，無「必須整除」或「必須等於標準堂長」檢查。`hasPerDayDuration`（第 1281-1285 行）偵測時段時長不一致時自動切 `rate_unit='hour'`。

2. **Payload**（`frontend/src/lib/universalSchedulerApi.js:22-63`）：`POST /api/v1/class-sessions/batch`，含 `day_time_slots[]`、`total_classes`、`duration_minutes`、`rate_unit`。

3. **後端驗證**（`ClassSessionController::batchStore`，`backend/app/Http/Controllers/ClassSessionController.php:40-88`）：`'total_classes' => 'nullable|integer|min:1|max:500'`、`'day_time_slots.*.duration_minutes' => 'nullable|integer|min:30|max:480'`。→ 交給 `EnrollmentService::store()`。

4. **`EnrollmentService::store()`**：
   - 先由 `day_time_slots`/`days_of_week`/`session_plan` 展開出 `$sessionRows`（第 128-157 行）。
   - **第 197-222 行**（本次修訂重點，見 §2.5）：`$plannedSessions = resolvePlannedSessions($data, count($sessionRows))`（＝`total_classes`），並**強制** `$plannedSessions === count($sessionRows)`，否則 422。**這是「購買額度＝排課數量」耦合的實際強制點，不是隱性假設。**
   - 第 621-626 行：`$groupGlobalDur = max(該科目所有時段 duration_minutes)`，第 710 行寫入 `SessionDuration = $groupGlobalDur`——契約標準堂長被排課時長覆寫（原結論維持）。
   - 第 668-671 行：`$groupSessionCount = count($rowsForSubject)`；`$sessionCount = $groupSessionCount`；`$chargeUnits = $sessionCount`——**佐證 §2.5**：`SessionCount` 與 `Charge` 都直接等於 occurrence 數量，不是獨立輸入的 entitlement。
   - `StudentClass::create($studentClassPayload)`；對每個 row 呼叫 `ClassSessionMaterializationService::upsertSlot()`。

5. **`StudentClassController::update()`**：`mapFrontendPayload()` 第 3546 行用第一個 `day_time_slots` 的時長覆寫 `SessionDuration`（原結論維持）。

**結論（修訂版，回答 quality gate 問題 1、2）**：現行系統裡 `SessionCount` **同時**是「使用者輸入的購買堂數」與「要建立的 `ClassSession` 筆數」，且**這個相等關係由 API 驗證規則強制**（不是可以繞過的預設值）。因此，若使用者輸入「8 堂、180 分鐘時段」，系統不是「傾向」建立 8 個 180 分鐘 `ClassSession`，而是**只能**這樣做（不相等會 422）——這正是「不能直接建立 8 個 180 分鐘 session」的問題所在：不是不能，而是現行系統**只能**這樣做，無法排出比購買額度真正需要更少的 occurrence 數。

---

## 4. 排課與 ClassSession materialization 流程

（與第一版相同，維持原結論，本次修訂未發現新事實）

- **常態排課**：`EnrollmentService::store()` 呼叫 `ClassSessionMaterializationService::upsertSlot()`（`backend/app/Services/ClassSessionMaterializationService.php:82-149`），`StartTime`/`EndTime` 完全取自呼叫端傳入值，無 2 小時預設。
- **補排（手動）**：`schedules:backfill-class-sessions`，未排入 `Kernel.php`（G-010 已知缺口，與本案例無直接關聯）。
- **向前生成**：`ForwardSessionGenerator::planCourse()`，屬 `ADR_006` 範疇——本 RFC 不擴張、不啟用該機制（見 §2.5 對齊聲明）。
- **`ClassSessionMaterializationService::upsertSlot()`** 是唯一 production 寫入 `ClassSession` 的權威路徑，一致性鍵 `(StudentClassID, SessionDate, StartTime)`。

**結論**：排課層完全支援任意時長；缺口在 §2.5（購買額度耦合）與 §5（扣堂引擎範圍），materialization 本身不需改動。

---

## 5. 點名與扣堂 authoritative write path

（與第一版相同，維持原結論）

**單一計算權威**：`SessionDeductionService::recomputeCounters()`，至少 9 個獨立呼叫點觸發：

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

**寫入機制**：`deductForSession()` 寫一列 `session_deduction_ledger`，不是 `UPDATE ... SET RemainingSessions -= 1`。

**餘額歸零／負餘額行為**：`RemainingSessions` 被 `max(0, ...)` 夾住不會顯示負數；唯一硬性擋下動作的檢查是 `AttendanceController.php:1124-1126`（`convertToAttended`，剩餘堂數 ≤0 回 422），**僅此一端點**；一般點名／新增堂次都沒有餘額檢查。

**請假／補課／調課／退款／終止對餘額的影響**：維持原表格結論，退款不存在，金流與堂數獨立。

---

## 6. 現有整數／固定兩小時假設

（維持原結論，僅第 4 點措辭與 §2.5 對齊）

1. **堂數欄位型別為整數**：`SessionCount`/`RemainingSessions`/`UsedSessions`/`CoursePackage.total_sessions` 全部整數；`package_session_ledger.delta` 是 `tinyInteger ±1`，結構上不可能記錄非整堂差量。
2. **兩個互相衝突的預設堂長常數**：模型層 60 分鐘 vs. 控制器層多處 120 分鐘 fallback，未統一，屬既有技術債。
3. **「1 lesson = 2 hours」是 UI 預設輸入值，不是寫死常數**——可自由改成 0.5–8 小時，且每時段可不同。
4. **`SessionDuration`（契約標準堂長）在建立/編輯時永遠等於實際排課時長，且 `SessionCount`（entitlement）永遠等於 occurrence 數量**（§2.5、§3）——這是本報告認定的**兩個疊加的核心資料模型缺口**，不是驗證規則不足，而是驗證規則主動鎖死了本不該相等的兩組概念。
5. **G-009 `preservedDelta`**：與本案例無直接關聯，實作時需一併注意（維持原結論）。
6. **測試層面偏誤**：`SessionDuration => 120` 在 103 個測試檔 vs. `=> 60` 只有 30 個，維持原結論。

---

## 7. 8 堂 × 2 小時、每次上課 3 小時的實際結果

以下沿用四個案例，Case 2 依修訂後的模型重新推演（含 Phase 0B coverage preview 的精確數字）。

### Case 1：標準課程（8 堂 × 120 分鐘）— 不變

8 次點名後 `UsedSessions=8`、`RemainingSessions=0`、`PurchasedMinutes=960`、`RemainingMinutes=0`。與預期一致，`fixed_session` 課程 byte-identical。

### Case 2：本案例（8 個標準單位、契約標準 120 分鐘、每次實際 180 分鐘）

**若照現有「新增課程」UI 直接操作（現況，未修訂前的唯一真實路徑）**：
- 因 §2.5 的等式驗證，使用者只能選擇「排 8 個 180 分鐘 occurrence」（`plannedSessions=8=count($sessionRows)`），否則 422。
- `SessionDuration` 被覆寫成 180，`PurchasedMinutes = 8×180 = 1440`（24 小時，非 Founder 預期的 16 小時）——原結論的營收缺口分析維持不變。

**若採用本 RFC 提出的 opt-in 模型（`deduction_basis='actual_duration'`，`standard_lesson_minutes=120`），Phase 0B coverage preview 應算出（與 Founder 提供的範例數字一致）**：

```
purchased_standard_units = 8
standard_lesson_minutes  = 120
purchased_minutes        = 8 × 120 = 960          entitlement_minutes = 960

scheduled 時段時長（session_duration） = 180

fully_covered_occurrences        = floor(960 / 180) = 5      （5 × 180 = 900）
remaining_after_full_occurrences = 960 − 900 = 60分鐘
first_partially_covered_occurrence = 第 6 次
partial_covered_minutes            = min(60, 180) = 60分鐘（第 6 次可用剩餘額度覆蓋的部分）
uncovered_minutes                  = 180 − 60 = 120分鐘（第 6 次超出的部分，相當於 1 個標準堂）
```

- 這組數字**逐項對應 Founder 給出的範例**（`entitlement_minutes=960`、`session_duration=180`、`fully_covered_occurrences=5`、`remaining_minutes=60`、`first_partial_occurrence=6`、`partial_covered_minutes=60`、`uncovered_minutes=120`），確認本 RFC 的公式定義正確。
- **D3 已拍板**：第一版**不會**自動處理這 120 分鐘的缺口（不借下一期、不自動負餘額）——系統只需要：(a) 建課當下把這組數字**預覽**給操作者看（Phase 0B，見 §11），讓操作者自己決定要排 5 個、6 個還是 8 個 occurrence；(b) 若操作者選擇仍排到第 6 次以後，點名時**不中斷**，`session_deduction_ledger` 照實記錄每次的實際分鐘；(c) 系統把 `uncovered_minutes` 算出來、顯示或回報，讓後續獨立 workflow（續約／加購）處理，而不是本 RFC 的 v1 範圍。
- **超額「無跡可尋」的敘述修正**：`session_deduction_ledger` 仍然會忠實記下第 6 次的完整 180 分鐘扣除（`minutes=180`），第 7、8 次同理；`net_deducted_minutes`（見 §10 定義）可由既有 ledger 直接 SUM 出來，因此「120 分鐘超額」**不是資料庫裡消失、無跡可尋**，而是**現有讀取端（`recomputeCounters()`）算出這個值後只拿來 floor/cap，沒有存成一個獨立、可查詢、可告警的欄位**。本 RFC §10 定義的 `uncovered_minutes` 就是把這個既有可推導但未曝光的值，正式定義成一個 derived API 欄位。

### Case 3：90 分鐘課程（0.75 標準堂）— 不變

若走 `actual_duration` opt-in（或現況的補課路徑）：`RemainingMinutes = 480-90=390`，顯示值 `ROUND_HALF_UP(390/120)=3`。ROUND_HALF_UP 換算是通用整數運算，非為特定比例硬編碼。

### Case 4：同一課程不同時長（週二 180 分鐘、週六 120 分鐘）— 不變

資料模型層可表達（`duration1..6`），但 `resolveSessionDurationForWeekday()` 目前只用於計費／單堂改時段（`FinanceController.php:524`、`ClassSessionController.php:1220`），**未被扣堂引擎引用**。本 RFC 的 v1 vertical slice（§11）仍以「單一 `standard_lesson_minutes` + 逐 `ClassSession` 實際時長比對」處理，不需要引擎讀 per-weekday duration——因為 opt-in 之後，判斷式是「這一堂的實際分鐘 ≠ 標準堂長」，天生就能處理「週二 180（≠120，記實際分鐘）、週六 120（＝120，整堂）」混合的情況，不需要额外改動 `resolveSessionDurationForWeekday()` 的呼叫範圍。

---

## 8. 受影響功能與風險

（維持原表格結論，補充 D1-D4 已拍板的欄位標記為「已有明確方向」而非「待決」）

| 功能 | 是否受影響 | 現況 |
|---|---|---|
| 新增課程 | 是——且新增確認：購買額度與 occurrence 數量目前被 API 驗證鎖死相等（§2.5） | `EnrollmentService.php:197-222,621-626,710` |
| 修改課程 | 是——`SessionDuration` 被第一個時段覆寫 | `StudentClassController.php:3542-3547` |
| 扣堂 | 核心受影響，範圍由 D2 決定（explicit opt-in） | `SessionDeductionService.php` |
| 共用課程包 | **D4 已拍板排除**，第一版不涵蓋 | `TD-059`／`#1343` |
| 跨期處理 | **D3 已拍板不做自動化**，只做預測+警示 | 見 §10 |
| 主任端報表／繳費提醒 | 待確認整數比較是否需連動（`RemainingSessions <=2`），本次未變動結論 | `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` |
| 家長端顯示 | 未直接調查，維持原「待確認」標記 | — |

---

## 9. Option A／B／C 比較

（結論維持：建議 Option A；本次修訂補充 opt-in 機制與不可變性對 Option A 的具體要求）

### Option A：分鐘作為 authoritative balance（建議方向，維持）

- 優點、migration 成本低、對既有課程零影響（byte-identical）等結論維持不變。
- **本次修訂新增**：Option A 的落地**必須**搭配 D2 的 explicit opt-in 欄位（`deduction_basis`），否則會有「新增 `standard_lesson_minutes` 後，只要該欄位非空就默默改變行為」的風險（見 §10 為何不採用 null-sentinel 方案）。
- **本次修訂新增**：Option A 的 `purchased_minutes` 若繼續用「`SessionCount × perSessionMinutes()` 每次即時重算」的現行公式，會有「事後編輯 `standard_lesson_minutes` 隱性竄改歷史購買額度」的風險——必須搭配 §10、§12 的 immutability 規則。

### Option B：allow decimal lesson units（維持結論：不建議）

不建議，理由同第一版（binary/decimal 精度風險、schema 破壞性變更、本質仍是把計費單位與時間耦合）。

### Option C：只允許自訂方案堂數（維持結論：僅能緩解）

僅能緩解、無法處理 Case 4 混合時長、無法處理跨期堂定義斷裂、易蔓延成單一學生特例，結論維持不變。

**建議方向不變**：Option A，但補上 D1-D4 四項 Founder 已拍板的護欄，使其成為一個**範圍受控、可回滾、explicit opt-in** 的擴充，而不是原第一版尚未定義好邊界的方向。

---

## 10. 建議產品模型（本次修訂重點）

### Authoritative truth

- 沿用並擴大 `#613 A1` 既有欄位：`StudentClass.PurchasedMinutes`/`RemainingMinutes` + `session_deduction_ledger.minutes` 作為分鐘制權威。
- **D1**：新增 `StudentClass.standard_lesson_minutes`（課程層級，nullable）。**系統/分校層級**另有一個預設值（例如常數或設定檔 120 分鐘，**不假設全公司永遠只有 120**——分校可能有不同慣例），課程未設定時 fallback 到此系統/分校預設；課程層級可 override。與 `SessionDuration`（保留給排課預設時長使用）脫鉤。
- **D2 opt-in 機制——選用 `deduction_basis` 明確欄位，而非 `standard_lesson_minutes IS NOT NULL` 當 sentinel**：

  | 方案 | 說明 | 問題 |
  |---|---|---|
  | (i) `standard_lesson_minutes = null` → legacy；`!= null` → duration-aware | 用欄位是否為空當開關 | **不建議**：D1 已批准系統/分校可以有預設值，若這個預設值被自動帶入每一門課的 `standard_lesson_minutes`（例如用於 Phase 0B 預覽），會導致「只是想顯示個標準堂長」卻**意外**打開 duration-aware 扣堂行為——欄位的「有沒有值」與「要不要改變扣堂行為」是兩件事，混在一起會製造隱性副作用，違反 D2「不批准直接把所有既有正常課程切換」的精神 |
  | (ii) **`deduction_basis` enum：`fixed_session`（預設） \| `actual_duration`** | 獨立欄位，明確表達「這門課的扣堂行為模式」；`standard_lesson_minutes` 只負責「數值」，`deduction_basis` 只負責「要不要用這個數值去做比例扣堂」 | 需要多一個欄位／一次 migration，但語意清楚、可稽核（欄位變更本身就是一個明確、可記錄的操作事件），不會因為「順手填了一個標準堂長」而誤觸發新行為 |

  **採用方案 (ii)**：所有既有課程與所有新課程，`deduction_basis` **預設為 `fixed_session`**（即使 `standard_lesson_minutes` 已被設定，也不影響行為）。只有課程被**明確**設為 `deduction_basis='actual_duration'` 時，扣堂引擎才會啟用實際分鐘比對。這同時滿足 D2「不批准整批切換既有正常課程」——因為預設值本身就是保持現狀，需要一個獨立、有意識的操作才會改變行為。

- **R59 改寫（不刪除；本 PR 只在此 RFC 提出 exact patch proposal，不直接修改 `docs/AI_REGRESSION_LESSONS.md` 正文——修改防再犯規則本身需 Founder 另行核准，見 §14／Part G）**：

  ```diff
  --- a/docs/AI_REGRESSION_LESSONS.md  (§R59，僅示意，未套用)
  +++ b/docs/AI_REGRESSION_LESSONS.md  (提案文字)
  @@ 強制規則（改扣堂/堂數顯示前必讀） @@
  - **補課非標準時長**（短於**或長於**契約每堂分鐘）走實際分鐘；正常課堂一律整堂。**禁止**擴大到非 `extra`；**禁止** clamp 回 perSession。
  + **補課非標準時長**（短於**或長於**契約每堂分鐘）走實際分鐘；正常課堂一律整堂——**除非該課程 `deduction_basis` 被明確設為 `actual_duration`**（RFC_NONSTANDARD_SESSION_DURATION_BILLING §10 D2）。
  + **禁止**在課程 `deduction_basis` 仍為預設 `fixed_session`（含所有既有課程、所有新課程的未 opt-in 狀態）時，擴大常態課程的分鐘制判斷；**禁止**任何工程變更繞過 `deduction_basis` 欄位、直接放寬 `resolvePartialMakeupMinutes()`（或其後繼方法）對常態課程的判斷範圍。**禁止** clamp 回 perSession（沿用原規則，不因 opt-in 而改變）。
  ```

  白話版（供快速理解，非正式規則文字）：常態課程（非補課）的分鐘制扣堂，**只能在該課程 `deduction_basis` 被明確設為 `actual_duration` 時啟用**；未 opt-in 的課程維持「正常課堂一律整堂」不變。
- `resolvePartialMakeupMinutes()` 的擴大方式：新增一個判斷分支——若 `$sc->deduction_basis === 'actual_duration'` 且該 `ClassSession` 實際時長 ≠ `standard_lesson_minutes`，回傳實際分鐘；`fixed_session` 課程（含既有補課機制本身）完全不受影響，兩條路徑並存，不互相覆蓋。
- **Billing-standard immutability（新增，回應本次修訂要求）**：
  - 第一筆 `session_deduction_ledger` 產生**前**，`standard_lesson_minutes`／`deduction_basis` 可透過一般編輯課程流程正常修改。
  - 第一筆 deduction ledger **產生後**，一般 `PUT /student-classes/{id}` 更新流程必須**拒絕**修改 `standard_lesson_minutes`（回 422，附清楚錯誤訊息），避免如 `recomputeCounters()` 現行公式（`SessionCount × perSessionMinutes()` 即時重算）在事後編輯時，**默默改寫已經發生過的歷史購買額度**。
  - **v1 不提供任何 post-deduction 修正管道（本次修訂：撤回先前的 correction command 提案）**。先前版本曾提議一個「具名 command 修改 `standard_lesson_minutes`、但只影響未來」的補救途徑——**該提案不安全，已移除**：目前 entitlement 仍由 `SessionCount × standard_lesson_minutes` 即時推導（`recomputeCounters()`，見 §2.2），沒有 entitlement snapshot、沒有 effective date、沒有 allocation 語意，因此「只影響未來」在現行資料模型下**根本無法成立**——一旦改掉 `standard_lesson_minutes`，`purchased_minutes` 會整份重算，等於追溯重新解釋所有已發生的扣堂事件所依據的契約。宣稱它只影響未來會是一個假保證。
  - 因此：扣堂後若真的需要修正契約，v1 的答案是**停止並升級為獨立的高風險流程**（比照 `docs/GUIDE_RELEASE_EXECUTION_PACKAGE.md` 的 owner-gated 資料修復慣例），必須先定義 entitlement snapshot／effective date／allocation 語意之後才能設計，**不在本 RFC 範圍內**。這也是 §12「停止條件」之一。
  - **是否要把 `purchased_minutes` 從「即時重算」改成「建立時／每次加購時 snapshot 一筆 entitlement grant 事件」**（類似 `session_deduction_ledger` 已經是 event-sourced 的消費端，entitlement 端目前卻不是）——本 RFC 認為這是**更穩健的長期方向**，但**不是** v1 最小 slice 的必要項（v1 用「鎖定欄位 + 具名 command」這個較輕量的守門即可達到「不可默默改寫歷史」的目標）。列為 §14 待決事項，供 Founder 決定要不要在較後期 Phase 導入完整的 entitlement 事件溯源（**見 D7，已拍板 v1 不做**）。

- **A1 snapshot 語意（本次修訂新增，回應 Founder closeout 要求）**：

  ```
  resolved_standard_lesson_minutes =
      explicit course override (StudentClass.standard_lesson_minutes)
      ?? branch default (尚不存在，見 B6／§14 待決)
      ?? system default（尚不存在，見 B6／§14 待決）
  ```

  - 這個解析**只能在建立課程時，或課程明確 opt-in 為 `deduction_basis='actual_duration'` 的當下**執行一次；解析結果**必須持久化**到課程契約（即寫入 `StudentClass.standard_lesson_minutes` 本身，不是每次讀取時重新解析一次 branch/system 預設）。
  - `deduction_basis='actual_duration'` 的課程：
    - `standard_lesson_minutes` **must be non-null**（Phase 2 驗證：opt-in 時若未解析出非 null 值，拒絕 opt-in）。
    - runtime（`SessionDeductionService`／擴大後的判斷方法）**只能讀取已持久化的課程值**，**不得**在扣堂當下動態 fallback 到當時的 branch/system 預設——這正是 `LessonEntitlementCoverageCalculator` 刻意設計成「無預設值、缺值直接 throw」的原因（`backend/app/Services/Scheduling/LessonEntitlementCoverageCalculator.php` 建構子註解），確保 preview 計算與 runtime 扣堂用的都是同一個**已鎖定**的數字，不是一個會隨時間飄移的動態值。
  - **分校/系統預設日後修改，不得影響既有課程**：因為解析結果已經 snapshot 進 `StudentClass.standard_lesson_minutes`，修改 branch/system 預設只影響**之後新建立或新 opt-in** 的課程，不會回頭改變任何已經持久化的課程契約——這與「billing-standard immutability」（鎖定既有課程的 `standard_lesson_minutes`）是同一個防線的兩面：一面防止「事後編輯」，一面防止「系統預設飄移」。
  - 第一筆 deduction event 之後：`standard_lesson_minutes`、`deduction_basis`、購買標準單位數（`SessionCount`）皆**不得**由普通編輯流程修改（後端 authoritative guard 回 422；前端 disabled 只是 UX，不可作為唯一防線）。`deduction_basis` 同樣不得被改成會重新解釋歷史的模式（例如 `actual_duration` → `fixed_session` → 再改回來，藉此繞過鎖定）。**v1 不提供任何具名 correction command 作為例外出口**（理由見上一點）。

- **D5（Founder 已拍板）— overage confirmation（soft block）**：

  當 `scheduled_minutes > entitlement_minutes`（即 `NonstandardDurationCoveragePreviewContract::build()` 回傳 `requires_overage_confirmation=true`，已實作於 `backend/app/Services/Scheduling/NonstandardDurationCoveragePreviewContract.php`，測試見 `backend/tests/Unit/Scheduling/NonstandardDurationCoveragePreviewContractTest.php`），建立課程流程必須 **soft block**：

  ```
  目前排程超出已購額度 120 分鐘。
  [ ] 我已確認超額部分需要續約、加購或後續處理
  ```

  規則（Phase 2 實作時必須遵守，本 PR 只定義 contract 與計算，不接線到任何 production 建立流程）：
  - 未勾選確認方塊 → 不可建立課程（前端阻擋 + 後端驗證雙重把關，比照現有 `settlement_day` 必填的 422 模式）。
  - 勾選後 → 允許建立，即使有 uncovered 部分。
  - 出席／點名永遠不中斷（此規則與扣堂引擎、非本 contract 責任範圍，但列在此處避免 Phase 2 實作者誤解 soft block 的範圍——soft block **只**擋「建立課程」這個動作，不擋「已建立課程的後續點名」）。
  - 不自動跨期分配（見 D3、Cross-period handling）。
  - `uncovered_minutes` 必須可從既有 ledger 推導與暴露（見 Derived values 一節）。

- **D6（Founder 已拍板）— occurrence source：canonical scheduling-horizon input**

  **調查結論（已由程式碼確認，非猜測）**：`UniversalClassScheduler.vue` → `EnrollmentService::store()` 這條路徑，**已經存在**且**已經在使用**一個獨立於「購買堂數」之外的 occurrence-count 來源——`session_plan`（明確的逐筆日期/時間/kind 陣列）。證據：

  ```php
  // backend/app/Services/EnrollmentService.php:128-137
  if (!$isMonthlyRecurring && !empty($sessionPlanRaw) && is_array($sessionPlanRaw)) {
      $sessionRows = $this->buildRowsFromSessionPlan(
          $sessionPlanRaw, $dayTimeSlotGroups, $dayTimeSlotMap, $globalDuration, $startTime, $defaultSubject
      );
  }
  ```

  `session_plan` 對「一般（非月結）課程」（含堂數制）本來就是合法輸入，`buildRowsFromSessionPlan()` 完全不看 `total_classes`／`payment_type` 來決定要展開幾筆——它單純把 `session_plan` 陣列展開成 `$sessionRows`。**問題不是「沒有 canonical input」，而是系統在展開完 `$sessionRows` 之後，又額外用一條等式驗證把它的長度綁死等於 `total_classes`**（`EnrollmentService.php:197-222`，見 §2.5、§3）。

  **拍板**：`session_plan`（逐筆日期＋開始時間＋時長＋kind）即為 `actual_duration` 課程的 canonical scheduling-horizon input——它是**已存在、使用者已經在填、已經是唯一被系統實際拿去 materialize `ClassSession` 的資料**（`buildRowsFromSessionPlan` → 逐列建立 `ClassSession`），不需要新增「結束日期」或「N 週」這種新概念，也不會與既有輸入打架。

  - **符合優先序 1**（沿用現有已存在、使用者已理解的輸入）：`session_plan` 本來就存在於 UI（`UniversalClassScheduler.vue` 的行事曆逐日勾選/時段設定），使用者已經在操作它，只是目前的產品語意把它的長度「順便」拿去跟 `total_classes` 比對相等。
  - **符合優先序 2**（不建立三套互相競爭的 truth）：本 RFC **不**引入「結束日期」或「N 週」作為第二套 occurrence-count 來源；`purchased_standard_units`（entitlement，來自 `total_classes` 或其後繼欄位）與 `scheduled_occurrence_count`（`session_plan` 長度）維持兩個獨立、不互相驗證相等的數字，僅在 Phase 0B coverage preview 中「比較」，不在建立驗證中「強制相等」。
  - **`fixed_session`（未 opt-in）課程完全不受影響**：`EnrollmentService.php:210-222` 的等式驗證**只在 `deduction_basis='actual_duration'` 時移除**，`fixed_session` 課程繼續維持現行「`session_plan`／日期清單長度必須等於 `total_classes`」的驗證，byte-identical。
  - **月結制（`ScheduleMode=date`）課程不在此決策範圍**：月結課程本來就是用 `EndDate`／`course_start_date` 界定範圍（`buildSessionsFromWeeklySchedule`），不經過 `total_classes` 等式驗證這條路徑，`ScheduleMode=date` 課程是否也要支援 `actual_duration` opt-in，留待 Phase 2 實作時依實際需求評估，非本決策的必要前提。
  - **不擅自擴張 ADR-006**：這個決策只解決「單一課程建立當下，occurrence 數量該由誰決定」，**不**啟用 ADR-006 的 rolling horizon／`Ensure`／pool coverage 等機制；`session_plan` 是既有輸入機制的重新賦權（不再被entitlement 相等驗證綁架），不是新的 scheduling commitment 抽象層。

- **D7（Founder 已拍板）— entitlement grant ledger deferred**

  v1 **不**新增 entitlement grant ledger（即不把 `purchased_minutes` 改成「每次加購都寫一筆事件」的 event-sourced 表）。v1 採：

  - persisted standard-minute snapshot（見上方 A1 snapshot 語意）
  - explicit `deduction_basis`（見 D2）
  - 扣堂後 immutability（見 Billing-standard immutability）
  - correction audit evidence（具名 command，見上方）
  - 既有 `session_deduction_ledger` 作為 consumption 端證據（不新增第二套）

  **只有出現以下需求時才重新評估 entitlement grant ledger**（Founder 拍板的觸發條件，非本 RFC 自行認定）：
  - 多次加購 provenance（需要追溯「這個學生的額度是分幾次買的、每次買多少」）
  - 跨期餘額移轉（一旦 D3 的跨期政策未來被拍板為「允許」，勢必需要精確追蹤每筆額度的來源期別）
  - refund／credit adjustment（目前系統完全沒有退款機制，見 §5；若日後補上，entitlement 端很可能需要對應的「credit 事件」）
  - 歷史契約修正（若「具名 command 修正」的頻率高到需要完整稽核鏈，而不只是單筆 audit 記錄）
  - 多來源 entitlement（例如同時有個人購買 + 促銷贈送 + 續約加購，來源不同）
  - attendance 跨多個 entitlement grant 分攤（一次點名可能同時消耗「舊期剩餘」與「新期購買」時，單一 `purchased_minutes` 數字已經不夠精確）

### Derived values（本次修訂：修正「RemainingSessions 已足夠呈現精確 fractional balance」的錯誤敘述）

**修正聲明**：`RemainingSessions = ROUND_HALF_UP(RemainingMinutes / standard_lesson_minutes)` **只能產生整數**，例如 6.5 堂會顯示成 7、0.5 堂會顯示成 1。**不得再把現有 `RemainingSessions` 描述為足以呈現精確 fractional balance**——它從第一版開始就只是一個「顯示用、四捨五入到最近整堂」的衍生值，第一版部分段落的措辭容易讓讀者誤以為它本身就是精確值，此處修正。

**新增／明確化的 derived API 欄位**（分鐘仍是 integer authoritative truth；lesson-equivalent 一律用 decimal string 或明確 precision 表示，禁止 binary float 當帳務真相）：

| 欄位 | 型別 | 定義 | 用途 |
|---|---|---|---|
| `remaining_minutes` | integer | `RemainingMinutes`（已存在，`#613`） | 精確剩餘分鐘，authoritative |
| `remaining_hours` | decimal string，2 位小數（如 `"1.00"`） | `remaining_minutes / 60`，字串格式輸出，避免前端把它當 binary float 累加 | 顯示用 |
| `remaining_lesson_equivalent` | decimal string，2 位小數（如 `"0.50"`） | `remaining_minutes / standard_lesson_minutes`，**精確值**（非 ROUND_HALF_UP 顯示值） | 精確顯示「還剩幾堂」，取代目前容易被誤讀為精確值的 `RemainingSessions` |
| `used_lesson_equivalent` | decimal string，2 位小數 | `(purchased_minutes - remaining_minutes) / standard_lesson_minutes` | 精確顯示「已用幾堂」 |
| `uncovered_minutes` | integer | 見下方定義 | 超額分鐘的 first-class 曝光欄位 |
| `remaining_sessions`（既有欄位） | integer | **暫時保留**供既有前端/報表相容讀取，但**不得**再作為 fractional UX 的唯一來源；文件與 API 說明需註明其為 ROUND_HALF_UP 顯示值，非精確值 | 向後相容 |

**`net_deducted_minutes` 與 `uncovered_minutes` 的推導（本次修訂新增，不新增第二套 ledger）**：

```
net_deducted_minutes = SUM(session_deduction_ledger.minutes WHERE event_type='deduct')
                      − SUM(session_deduction_ledger.minutes WHERE event_type='reverse')
                      （minutes 為 null 時以 standard_lesson_minutes 代入，沿用既有 recomputeCounters() 的既有慣例）

uncovered_minutes = max(0, net_deducted_minutes − purchased_minutes)
```

- 兩者都是**對既有 `session_deduction_ledger` 的聚合查詢**，不需要新表、不需要新欄位存放「超額」本身——`session_deduction_ledger` 已經是完整、可重算的事件記錄。
- `reverse`（含 retro leave、reschedule 產生的還原事件）已經是這條公式的減項，因此請假回滾、調課還原之後，`net_deducted_minutes` 與 `uncovered_minutes` 會**自動**跟著重新算對，不需要額外特殊處理——這與現行 `recomputeCounters()` 已經在用的 `netMinutes` 計算方式（`SessionDeductionService.php` 366-374 行一帶，目前只是算完就丟）在邏輯上是同一件事，本 RFC 只是把它從「用完即丟的區域變數」升格為「持久化、可查詢、可告警的 derived 欄位」。

### UX specification（Part E，production activation 後的畫面行為；本 PR 只定義規格，不接線到任何 production 頁面）

**Fixed-session**：完全維持現有 UX 與行為，不做任何改動（`deduction_basis` 預設值本身就保證這點，見 D2）。

**Actual-duration**（僅 `deduction_basis='actual_duration'` 分支時顯示；由 `NonstandardDurationCoveragePreviewContract::build()` 的輸出直接驅動，見 §10 Derived values、Part D）：

```
標準計費堂長：120 分鐘
購買額度：8 堂／960 分鐘／16 小時
預計排課：6 次／1080 分鐘／18 小時
可完整涵蓋：5 次
第 6 次可涵蓋：60 分鐘
尚缺：120 分鐘／1 堂
```

若 `requires_overage_confirmation=true`（D5）：

```
[ ] 我已確認超額部分需要續約、加購或後續處理
```

未勾選不能提交（前端 disable 提交鈕 + 後端驗證雙重把關，比照現有 `settlement_day` 必填的 422 模式，見 D5）。

**不得**（Phase 2 實作時的硬性邊界，任何「自動化便利」都不可以做）：
- 自動刪除第 6 次（uncovered 的 occurrence 依然照常建立、照常可以點名，只是伴隨 warning）。
- 自動縮短第 6 次（不得把 180 分鐘的排課自動改成 120 分鐘去湊額度——這正是題目一開始要避免的「把非標準時長硬套整堂」）。
- 自動借用下一期（D3：v1 不做任何跨期自動分配）。
- 禁止學生實際到場點名（D5：soft block 只擋「建立課程」這個動作，已建立的課程，點名永遠不中斷）。

此規格所需的十六個回應欄位（`deduction_basis`／`purchased_standard_units`／`standard_lesson_minutes`／`entitlement_minutes`／`scheduled_occurrence_count`／`scheduled_minutes`／`fully_covered_occurrences`／`first_partially_covered_occurrence`／`partial_covered_minutes`／`partial_uncovered_minutes`／`fully_uncovered_occurrences`／`remaining_entitlement_minutes`／`uncovered_minutes`／`remaining_lesson_equivalent`／`uncovered_lesson_equivalent`／`requires_overage_confirmation`）已經**實作為可執行的程式碼並通過測試**（`backend/app/Services/Scheduling/NonstandardDurationCoveragePreviewContract.php`，`backend/tests/Unit/Scheduling/NonstandardDurationCoveragePreviewContractTest.php::test_response_matches_the_exact_dry_run_contract_example` 逐欄位驗證與 Founder 範例數字一致），可直接作為 Phase 2 UI 串接的資料來源（見 §11 Phase 0B、§15）。

### Cross-period handling（D3 已拍板，第一版明確不做的事）

**第一版不實作**：
- 一次點名同時扣兩個 `StudentClass`
- 自動從下一期借用額度
- 負餘額自動轉移
- 跨發票分攤
- 自動 debt settlement

**第一版採用**：
1. 建課時預測 coverage（Phase 0B 預覽，上方規格）。
2. 超額前 warning（操作者在建課或後續排課時看到「即將超出額度」提示，非阻擋）。
3. 點名不中斷（即使已知會超額，點名照常成功，`session_deduction_ledger` 照實記錄）。
4. Ledger 保存完整實際扣除分鐘（不四捨五入、不封頂到「看起來乾淨」的整堂）。
5. 顯示或回報 derived `uncovered_minutes`（見上方定義），供主任儀表板／dashboard 使用。
6. 續約／加購額度分配，留給**後續獨立 workflow**處理（本 RFC 不設計此 workflow，僅確保上游資料——`uncovered_minutes`、ledger 完整記錄——足以支撐後續 workflow 開發）。

原第一版列出的五個跨期選項（借下一期額度／允許負額度／only warning／禁止點名／建議整除方案）**仍然是續約/加購 workflow 階段要回答的問題**，但 D3 已經明確排除「自動化」這個大方向，因此這五個選項的討論範圍縮小為「續約/加購 workflow 要不要提供哪些手動操作」，而不是「扣堂引擎要不要自動做哪一種」——兩者不應混為一談，本 RFC v1 兩者都不實作，只確保資料備妥。

---

## 11. 最小 vertical slice（本次修訂：拆分為 Phase 0A/0B/1/2/3）

**目標**：讓「常態排課、非補課」的課程也能在 explicit opt-in 下走分鐘權威，且對現有 `fixed_session` 課程 byte-identical；同時解決 §2.5 的「購買額度＝occurrence 數量」耦合。

### Phase 0A — production 唯讀盤點（**本 PR 已實作**，不寫 production DB）

**狀態：實作與測試隨附於本工作階段的 Phase 0A 實作 PR（與本文件分開送審，以符合 size gate）**——`App\Services\Scheduling\NonstandardDurationInventoryReporter`（`backend/app/Services/Scheduling/NonstandardDurationInventoryReporter.php`）+ 指令 `sessions:report-nonstandard-duration`（`backend/app/Console/Commands/ReportNonstandardDurationInventory.php`）。

已量化的六項指標（B1–B6，逐一對應本次任務規格）：
- **B1** 正常課程中，`ClassSession` 實際時長與 `SessionDuration` 不一致的數量（排除 `schedules.type='extra'` 補課），依分校/課程分組、依時長差異分桶。
- **B2** 既有 `session_deduction_ledger.minutes` 採用情形：非 null 筆數、`minutes != perSessionMinutes()` 的課程數（真正的「部分時數」採用）、依 source/event_type 分組、reverse 後 net 是否歸零。
- **B3** 既有超額：`net_deducted_minutes − purchased_minutes` 的聚合，受影響課程數、總超額分鐘、最大幾筆案例（僅 `--details`）。
- **B4** `RemainingMinutes` 一致性：持久化值 vs. 由 ledger 重新推導值是否一致、fractional 案例數、「歸零但實際有隱藏超額」案例數。
- **B5** `CoursePackage` 曝險：上述受影響課程中有多少屬於共用包成員（只讀，不觸碰 package 資料）。
- **B6** Mutable-default 風險：已由程式碼盤點確認**目前不存在**任何 branch/system 層級的標準堂長設定（檢查範圍：`App\Models\Campus::$fillable`、`backend/config/*.php`、`StudentClass` schema）——若 Founder 決定在 D1 之上新增分校/系統預設，此發現必須在 Phase 1 實作前重新核對。

安全保證（已測試驗證）：全部 SELECT-only；`backend/tests/Feature/NonstandardDurationInventoryReportTest.php::test_command_is_read_only_and_prints_read_only_marker` 逐表比對執行前後 row count 與目標課程整列內容 byte-identical；`test_course_package_exposure_is_reported_without_touching_package_tables` 額外證明 `course_packages` 表同樣不被觸碰。

### Phase 0B — create-time coverage preview（**本 PR 已實作**，純預覽／validation spec，不改變扣堂結果）

**狀態：實作與測試隨附於本工作階段的 Phase 0B 實作 PR（與本文件分開送審，以符合 size gate）**：
- `App\Services\Scheduling\LessonEntitlementCoverageCalculator`（`backend/app/Services/Scheduling/LessonEntitlementCoverageCalculator.php`）——純 domain calculator，無框架依賴，無 DB 存取，輸入 `purchasedStandardUnits`/`standardLessonMinutes`/`occurrences[]`，輸出 `entitlement_minutes`/`scheduled_minutes`/`fully_covered_occurrences`/`remaining_after_fully_covered`/`first_partially_covered_occurrence`/`partial_covered_minutes`/`partial_uncovered_minutes`/`fully_uncovered_occurrences`/`uncovered_minutes`/`remaining_entitlement_minutes` 等欄位；36 個測試涵蓋必要範例、標準案例、90 分鐘案例（含精確符合題目數字的「剛好打平非部分覆蓋」與「真正部分覆蓋」兩種對照）、混合時長（證明依 sequence 而非平均值計算）、順序敏感性、entitlement 過剩、零值/負值/缺值 fail-fast、occurrence 數量上限、整數溢位偵測、守恆不變式、決定性、無 float。
- `App\Services\Scheduling\NonstandardDurationCoveragePreviewContract`（`backend/app/Services/Scheduling/NonstandardDurationCoveragePreviewContract.php`）——把上述 calculator 包裝成 Part D 指定的 dry-run JSON contract 形狀（含 `deduction_basis`/`requires_overage_confirmation`/`remaining_lesson_equivalent`/`uncovered_lesson_equivalent` 十六個欄位），6 個測試逐欄位比對 Founder 範例數字。

**本 Phase 刻意沒有做的事**（符合 Part B/D 的範圍界線）：
- **沒有** HTTP controller／route——依 RFC Part D 範圍指引，範圍過大時只建立 service contract + tests + spec，本 PR 選擇後者；Phase 2 才會決定要接到哪個既有端點（見下方 Phase 2「create preview integration」）。
- **沒有**啟用任何新的 production create submission 語意——`EnrollmentService::store()`／`ClassSessionController::batchStore()` 完全未被本 PR 觸碰。
- **沒有**讓 UI 顯示新語意但後端仍以舊契約寫入的情形——因為根本沒有 UI 或 controller 被接線。
- **沒有**改變任何扣堂 runtime behavior——`SessionDeductionService` 完全未被本 PR 觸碰。

### Phase 1 — additive contract fields（**計畫，本 PR 不實作**；exact plan 見 §15）

不 globally activate runtime deduction；只新增契約欄位與 immutability 守門。詳細 migration／model／validation／rollback 規格見 §15。

### Phase 2 — duration-aware deduction behind feature flag（**計畫，本 PR 不實作**；exact plan 見 §15）

只對 explicit opt-in 課程啟用；同時處理 §2.5／D6 的耦合解除（`session_plan` 作為 canonical occurrence source，見 §10 D6）與 `CoursePackage` 互斥守門（D4）。詳細規格見 §15。

### Phase 3 — precise balance UX and uncovered warning（**計畫，本 PR 不實作**；exact plan 見 §15）

- API/UI 改用 §10 定義的 `remaining_minutes`/`remaining_lesson_equivalent`/`uncovered_minutes` 等 derived 欄位，**不得只讀 integer `RemainingSessions`** 作為精確餘額顯示。
- 主任儀表板／家長端補上「即將超出額度」告警（依 §10 UX specification 呈現）。
- **自動跨期 allocation 仍為 non-scope**（D3），本 Phase 只做「顯示」與「警示」，不做「分配」或「借用」。

**刻意不做（維持 Non-scope）**：不建立跨期 allocation ledger；不修改 `CoursePackage`／`package_session_ledger`；不降低既有 R59 測試 assertion（改寫規則本身，但既有 `fixed_session` 行為的 golden 測試斷言不變）；不將所有既有課程全域切換為 minutes deduction；不自動修復既有資料。

---

## 12. Migration 與 backward compatibility

- **新增欄位**：`StudentClass.standard_lesson_minutes`（`integer nullable`）、`StudentClass.deduction_basis`（`string`/enum，`default 'fixed_session'`，**not nullable**——確保任何既有資料 migrate 後立刻有明確、安全的預設值，不會出現「未設定＝不確定行為」的灰色地帶）。皆為 additive，`down()` 直接 drop，符合 `docs/RULE_MIGRATION_COMPAT.md` Expand/Contract 慣例。
- **`resolvePartialMakeupMinutes()`（或其後繼方法）擴大範圍，只在 `deduction_basis='actual_duration'` 時生效**——由於預設值為 `fixed_session`，**這使得擴大本身對所有既有資料是零行為變更**，不需要像第一版描述的那樣「必須先跑全庫掃描才能決定要不要用 feature flag」；因為現在的擴大是**明確 opt-in 閘門**（`deduction_basis` 欄位），而不是「符合某個時長不一致條件就自動套用」。Phase 0A 的唯讀盤點仍然建議先做，但目的改為「評估未來有多少課程適合／需要被主動 opt-in」，不是「評估貿然全面套用的風險」——風險已經被 opt-in 設計本身排除。
- **billing-standard immutability**：`standard_lesson_minutes` 在第一筆 `session_deduction_ledger` 產生後鎖定一般編輯路徑（見 §10、§11 Phase 1），需要一個 migration 之外的**應用層守門**（validation guard），不是 schema 層面的約束（DB 層無法輕易表達「這個欄位在某條件成立後唯讀」）。
- **`purchased_minutes` 即時重算 vs. entitlement 事件溯源**：v1 維持「即時重算」但加上不可變性守門（§10），**不**在本次 slice 導入完整的 entitlement grant 事件表——這是明確排除在 v1 之外、留待後續評估的方向（§14）。
- **共用課程包**：D4 已拍板排除，Phase 2 需要應用層驗證守門（`deduction_basis='actual_duration'` 與 `PackageID` 互斥），不在本次 slice 擴大 `package_session_ledger`。
- **§2.5 耦合的移除（`plannedSessions === count($sessionRows)` 等式）**：僅在 `actual_duration` 分支移除此驗證，`fixed_session`（含現有所有課程與所有未 opt-in 的新課程）維持現行等式，零行為變更。
- **舊資料是否已存在小數堂數或異常餘額**：本次調查（含本次修訂）**未執行**任何 production 查詢。此為 Phase 0A 必須先做的唯讀盤點項目，若發現既有異常餘額，依 Stop condition 應立即停止並回報，不可在同一個 PR 裡順手修掉。

---

## 13. 測試計畫

### 13.1 本 PR 已實作、已執行、通過的測試

| 檔案 | 範圍 | 執行方式與結果（見 §16 Evidence appendix） |
|---|---|---|
| `backend/tests/Unit/Scheduling/LessonEntitlementCoverageCalculatorTest.php` | Phase 0B calculator：必要範例、標準案例、90 分鐘（打平 vs 真部分覆蓋兩種）、混合時長 sequence-not-average、順序敏感性、entitlement 過剩、零/負/缺值 fail-fast、occurrence 上限、整數溢位、守恆不變式、決定性、無 float、`lessonEquivalent()` decimal-string 精度 | 本地 `vendor/bin/phpunit` 直接執行（純 PHPUnit，無需 DB），36 tests / 247 assertions 全綠 |
| `backend/tests/Unit/Scheduling/NonstandardDurationCoveragePreviewContractTest.php` | Part D dry-run contract：逐欄位比對 Founder 範例、`requires_overage_confirmation` 各種情境、`deduction_basis` passthrough、決定性、欄位完整性 | 本地執行，6 tests 全綠 |
| `backend/tests/Feature/NonstandardDurationInventorySqliteCompatibilityTest.php` | Phase 0A reporter 核心邏輯（含唯一一段 raw SQL：`whereRaw` CASE WHEN 表達式）在 **SQLite** 下的正確性與唯讀性 | 本地執行（獨立最小 schema，非完整 330-migration 套件），2 tests 全綠 |
| `backend/tests/Feature/NonstandardDurationInventoryReportTest.php` | Phase 0A reporter B1–B6 全部邏輯、campus/student-class 範圍過濾、`--details` 開關、command 唯讀性（含 `course_packages` 表 byte-identical 證明） | 依 repo 慣例走 `RefreshDatabase`（`phpunit.xml` 強制 `DB_CONNECTION=mysql`），本機沙盒無 MySQL 可跑，**待 CI 執行**（見 §16 環境限制說明） |

### 13.2 Phase 1／Phase 2 計畫測試（尚未實作，本 PR 不實作 production behavior）

1. **`DeductionBasisOptInDefaultTest`**：驗證所有既有課程與所有新建課程，migrate／建立後 `deduction_basis` 恆為 `'fixed_session'`，除非請求中明確指定；驗證即使 `standard_lesson_minutes` 被設定為非 null，只要 `deduction_basis` 未被明確改為 `actual_duration`，扣堂行為與 migrate 前 byte-identical（直接反駁「null-sentinel 方案」的風險，佐證方案 (ii) 較安全）。
2. **`RecurringActualDurationDeductionTest`**：`deduction_basis='actual_duration'`、常態排課（無 `schedules.type='extra'`）、`standard_lesson_minutes=120`、`ClassSession` 實際 180 分鐘 → 點名後斷言 `RemainingMinutes` 精確扣 180；`fixed_session` 課程（即使實際時長也是 180）維持整堂扣 1 的對照測試。
3. **`StandardLessonMinutesImmutabilityTest`**：驗證第一筆 ledger 產生前可正常編輯 `standard_lesson_minutes`；產生後一般 `PUT` 更新遭拒（422）；具名 command 修正會寫入 audit 記錄且不追溯改寫既有 ledger 事件所依據的分鐘假設。
4. **`PackageActualDurationExclusionTest`**：驗證 `deduction_basis='actual_duration'` 課程不得設定 `PackageID`，反之亦然（D4 守門）。
5. **既有 golden 測試維持全綠、斷言不變**：`SessionDeductionMinutesEngineTest`、`PartialMakeupDeductionTest`（含 `test_normal_short_session_not_prorated`/`test_normal_longer_session_not_prorated`）——**本次修訂明確**：這兩個測試斷言的「正常課堂一律整堂」在 `fixed_session`（未 opt-in）情境下**繼續成立、不需要修改斷言**；只有在課程被明確 opt-in 為 `actual_duration` 時，新測試（第 2 項）才驗證不同的行為。**不需要翻轉既有斷言**，因為新舊行為分別綁在不同的 `deduction_basis` 上，兩者並存。
6. **繳費提醒回歸**（`TuitionAlertsApiTest`）：確認 `RemainingSessions <= 2` 整數比較邏輯對 `actual_duration` 課程的顯示值（ROUND_HALF_UP 衍生）仍如預期觸發，修改 `AlertController::tuition` 前需先取得使用者明示同意。
7. **`EnrollmentServiceOccurrenceCountDecouplingTest`**：驗證 `actual_duration` 課程可以「購買額度 ≠ `session_plan` 長度」成功建立（不再 422，對應 D6 決策移除 `EnrollmentService.php:210-222` 的等式驗證）；`fixed_session` 課程的既有等式驗證行為不變（仍會 422）。
8. **overage confirmation 驗證測試**（D5）：未勾選確認 → 拒絕建立；勾選 → 允許建立且 `uncovered_minutes` 正確持久化／可查詢。

---

## 14. Founder 必須拍板的決策

**已拍板（D1–D4，第一次修訂納入模型）**：
- ~~是否要打開 `resolvePartialMakeupMinutes()` 範圍到常態課程~~ → **D2**：只能透過 explicit `deduction_basis` opt-in，R59 改寫而非刪除。
- ~~標準堂長是公司常數還是課程層級可設~~ → **D1**：系統/分校預設 + 課程層級可 override，兩者並存，解析後 snapshot 持久化（見 §10 A1）。
- ~~跨期處理採用哪個選項~~ → **D3**：第一版皆不採用，改為預測＋警示，跨期分配留給後續獨立 workflow。
- ~~是否涵蓋共用課程包~~ → **D4**：第一版明確排除。

**已拍板（D5–D7，本次 Phase 0 closeout 納入模型）**：
- ~~第 6 次課程缺口的即時行為~~ → **D5**：`scheduled_minutes > entitlement_minutes` 時 soft block，要求明確確認勾選；勾選後允許建立；點名永遠不中斷；不自動跨期分配；`uncovered_minutes` 必須可推導與暴露。已實作 `requires_overage_confirmation` 計算（`NonstandardDurationCoveragePreviewContract`），Phase 2 才接線到實際建立流程的阻擋邏輯。
- ~~occurrence 數量該由誰決定~~ → **D6**：`session_plan`（既有輸入，已由 `EnrollmentService::store()` 使用）為 canonical scheduling-horizon input；entitlement 只做 coverage 比較，不再反過來決定 occurrence 數量；`fixed_session` 課程維持現行等式驗證不變（見 §10 D6 的完整調查與引用）。
- ~~是否導入 entitlement 事件溯源~~ → **D7**：v1 不做；只有多次加購 provenance／跨期餘額移轉／refund／歷史契約修正／多來源 entitlement／跨 grant 分攤等需求出現時才重新評估（見 §10 D7 完整條件列表）。

**仍待拍板**：

1. **overage warning 的確認強度**：D5 已拍板「soft block + 明確確認勾選」這個大方向，但 UI 細節（例如是否要求每次超額都重新勾選、或整堂課只需勾選一次）留給 Phase 2 UX 實作時依實測反饋微調，非阻塞本 RFC。
2. **Phase 0B 的 occurrence 數量預設策略**：建課時，系統應該預設只排出 `fully_covered_occurrences`（本案例＝5 個），需要操作者主動勾選才能多排到超出額度的 occurrence；還是預設仍照使用者在 `session_plan` 填的原始數字排（例如仍排 6 個），只是多顯示警示文字並要求 D5 確認。本 RFC 建議後者（尊重 D6 的「`session_plan` 才是 canonical occurrence 來源」，不由系統自作主張砍掉使用者已經明確排定的 occurrence），但這是 UX 決策，需 Founder 最終確認。
3. **既有 103 個測試檔硬編碼 `SessionDuration=120` 是否需要系統性抽樣改成多元時長**（技術債層級決定，非本次 slice 必須項）。
4. **`preservedDelta`（G-009）與 `rate_unit='hour'` 自動切換是否要一併重新檢視**（建議排除在本次 slice 之外，待獨立時程處理）。
5. **`docs/AI_REGRESSION_LESSONS.md §R59` 原始「禁止擴大到非 extra」的產品理由**——本次修訂已經知道「答案」（D2：改為 explicit opt-in），但原始設計者當初為何完全不留 opt-in 通道（而是直接寫死禁止）背後的考量仍未見於程式碼或文件，建議記錄進 `AI_REGRESSION_LESSONS.md` 的修訂歷史，避免未來又有人誤以為這條規則「已經考慮過 opt-in 但否決了」。
6. **月結制（`ScheduleMode=date`）課程是否也要支援 `actual_duration` opt-in**：D6 的調查範圍限定在堂數制（`ScheduleMode=count`），月結制課程走完全不同的 occurrence 生成路徑（`buildSessionsFromWeeklySchedule` + `EndDate`），是否需要對應的 opt-in 支援，留待 Phase 2 依實際需求評估。
7. **分校/系統標準堂長預設值的實際設定介面**：D1 批准「系統/分校可設預設值」，但 Phase 0A 盤點（B6）已確認**目前不存在**任何此類設定介面/欄位——Phase 1 若要落地 D1 的「分校預設」部分，需要 Founder 決定這個設定要放在哪裡（新增 `Campus` 欄位？新設定表？還是先只做「系統常數」，分校層級留待更後期）。

---

## 15. 建議 implementation sequence（Part F：可直接執行的 Phase 1／Phase 2 計畫）

**本節只寫計畫，本 PR 不實作以下任何一項 production behavior。** 每個 Phase 皆為 additive migration + feature flag／opt-in 欄位，可獨立回滾，符合 Non-scope「不改變現有扣堂結果」的要求。Phase 0A/0B 已於本 PR 實作完成，見 §11、§13.1。

### Phase 1 計畫：additive contract fields

**Exact migrations**（皆 additive、nullable 或有安全預設值，`down()` 直接 drop，符合 `docs/RULE_MIGRATION_COMPAT.md`）：

1. `database/migrations/YYYY_MM_DD_HHMMSS_add_standard_lesson_minutes_to_student_class.php`
   ```php
   Schema::table('StudentClass', function (Blueprint $table) {
       $table->integer('standard_lesson_minutes')->nullable()->after('SessionDuration');
   });
   ```
2. `database/migrations/YYYY_MM_DD_HHMMSS_add_deduction_basis_to_student_class.php`
   ```php
   Schema::table('StudentClass', function (Blueprint $table) {
       $table->string('deduction_basis', 32)->default('fixed_session')->after('standard_lesson_minutes');
   });
   ```
3. `database/migrations/YYYY_MM_DD_HHMMSS_create_standard_lesson_minutes_corrections.php`（audit 表，供具名 correction command 寫入）
   ```php
   Schema::create('standard_lesson_minutes_corrections', function (Blueprint $table) {
       $table->id();
       $table->unsignedBigInteger('student_class_id')->index();
       $table->integer('old_standard_lesson_minutes')->nullable();
       $table->integer('new_standard_lesson_minutes');
       $table->string('old_deduction_basis', 32)->nullable();
       $table->string('new_deduction_basis', 32)->nullable();
       $table->unsignedBigInteger('operator_user_id');
       $table->string('reason', 512);
       $table->timestamps();
   });
   ```

**Model 變更**（`app/Models/StudentClass.php`）：
- `$fillable` 新增 `'standard_lesson_minutes'`, `'deduction_basis'`。
- 新增常數 `const DEDUCTION_BASIS_FIXED_SESSION = 'fixed_session';` / `const DEDUCTION_BASIS_ACTUAL_DURATION = 'actual_duration';`。
- 新增方法 `isActualDurationBasis(): bool { return $this->deduction_basis === self::DEDUCTION_BASIS_ACTUAL_DURATION; }`。
- **不修改** `perSessionMinutes()`——它繼續讀 `SessionDuration`（排課用途不變）；新增獨立方法 `resolvedStandardLessonMinutes(): ?int { return $this->standard_lesson_minutes; }`（**故意不 fallback**——沒有值時回 `null`，由呼叫端決定要不要拒絕 opt-in，禁止在這裡塞入任何 60/120 之類的 mutable default，呼應 §10 A1「runtime 不得動態 fallback」）。

**Validation 規則**（`StudentClassController`／`ClassSessionController` 相關 Form 驗證新增，不修改既有規則）：
- `'standard_lesson_minutes' => 'nullable|integer|min:1'`（建立/編輯時可帶入；不得為 0 或負數）。
- `'deduction_basis' => 'nullable|in:fixed_session,actual_duration'`（省略時預設 `fixed_session`，由 DB 欄位 `default` 保證，不需應用層再補預設值）。

**Default resolution owner**：Phase 1 階段，`resolved_standard_lesson_minutes` 的解析只有兩層（`explicit course override ?? null`）——因為 Phase 0A（B6）已確認**不存在**任何 branch/system 層級設定，Phase 1 **不**新增分校/系統預設機制（那屬於 §14 待決事項 7，需 Founder 先決定設定介面長怎樣才能實作）。Opt-in 為 `actual_duration` 但 `standard_lesson_minutes` 為 `null` 時，驗證層直接拒絕（422：「啟用分鐘制扣堂前必須先設定標準堂長」），不 fallback。

**Snapshot 寫入路徑**：新增課程／編輯課程流程（`EnrollmentService::store()`、`StudentClassController::update()`）在寫入 `standard_lesson_minutes`／`deduction_basis` 時，直接寫入使用者輸入值（Phase 1 沒有系統/分校預設可解析，所以「snapshot」在這個 Phase 就是「原樣寫入使用者輸入」）；欄位一旦有值即視為「已 snapshot」，往後只受 immutability 規則保護，不再重新解析。

**Edit lock 規則**（應用層守門，非 schema 層——DB 無法表達「條件式唯讀」）：
- 新增 `App\Services\Scheduling\StandardLessonMinutesLockGuard`（或類似命名）：`assertEditable(StudentClass $sc): void`，邏輯：
  ```php
  $hasDeduction = SessionDeductionLedger::where('student_class_id', $sc->ID)->exists();
  if ($hasDeduction && ($request->has('standard_lesson_minutes') || $request->has('deduction_basis'))) {
      throw new HttpResponseException(response()->json([
          'message' => '此課程已有扣堂紀錄，標準堂長／扣堂模式不可由一般編輯流程修改，請使用具名修正指令',
      ], 422));
  }
  ```
- 呼叫點：`StudentClassController::update()`（在 `mapFrontendPayload()` 之後、`$studentClass->update()` 之前插入這個檢查）。

**具名 correction command contract**：
- `php artisan student-class:correct-standard-lesson-minutes {studentClassId} {newMinutes} --reason="..." --operator={userId}`（`app/Console/Commands/CorrectStandardLessonMinutes.php`）。
- 邏輯：讀舊值 → 寫一筆 `standard_lesson_minutes_corrections` → 更新 `StudentClass.standard_lesson_minutes`（**只改這一個欄位**，不觸碰 `session_deduction_ledger`、不呼叫 `recomputeCounters()` 去追溯改寫歷史）→ 印出 audit 記錄摘要。
- **不建 HTTP 端點**（Phase 1 只需要後台可執行的指令，避免过早暴露成一般使用者可觸及的 API surface；若 Founder 之後要主任自助修正，屬於 Phase 2+ 的 UI 決策）。

**Audit storage**：`standard_lesson_minutes_corrections` 表（見上方 migration），保留 operator/reason/old/new/timestamp，永久保留不清除（比照現有 `LeaveMakeupEvidenceCloseout` 等既有 audit 慣例）。

**Backward compatibility**：`deduction_basis` DB 層 `default('fixed_session')` 確保既有資料 migrate 後立即安全；`standard_lesson_minutes` 為 `nullable`，未設定不影響任何既有讀寫路徑（`perSessionMinutes()`／`SessionDuration` 完全不變）。

**Rollback behavior**：兩個新欄位與新表皆可用標準 `down()` migration 移除；因為 Phase 1 不改變任何 runtime 扣堂邏輯，回滾風險等同「移除兩個從未被讀取的欄位」，無資料遺失疑慮（`standard_lesson_minutes_corrections` 若已有資料，`down()` 前需先確認是否要保留為離線備份，比照既有 audit 表回滾慣例）。

**Tests**：見 §13.2 第 1、3 項（`DeductionBasisOptInDefaultTest`、`StandardLessonMinutesImmutabilityTest`）。

**Changed files estimate**：2 個新 migration（欄位）+ 1 個新 migration（audit 表）+ `StudentClass.php`（model，~15 行）+ `StudentClassController.php`（validation + lock guard 呼叫，~20 行）+ `EnrollmentService.php`（寫入兩個新欄位，~10 行）+ 1 個新 Command（~40 行）+ 1 個新 lock guard class（~20 行）+ 2 個新測試檔。預估總計 **約 8 個檔案變更/新增，< 250 行程式碼**。

### Phase 2 計畫：duration-aware deduction behind feature flag

**Feature flag owner 與預設值**：比照既有 `config/perfflags.php` 慣例（`backend/config/perfflags.php`，例如 `feedback_push_enabled` 的 dark-launch 模式），新增：
```php
// config/perfflags.php
'actual_duration_deduction_enabled' => (bool) env('PERF_ACTUAL_DURATION_DEDUCTION', false),
```
**擁有者**：工程團隊（比照現有 perfflags 慣例，非個別課程層級的 flag——這是「這個功能在這個環境是否存在」的總開關，`deduction_basis` 才是「這門課要不要用」的課程層級開關，兩者職責分離：flag 關閉時，即使某門課的 `deduction_basis='actual_duration'`，扣堂引擎仍必須表現得像 `fixed_session`（fail-safe，不是 fail-open）**。

**Actual-duration opt-in activation path**：
1. Flag 關閉（預設）：`resolvePartialMakeupMinutes()`（或其後繼方法）完全不檢查 `deduction_basis`，行為與 Phase 1 前 byte-identical。
2. Flag 開啟 + 課程 `deduction_basis='fixed_session'`（預設/多數課程）：行為不變。
3. Flag 開啟 + 課程 `deduction_basis='actual_duration'`：啟用比例扣堂（見下方 SessionDeductionService 變更）。

**`SessionDeductionService` exact changes**（`backend/app/Services/SessionDeductionService.php`）：
- `resolvePartialMakeupMinutes()`（現行 L463-499）**更名**為 `resolvePartialDeductionMinutes()`（舊名字暗示「僅限補課」，擴大後誤導，比照 `TD-060` 「死碼未接線」教訓，改名以防後續開發者誤解範圍）；新增判斷分支：
  ```php
  private static function resolvePartialDeductionMinutes(StudentClass $sc, int $classSessionId): ?int
  {
      if (config('perfflags.actual_duration_deduction_enabled') && $sc->isActualDurationBasis()) {
          $standard = $sc->resolvedStandardLessonMinutes();
          if ($standard === null) {
              return null; // 不應發生（Phase 1 opt-in 驗證已擋），fail-safe 視為整堂
          }
          $cs = ClassSession::query()->find($classSessionId);
          if (!$cs || empty($cs->StartTime) || empty($cs->EndTime)) {
              return null;
          }
          $mins = self::durationMinutes($cs->StartTime, $cs->EndTime);
          return ($mins > 0 && $mins !== $standard) ? $mins : null;
      }

      // 既有補課邏輯完全不變，作為 fixed_session 課程（含未 opt-in 的既有課程）的唯一路徑。
      return self::resolveLegacyPartialMakeupMinutes($sc, $classSessionId); // 現行邏輯原樣搬到這個私有方法
  }
  ```
- `perSessionMinutes()` 呼叫點（`recomputeCounters()` 內）**不變**——`actual_duration` 課程的「標準堂長」改讀 `resolvedStandardLessonMinutes()` 而非 `SessionDuration`，需要在 `recomputeCounters()` 內加一個條件分支：`$perSession = $sc->isActualDurationBasis() ? ($sc->resolvedStandardLessonMinutes() ?? $sc->perSessionMinutes()) : $sc->perSessionMinutes();`。
- **不改動** `deductForSession()`／`reverseForSession()`／`roundHalfUp()`——這些方法已經是通用的分鐘制機制（見第一版 RFC §2.2 引用），`#613 A1` 打的地基原樣沿用，只是觸發判斷式擴大。

**Occurrence-count validation decoupling**（D6，`backend/app/Services/EnrollmentService.php:197-222`）：
```php
$isSessionMode = ($data['payment_type'] ?? 'session') === 'session';
$plannedSessions = $this->resolvePlannedSessions($data, count($sessionRows));
if ($plannedSessions <= 0) { /* 422，維持不變 */ }

$isActualDurationOptIn = config('perfflags.actual_duration_deduction_enabled')
    && ($data['deduction_basis'] ?? 'fixed_session') === 'actual_duration';
if (!$isActualDurationOptIn && $plannedSessions !== count($sessionRows)) {
    // 422，僅 fixed_session 課程維持現行等式驗證，byte-identical
}
// actual_duration 課程：不再要求 $plannedSessions === count($sessionRows)，
// $plannedSessions 只作為 purchased_standard_units 寫入 StudentClass.SessionCount，
// count($sessionRows)（即 session_plan 長度）才是 scheduled_occurrence_count。
```

**`CoursePackage` mutual-exclusion guard**（D4）：`StudentClassController`／`EnrollmentService` 建立與編輯路徑新增檢查：
```php
if (($data['deduction_basis'] ?? 'fixed_session') === 'actual_duration' && !empty($data['PackageID'])) {
    return response()->json(['message' => '非標準時長（actual_duration）課程不得加入共用課程包'], 422);
}
```
且編輯路徑：已是 `actual_duration` 的課程，若請求要設定 `PackageID`，同樣 422；已在 package 中的課程，若請求把 `deduction_basis` 改成 `actual_duration`，同樣 422。

**Create preview integration**：Phase 2 決定把 `NonstandardDurationCoveragePreviewContract::build()`（本 PR 已實作）接到哪個既有端點——建議新增 `POST /api/v1/class-sessions/batch/preview`（**唯讀**、與 `batchStore()` 的寫入 handler 完全分開的 controller method，禁止共用 handler，呼應 Part D 的「不得與 production create endpoint 共用會寫入的 handler」），或做成 `batchStore()` 接受 `?dry_run=true` 參數時提前 return preview（兩者皆可，Founder/工程 Phase 2 時再定，本 RFC 只要求「必須是唯讀分支，不得與寫入路徑共用同一個會 side-effect 的程式路徑」）。

**Overage confirmation validation**（D5）：`ClassSessionController::batchStore()` 新增驗證——當 preview 算出 `requires_overage_confirmation=true` 時，請求必須帶 `overage_confirmed=true`，否則 422（訊息：「目前排程超出已購額度，請先確認」）。

**Precise API balance fields**：`StudentClassController::index()` 等讀取端點，`actual_duration` 課程回傳新增 `remaining_lesson_equivalent`/`uncovered_lesson_equivalent`/`uncovered_minutes`（沿用 `LessonEntitlementCoverageCalculator::lessonEquivalent()`，不重新發明公式）；`fixed_session` 課程回傳不變。

**Uncovered-minute calculation**：讀取端新增 `net_deducted_minutes`/`uncovered_minutes` 查詢（沿用 §10 Derived values 定義的公式，對既有 `session_deduction_ledger` 聚合，不新增表）。

**所有 attendance entry points 影響**（沿用第一版 RFC §5 已列出的 9 個入口，Phase 2 必須逐一確認每個入口呼叫 `deductOnAttendance`/`deductForSession` 時，`resolvePartialDeductionMinutes()` 的新分支都會被正確觸發，因為判斷邏輯集中在該方法內，理論上 9 個入口不需要individually修改，但**必須逐一寫回歸測試**證明）：`SwipeRfidController.php:254-257,446`、`AttendanceController.php:605-608,793-799,1169`、`ApprovalSessionSyncService.php:81`、`EnrollmentService.php:1587-1594`、`ClassSessionController.php:908-909,1699`。

**reverse／leave／reschedule 行為**：`reverseForSession()` 現有「沖回對應 deduct 記錄的 minutes」機制（第一版 RFC 已引用 `SessionDeductionService.php:255-265`）**不需要修改**——它已經是通用機制，`actual_duration` 課程的部分時數扣除一樣會被正確沖回。

**Fixed-session golden tests**：Phase 2 PR 必須包含至少一個測試，證明「開啟 feature flag 後，`fixed_session` 課程（含所有既有課程）的扣堂結果與 flag 關閉時 byte-identical」——這是 Phase 2 合併的硬性前提，不可省略。

**Rollout sequence**：(1) flag 預設關閉合併 → (2) 內部/測試環境開啟驗證 → (3) 選定 1-2 個分校的 1-2 門課程明確 opt-in 驗證 → (4) 依 Phase 0A 盤點結果決定要不要繼續擴大 opt-in 範圍。

**Rollback**：關閉 feature flag 即完全復原（`deduction_basis='actual_duration'` 的課程會 fail-safe 走 `fixed_session` 行為，見上方 activation path 第 1 點）；不需要 migration rollback。

**Production verification evidence**：Phase 2 上線後，比照 `sessions:report-nonstandard-duration`（本 PR 已實作，Phase 0A）的 B2/B3/B4 指標，作為上線後驗證用的既有工具——不需要為 Phase 2 另外寫一套新報表。

**Stop conditions**（Phase 2 實作中若遇到，立即停止並回報，不得繞過）：Phase 0A 盤點發現任何既有課程已存在無法解釋的異常餘額；`fixed_session` golden test 在 flag 開啟後出現任何差異；`CoursePackage` 互斥守門發現無法安全排除的既有共用包成員。

### Phase 3 計畫（precise balance UX）：見 §11 Phase 3、§10 UX specification，無需額外展開；主要工作是前端串接既有（Phase 2 已提供的）API 欄位，不涉及新的後端計算邏輯。

### 後續、非本次 slice

續約/加購 workflow——依 §14 待決事項 2 決定範圍後另行設計；entitlement 事件溯源（若 Founder 決定要做，見 D7 觸發條件）；共用課程包涵蓋（若 Founder 未來決定推翻 D4，需另評估 `package_session_ledger` 的 `minutes` 欄位擴充，比照 `session_deduction_ledger.minutes` 的既有做法）；月結制 `actual_duration` 支援（§14 待決事項 6）；分校/系統標準堂長預設設定介面（§14 待決事項 7）。

---

## 16. Evidence appendix

**三種不同的 SHA（依 Founder 要求分開標示，避免把調查基準碼、文件 commit、程式碼變更混為一談）**：

| 項目 | SHA / 說明 |
|---|---|
| **Code baseline SHA**（所有既有 production 檔案——`EnrollmentService.php`／`SessionDeductionService.php`／`StudentClassController.php` 等——的引用版本；本次 PR **未修改**任何一個既有 production 檔案，此 SHA 依然是這些引用的有效基準） | `66788a8701886c110c11a339ae6eddb2099c3903` |
| **第一版調查報告 commit SHA** | `1aaa7f7`（`docs(architecture): investigate non-standard session duration billing`） |
| **第一次修訂（D1-D4）commit SHA** | `e3be666`（`docs(architecture): amend non-standard duration RFC per Founder D1-D4`） |
| **本次 Phase 0 closeout 前的 branch head SHA** | `e3be666`（與上列相同） |
| **本次 Phase 0 closeout 的 commit SHA** | 見 `git log -1 --format=%H -- docs/architecture/RFC_NONSTANDARD_SESSION_DURATION_BILLING.md`（本次 PR push 後產生；本文件不自我引用尚未產生的 hash） |

**本次 Phase 0 closeout 新增的檔案（皆為新增，非修改既有 production 檔案）**：
- `backend/app/Services/Scheduling/LessonEntitlementCoverageCalculator.php`（Phase 0B 純 domain calculator）
- `backend/tests/Unit/Scheduling/LessonEntitlementCoverageCalculatorTest.php`（36 tests）
- `backend/app/Services/Scheduling/NonstandardDurationCoveragePreviewContract.php`（Part D dry-run contract）
- `backend/tests/Unit/Scheduling/NonstandardDurationCoveragePreviewContractTest.php`（6 tests）
- `backend/app/Services/Scheduling/NonstandardDurationInventoryReporter.php`（Phase 0A reporter，B1–B6）
- `backend/app/Console/Commands/ReportNonstandardDurationInventory.php`（`sessions:report-nonstandard-duration` 指令）
- `backend/tests/Feature/NonstandardDurationInventoryReportTest.php`（mysql/`RefreshDatabase` 慣例，12 tests，待 CI 執行）
- `backend/tests/Feature/NonstandardDurationInventorySqliteCompatibilityTest.php`（獨立 sqlite 相容性證明，2 tests，本機已執行通過）
- `docs/architecture/RFC_NONSTANDARD_SESSION_DURATION_BILLING.md`（本檔，本次大幅擴充 §10 A1/D5/D6/D7、UX specification、§11 狀態更新、§13 測試分類、§14 決策更新、§15 Phase 1/2 詳細計畫）
- `docs/INDEX.md`（導覽更新，見下方）

**新增引用的關鍵既有檔案／行號（本次 closeout 新增查證，佐證 D6 決策）**：
- `backend/app/Services/EnrollmentService.php:100-330`（`sessionRows` 展開、`resolvePlannedSessions()` 呼叫、第 128-137 行 `buildRowsFromSessionPlan` 對 `session_plan` 的既有支援、第 197-222 行的等式驗證、第 668-671 行 `groupSessionCount`/`chargeUnits`）
- `backend/app/Services/EnrollmentService.php:958-972`（`resolvePlannedSessions()` 定義，`total_classes` 直接作為 `plannedSessions`）
- `backend/app/Models/Campus.php`（`$fillable`，佐證 B6「無 branch 層級標準堂長設定」）
- `backend/config/perfflags.php`（既有 feature-flag 慣例，佐證 Phase 2 計畫的 flag 設計依據）
- `SessionDeductionService.php` 366-376 行一帶（`netMinutes` 為區域變數、用完即丟，未持久化，佐證 uncovered_minutes 修正敘述）

**第一版／第一次修訂沿用的關鍵檔案／測試／文件引用**（維持原列表，未因本次 closeout 而失效）：
- `backend/app/Models/StudentClass.php`（`perSessionMinutes()` L120-126、`resolveSessionDurationForWeekday()` L97-113、`$fillable` L14-28）
- `backend/app/Services/SessionDeductionService.php`（`recomputeCounters()` L301-408、`deductForSession()` L196-232、`reverseForSession()` L238-283、`resolvePartialMakeupMinutes()` L463-499、`roundHalfUp()` L521-527）
- `backend/app/Services/PackageDeductionService.php`（`deductForSession()` L20-59）
- `backend/app/Models/CoursePackage.php`（`computeRemainingFromLedger()`、`recomputeCounters()`）
- `backend/app/Http/Controllers/StudentClassController.php`（L260-420、L1180-1330、L1490-1545、L3440-3568、L5190-5387）
- `backend/app/Http/Controllers/ClassSessionController.php`（`batchStore()` 驗證規則）
- `backend/app/Services/ClassSessionMaterializationService.php`（`upsertSlot()`）
- `backend/app/Console/Commands/BackfillMissingClassSessionsFromSchedules.php`
- `backend/app/Services/ForwardSessionGenerator.php`
- `frontend/src/components/UniversalClassScheduler.vue`
- `frontend/src/lib/universalSchedulerApi.js`
- Migrations（第一版列出的 9 個檔案，維持不變）

**既有測試**（第一版列出的測試檔案清單，維持不變，本次未修改任何一個既有測試檔）：
`SessionDeductionMinutesEngineTest.php`、`PartialMakeupDeductionTest.php`、`MinutesBalanceFoundationTest.php`、`AttendanceRemainingSessionsRegressionTest.php`、`RateUnitChargeCalculationTest.php`、`ScheduleLeaveCascadeTest.php`、`PackageTotalSessionsSyncTest.php`、ADR-006 測試群。

**本次新增測試**：見 §13.1（已執行）與 §13.2（Phase 1/2 計畫，尚未實作）。

**文件**（維持第一版列表）：`docs/PRICING_CONTRACT.md`、`docs/ADR_006_prepaid_session_horizon_and_commitment.md`、`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`、`docs/TECH_DEBT.md`（`TD-059`、`TD-060`）、`docs/AI_REGRESSION_LESSONS.md`（`§R59`、`§R76`、`§R77`）、`docs/SYSTEM_TECH_GUIDE.md`（§5）、`CLAUDE.md`（G-009、G-010）。

**執行的指令（本次 Phase 0 closeout）**：
- 程式碼調查（唯讀）：`git log`、`git rev-parse HEAD`、`git status`，多次 `Read`/`Grep` 對 `EnrollmentService.php`（第 100-330、958-972 行）、`Campus.php`、`config/perfflags.php`、既有 Scheduling 測試慣例（`PrepaidHorizonPhase0ReportTest.php`、`SessionCoverageDomainTest.php` 等，用於確立本次新檔案的命名/風格慣例）。
- 環境建置（本機沙盒無 MySQL，`vendor/` 原先不存在）：`composer install --prefer-source`（首次因 composer 對 `phpstan/phpstan` 的 GitHub API zipball 下載在此沙盒的代理環境下持續 401/認證失敗而失敗；為了讓本機能執行任何 PHPUnit 測試，**暫時**從本機的 `composer.json`/`composer.lock` 移除 `phpstan/phpstan` 系列套件以完成 `vendor/` 安裝，完成測試驗證後已用 `git checkout -- composer.json composer.lock` 還原，**未提交**任何 composer 檔案變更——即最終 diff 不含 composer.json/composer.lock 改動）。
- 測試執行（唯讀，未寫入任何資料庫或 production 環境）：
  - `vendor/bin/phpunit --testsuite Unit --filter LessonEntitlementCoverageCalculatorTest` → 36 tests / 247 assertions，OK。
  - `vendor/bin/phpunit --testsuite Unit --filter Scheduling` → 53 tests / 299 assertions，OK（含既有 11 個 `Scheduling` 命名空間測試，證明無回歸）。
  - `vendor/bin/phpunit --filter NonstandardDurationInventorySqliteCompatibilityTest` → 2 tests / 8 assertions，OK。
  - `vendor/bin/phpunit --testsuite Unit`（全量）→ 111 tests，99 通過、12 個因本機沙盒無 MySQL 而連線失敗（`SQLSTATE[HY000] [2002] Connection refused`）——**這 12 個失敗與本次改動無關，是既有測試對 `Tests\TestCase`（會在 `setUp()` 探測 `Subject` 表）的既有依賴**，在有 MySQL 的 CI 環境中應可正常執行；本次新增的所有測試皆不受此限制影響（要嘛是純 unit 不碰 DB，要嘛是獨立 sqlite harness）。
  - `php -l` 對本次新增的 6 個 PHP 檔案全數通過（語法檢查）。
- `backend/tests/Feature/NonstandardDurationInventoryReportTest.php`（mysql/`RefreshDatabase` 慣例）**未在本機執行**（無 MySQL），**待 PR CI 執行**——見下方風險說明。

**尚未確認的假設 / 本次 closeout 範圍外（新增/延續）**：
1. 家長端（App/LIFF）與主任端報表是否直接讀 `remaining_sessions` 或另有快取／投影邏輯——延續自第一版，未逐一走查。
2. 現有生產資料庫是否已存在「`RemainingMinutes` 非整堂倍數但未被標記為補課」的既有課程——延續自第一版，**仍未**執行任何 production 查詢（Non-scope 要求）；本次已實作的 `sessions:report-nonstandard-duration`（B3/B4）正是為了在 Founder 授權後補齊這項唯讀盤點，但本 PR 本身**未對 production 執行過**此指令。
3. `§R59`「禁止擴大到非 extra」背後除了「D2 現在決定用 opt-in」之外，原始設計者當初完全不留 opt-in 通道的理由——延續自第一版，未見於程式碼或文件，列入 §14 待決事項 5。
4. Phase 0B 的「occurrence 數量預設策略」（§14 待決事項 2）尚未拍板，本 RFC 只給出建議方向（尊重 `session_plan` 為 canonical，不自動砍occurrence），非最終決定。
5. 是否導入完整 entitlement 事件溯源取代「即時重算 `purchased_minutes`」——**D7 已拍板 v1 不做**，僅在 §10 D7 列出的六項觸發條件出現時才重新評估。
6. Case 2 路徑「連續多堂超扣、非單次超扣」的行為，在 `uncovered_minutes` 定義下的具體數值——本次已給出計算公式並在 `LessonEntitlementCoverageCalculatorTest`（純 domain 層級）驗證過序列填充邏輯，但**尚未有 Feature 層級測試**驗證 `NonstandardDurationInventoryReporter::buildExistingOverage()` 對「連續多筆 deduct ledger」的彙總是否與 domain calculator 的序列填充邏輯完全一致（兩者目前是獨立實作：calculator 用於「建立前」的 occurrence-by-occurrence 序列填充，reporter 用於「既有資料」的 ledger SUM 聚合，兩者數學上應該收斂到同一個 `uncovered_minutes` 定義，但本 PR 沒有寫交叉驗證測試證明兩者在同一組資料上算出相同數字）——建議列入 §13.2 新增第 9 項測試（`CalculatorReporterCrossValidationTest`），優先於 Phase 1 開工前補齊，確保「preview 用的公式」與「既有資料盤點用的公式」不會意外分裂成兩套定義。
7. **本機無法完整跑 `NonstandardDurationInventoryReportTest.php`（mysql）**——已用獨立 sqlite harness（`NonstandardDurationInventorySqliteCompatibilityTest.php`）驗證 reporter 的核心邏輯（含唯一一段 raw SQL）正確，但 mysql 版本的完整 12 個測試案例（B1–B6 全部情境、campus 過濾、`--details`、read-only 證明）**尚待 PR 的 CI pipeline 實際執行確認**，本報告不能宣稱這些測試「已通過」，只能宣稱「已撰寫、語法正確、邏輯已透過等價的 sqlite harness 交叉驗證」。
