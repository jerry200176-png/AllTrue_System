# B1：木柵 高瑞樸 × 楊智超 × 週四 19:00–21:00 數學 — 2026-07-30

> **狀態**：B1 偵查中 — **真正 blocker = Pi 唯讀 stdout**（寫完本文件 ≠ 進度完成）  
> **事件語境**：2026-07-27；實際日曆可為 2026-07-28（不影響判斷）  
> **核心事實**：2026-07-30 = 2026-07 第 **5** 個週四  
> **分校**：木柵（前端 CampusID **16**；查庫以 `Campus`／`Student.CampusID` 核對）  
> **禁止**：INSERT／UPDATE／DELETE／`save`／`create`／`upsert`／ensure-projected／`php artisan test`／`RefreshDatabase`／`config:clear`／任何 production write，直到單筆 repair plan 經 owner 批准

---

## 成功條件（本案唯一 Outcome）

讓 production 中 **2026-07-30 19:00–21:00「高瑞樸 × 楊智超 × 數學」** 成為：

1. **恰好一筆**有效 `ClassSession`（非 cancelled 幽靈；無第二筆重複）；
2. `StudentClassID` 指向正確的高瑞樸數學**月結**課；
3. 該課 `TeacherID` 為楊智超；
4. **老師工作台**可見；
5. **出缺勤**可點名；
6. 無舊期 `scheduled` 殘留、無額外帳務或扣堂變更。

產品契約整理、Option 2 UX、月結 domain redesign **不是**本次成功條件；僅在單筆修好後再決定是否需要。

---

## 最短路徑（現在）

1. **跑下方唯一一支 production 唯讀指令** → 判斷為何沒有這堂／為何看不到。  
2. 依輸出分類 → 寫 **最小單筆 repair plan**（等人批准）。  
3. 修後驗證老師工作台 + 出缺勤。  
4. 再決定要不要系統性 UX（Option 2）。

**不要**先做 Option 2；**不要**把「B1 文件寫完」當完成。

---

## Code-side 已鎖定（防亂修，非根因定案）

1. `buildSessionsFromWeeklySchedule()` **不是「只排四週」**；`EndDate >= 2026-07-30` 且 weekday=週四 → 7/30 應進候選集合。  
2. 既有 Feature Test 含「每週四、EndDate=2026-07-31」→ 期間含 7/30；**非** generic builder limitation。  
3. 入口有 recurring vs explicit `session_plan` 分歧 + 前端預設 `monthly_sessions=4` → 系統性促成因素（H5），但**不能**在無 Pi 輸出時當成單筆根因。  
4. 單筆最可能仍是 **H1／H2／H6**；物化缺口才是 **H3**。

---

## Production 唯讀（一支指令查到底）

> **欄位注意**：`Student` 表／Model 使用 `id`、`name`（小寫），**不是** `ID`／`Name`。  
> `StudentClass` 主鍵仍為 `ID`；`ClassSession.StudentClassID` 對應之。

在 Pi 執行後**只貼回完整 stdout**，不做任何修復：

```bash
cd /home/admin/backend
php artisan tinker --execute='
$students = \App\Models\Student::query()
    ->where("name", "like", "%高瑞樸%")
    ->get(["id", "name", "CampusID"]);
echo "STUDENTS\n";
echo $students->toJson(JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
foreach ($students as $student) {
    echo "\n=== STUDENT {$student->id} {$student->name} CAMPUS {$student->CampusID} ===\n";
    $classes = \App\Models\StudentClass::query()
        ->where("StudentID", $student->id)
        ->where("ScheduleMode", "date")
        ->orderBy("ID")
        ->get([
            "ID",
            "StudentID",
            "TeacherID",
            "SubjectID",
            "StartDate",
            "EndDate",
            "Stop",
            "Paid",
            "settlement_day",
            "monthly_sessions",
            "SessionCount",
            "Charge",
            "closed_reason",
            "week",
            "time",
            "week1",
            "time1",
            "week2",
            "time2",
            "week3",
            "time3",
            "week4",
            "time4",
            "week5",
            "time5",
            "week6",
            "time6"
        ]);
    echo "STUDENT_CLASSES\n";
    echo $classes->toJson(JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    $classIds = $classes->pluck("ID")->map(fn ($id) => (int) $id)->all();
    if (empty($classIds)) {
        echo "NO_MONTHLY_STUDENT_CLASSES\n";
        continue;
    }
    $sessions = \App\Models\ClassSession::query()
        ->whereIn("StudentClassID", $classIds)
        ->whereBetween("SessionDate", ["2026-07-01", "2026-07-31"])
        ->orderBy("SessionDate")
        ->orderBy("StartTime")
        ->get([
            "id",
            "StudentClassID",
            "SessionDate",
            "StartTime",
            "EndTime",
            "Status",
            "Note"
        ]);
    echo "JULY_CLASS_SESSIONS\n";
    echo $sessions->toJson(JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    $target = $sessions->filter(function ($row) {
        return substr((string) $row->SessionDate, 0, 10) === "2026-07-30"
            && substr((string) $row->StartTime, 0, 5) === "19:00";
    })->values();
    echo "TARGET_2026_07_30_1900\n";
    echo $target->toJson(JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    $schedules = \App\Models\Schedule::query()
        ->whereIn("student_course_id", $classIds)
        ->whereBetween("schedule_date", ["2026-07-01", "2026-07-31"])
        ->orderBy("schedule_date")
        ->orderBy("start_time")
        ->get();
    echo "JULY_SCHEDULE_EXCEPTIONS\n";
    echo $schedules->toJson(JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}
'
```

### 依輸出分類（再寫 repair plan，不現場改庫）

| 輸出特徵 | 分類 | 下一步 |
|----------|------|--------|
| `EndDate < 2026-07-30` | 期間邊界（H1） | 確認是否已有下一期、會否重疊 → 單筆 repair plan |
| `EndDate >= 2026-07-30`、固定週四 19:00，但 `TARGET` 空 | 物化缺口（H3） | repair **只補該堂**，不重建整月 |
| 已有 7/30 CS，Status=`cancelled`／`rescheduled` | workflow 狀態 | 修狀態，**不**新增第二筆 |
| 同學生或同老師時段已有另一筆 | conflict（H4） | 先處理 conflict，不 force create |
| 存在一筆 `scheduled` 7/30 19:00，但 UI 看不到 | 讀取／scope／calendar | **不**改排課資料；轉查 API／TeacherHome／Attendance |

若 `Stop=1` 且新期 `StartDate > 2026-07-30` → 續期空窗（H2）。

---

## 定義落差（防亂修備註，非成功條件）

| 項目 | interim 立場 |
|------|----------------|
| 期間 | `StartDate`／`EndDate` 仍是權威邊界 |
| `monthly_sessions` | 建議 = session rows 的 derived snapshot；非預付堂數、非上限 |
| `settlement_day` | 催繳日；不參與生成；**本案不改 AlertController** |
| 跨月 | 暫不 hard reject；另案 ADR |
| Option 2 UX | 單筆修好後再評估 |
| Option 4 日曆月帳單 | **拒絕**混進本案 |

---

## 變更紀錄

| 日期 | 內容 |
|------|------|
| 2026-07-27／28 | 初稿：code-side 排除「只排四週」 |
| 2026-07-28 | **修正**：Outcome 收斂為單堂可見可點名；§4 改為單支唯讀（`Student.name`／`id`）；刪除需手貼 `{SC_IDS}` 的分段指令 |
