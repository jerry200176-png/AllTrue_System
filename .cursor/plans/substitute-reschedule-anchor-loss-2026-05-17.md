# Bug Fix Plan：substitute_with_reschedule 鏈式操作下 `original_schedule_id` 留 NULL

> 對應 GitHub issue：#364（priority:p1, bug）  
> 對應 in-app bug：#108（reporter Jerry/木柵, in_progress）、#95（reporter 蔡佳蓉/石牌, in_progress）  
> 階段：B1 偵查（已完成）→ **B2 最小範圍修復（本檔，等使用者批准）**  
> 風險層級：T3 safety-critical（堂數扣除／排課／代課三角邏輯）

---

## 0. 根因（B1 結論）

代課邏輯在「**先做純 reschedule、之後再追加 substitute**」的鏈式流程下，新建的 `scheduled` row 會把 `original_schedule_id` 寫成 `NULL`，造成兩個下游問題：

| 後果 | 觸發點 | 檔案 |
|---|---|---|
| A. SmartCalendar 週檢視找不到代課錨點 → 把學生卡留在原合約老師欄位 | `mergeWeekCalendarOccurrences` 用 `original_schedule_id` 找 anchor | `frontend/src/lib/calendarOccurrenceMerge.js` |
| B. SubstituteTeacherPickerModal 把幽靈 row 算成代課老師當下排課 → 真實有空也誤標 `已滿/衝堂` | `SubstituteService::collectTeacherBusySlotsWithCapacity` 直接讀 `schedules WHERE teacher_id=X AND status=scheduled` | `backend/app/Services/SubstituteService.php` |

完整觸發鏈與 SQL 證據在 GitHub #364 已留言（不重複貼）。

---

## 1. 範圍

### 1.1 修復目標（FR）

- FR-1：當 `substitute` 對象 session 已有 `rescheduled` 例外（先前 pure reschedule 留下），新建的 `scheduled` row 必須將 `original_schedule_id` 指向**現存** `rescheduled` row 的 id，而不是 NULL。
- FR-2：偵測同一 `student_course_id + schedule_date + start_time` 出現多筆 `rescheduled` row（例：#2649 + #2651）時，substitute 路徑只把最新／合法那筆視為 anchor，其他需被收斂並紀錄事件以便事後審計。
- FR-3：對既有壞資料（5/16 已寫入的 #2652 / #2651 等）提供一次性收斂腳本（migration 或 artisan command），不可在 substitute 邏輯熱路徑做隱式重寫。

### 1.2 不在範圍

- 不重構 `SmartCalendar` 顯示邏輯（修完寫入路徑後，calendar overlay 會自動恢復；§G-007 規則維持）
- 不改 `SubstituteService::collectTeacherBusySlotsWithCapacity` 的 capacity 算法（修完 anchor 後，吳艾潼這筆會被 `whereNotExists` 子查詢正確排除）
- 不動分校隔離、不動 LearningRecord、不動 ClassSession `cancelled-duplicate-reschedule-placeholder` 邏輯
- 不改 substitute_with_reschedule 的「狀態機本身」（不重新設計）；只補 anchor 寫入這一個原子缺口

---

## 2. RACI

| 角色 | 負責 |
|---|---|
| Responsible | DEV（按本計畫實作） |
| Accountable | 使用者 / Tech Lead |
| Consulted | 既有 SubstituteWithRescheduleTest 設計者（透過 git blame） |
| Informed | 受影響老師（鄭翔祐／鄒宇旻／石牌相關 reporter） |

---

## 3. Test Plan（先寫，CI RED）

### 3.1 RED 測試（覆蓋 #2652 NULL anchor 場景）

新檔：`backend/tests/Feature/SubstituteAfterRescheduleAnchorTest.php`

```
test_substitute_after_pure_reschedule_keeps_original_schedule_id_anchor()
  Given 一名學生在 5/17 15:00-17:00 有 ClassSession + StudentClass（合約老師 A）
  And  先做 pure reschedule：5/17 15:00 → 5/17 10:00（schedules 寫 #1 rescheduled@15:00 teacher=A）
  When 之後追加 substitute：將該 session 的代課老師指定為 B
  Then schedules 多了一筆 #2 scheduled@10:00 teacher=B
   And #2.original_schedule_id == #1.id（**不可為 NULL**）
   And 不會多出第三筆「重複的 rescheduled@15:00 teacher=B」幽靈 row

test_calendar_picker_after_chain_does_not_double_count_target_student()
  Given 上述狀態
  When 再次呼叫 GET /teachers/B/availability?date=5/17
  Then busy_slots 在 10:00-12:00 的 student_count 應與 B 真實在排課數一致
   And 該 slot 的 remaining_capacity > 0（若 class_type=one_on_two 且僅該名學生）

test_substitute_picker_excludes_target_session_from_capacity_count()
  Given 教師 B 在 10:00-12:00 已是另一名學生 X 的代課（capacity=2，已 1 人）
  When 嘗試把學生 Y 也代課給 B 在同一時段（同一 class_type）
  Then picker 應回 remaining_capacity=1（B 還能再收 1 名），允許選 B
```

### 3.2 既有測試保護（防回歸）

跑齊：`SubstituteWithRescheduleTest`、`SubstituteRescheduleRegressionTest`、`SubstituteReschedulesCombinationTest`、`AvailabilityCapacityTest`、`ClassSessionsTeacherVisibilityAfterSubstituteTest`、`ClassSessionsSubstituteStartTimeFormatTest`。

CI 必須在 RED 測試先紅、修完才綠。

---

## 4. 實作（DEV，等使用者批准 B2 後再開始）

### 4.1 寫入端修補

**檔案 1**：`backend/app/Http/Controllers/SubstituteController.php`（或 `app/Services/SubstituteService.php` 的 mutating method，以下統稱「substitute 寫入路徑」）

修補位置：建立 substitute `scheduled` row 之前，先 lookup 同 `student_course_id + schedule_date + start_time` 的既存 `rescheduled` row：

```php
$anchor = Schedule::where('student_course_id', $courseId)
    ->whereDate('schedule_date', $newDate)
    ->where('status', 'rescheduled')
    ->orderByDesc('id')
    ->first();

$originalScheduleId = $anchor?->id; // 若 null，走「無前置 reschedule」分支（既有行為）
```

並在新建 `scheduled` row 時：

```php
Schedule::create([
    // ...既有欄位...
    'original_schedule_id' => $originalScheduleId, // 不再硬寫 NULL
]);
```

### 4.2 鏈式 anchor 偵測

若 substitute 路徑發現自己即將建立的 `scheduled` row 對應到一個「已有 anchor」的時段（例：之前 pure reschedule 已寫 #2649），只 reuse anchor，**不另建新 rescheduled row**（避免 #2651 ghost）。

### 4.3 一次性壞資料收斂

新增 artisan command：`php artisan schedules:backfill-substitute-anchors --dry-run`

掃 schedules 找出 `status=scheduled AND original_schedule_id IS NULL` 而對應 `StudentClass.TeacherID != schedules.teacher_id` 的記錄（亦即「實際是代課但沒 anchor」），用同 `student_course_id + schedule_date` 的 `rescheduled` row 補。先用 `--dry-run` 列清單給使用者確認，再以 `--apply` 寫入。

> ⛔ 違反 R5：此 artisan command 不可在 dev 自動執行。`deploy.yml` **不會** 自動 run，必須使用者手動下令。

### 4.4 防再犯 invariant

substitute 寫入路徑加 final assertion：若新 `scheduled` row 的 `original_schedule_id` 仍為 NULL **且** `teacher_id !== StudentClass.TeacherID`（亦即真的是代課但沒 anchor），記錄 `Log::warning` + 寫 `bug_reports` 自動單，方便人工調查。不要靜默通過。

---

## 5. NFR

- 效能：substitute 寫入新增 1 次 `SELECT` 查詢（同表，已 index 在 `student_course_id`），可忽略。
- 一致性：寫入路徑仍走 `DB::transaction`，不增加長 transaction 風險。
- 可觀測性：所有「補了 anchor」與「invariant violation」事件都進 `Log::info`／`Log::warning`，便於 grep。

---

## 6. 上線維運

1. PR 必須含 RED test 證明問題、含 GREEN diff 證明修復。
2. CI 必須跑 `Vite Frontend Build`（calendar 顯示邏輯不變，但保險）+ `PHPUnit Feature & Unit Tests`（含 §3.2 全部既有測試）。
3. 部署後跑 `php artisan schedules:backfill-substitute-anchors --dry-run` 取得壞資料清單；先給使用者看，使用者批准後再 `--apply`。
4. 5/17 受影響學生（吳艾潼、陳嘉軒在 5/17 10:00 鄭翔祐 化學一對二）由系統 backfill 自動修補；不需人工手動拉 schedule。

---

## 7. 風險

| 風險 | 緩解 |
|---|---|
| 補 anchor 時誤把不相關 rescheduled row 當 anchor（例：同學生同日多筆 rescheduled） | anchor lookup 加 `start_time` / `end_time` 比對；多筆候選時取 `MAX(id)` 並紀錄事件 |
| Backfill artisan command 跑歪損壞 5446 筆 ClassSession | 1) 只動 schedules，不動 ClassSession  2) `--dry-run` 為預設，必須顯式 `--apply`  3) 跑前自動 `mysqldump` schedules 表 |
| 既有測試對「substitute 後 anchor 應為 NULL」做了錯誤假設 | 6 個既有測試名單必須逐一檢查 fixture，必要時更新（同 PR） |
| Calendar 顯示在 chained 場景下出現新 edge case | 改完先以 `npm run test:calendar` 重跑，確認 occurrence merge 規則仍成立 |

---

## 8. 優先級

P1（多名使用者已被影響：Jerry／吳艾潼家／陳嘉軒家、蔡佳蓉/石牌；屬核心代課流程）。建議下一輪 dev cycle 第一順位。

不今天動 production code 的理由：

1. core scheduling/billing 三角邏輯，沒有完整測試覆蓋的修改高風險（§事故 C/E 模式）。
2. 需要使用者先 review 本計畫第 4 節寫入端修補方向，避免猜錯狀態機。
3. 需要使用者批准第 4.3 一次性壞資料收斂的執行時機（最好不在 5/17 上課時間做）。

---

## 9. DoD（AI 可驗證）

- [ ] PR 包含 §3.1 三組新 RED test（提交前 CI 紅）
- [ ] §3.2 六組既有測試 CI 綠
- [ ] 修補後 in-app bug #108、#95 用同樣輸入不再進入 NULL anchor 分支
- [ ] `php artisan schedules:backfill-substitute-anchors --dry-run` 列出的清單與 §0 表格一致；`--apply` 後 #2652 / #2651 等資料的 anchor 正確
- [ ] 5/17 鄭翔祐 10:00-12:00 化學一對二 calendar 顯示陳嘉軒 + 吳艾潼 兩位，無重複卡
- [ ] in-app bug #108 / #95 / #107（無關）狀態正確；GitHub #364 close

---

## 10. 等使用者批准的問題

1. 5/17 既有壞資料是否在工作日內 backfill？還是排到當晚 10pm 後？
2. 是否同意 `Log::warning` 在 substitute 寫入發現 NULL anchor invariant violation 時自動建立 `bug_reports` 自動單？
3. 是否同意 substitute_with_reschedule UI 在資料不一致時直接 disable 「換代課老師」按鈕並顯示提示「此堂次資料異常，請聯絡系統管理員」？

---

> **下一步**：使用者批准本計畫 → 開新 branch `fix/substitute-anchor-loss` → 寫 RED test → push → CI red → 改 production code → CI green → PR → merge → backfill artisan dry-run → 使用者批准 → apply → close issues。
