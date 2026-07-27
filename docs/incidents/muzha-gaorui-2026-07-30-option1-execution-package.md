# Execution Package：木柵 高瑞樸 2026-07-30 19:00–21:00 Option 1 containment

> **狀態**：`OWNER_APPROVED` — 等待有 Pi production 權限者執行  
> **日期校正**：今天語境可為 2026-07-28（**星期二**）；要補的是 **星期四 2026-07-30** 19:00–21:00  
> **關聯**：PR #1466 · B1 [`muzha-gaorui-2026-07-30-monthly-thursday-b1.md`](muzha-gaorui-2026-07-30-monthly-thursday-b1.md)  
> **Outcome**：production 恰好一筆有效該時段 `ClassSession`，老師工作台可見、出缺勤可點名  
> **非目標**：不改月結 UX、不改 Alert／invoice／扣堂、不 renew、不新建第二筆 `StudentClass`

---

## 0. 為何只重新整理不會好

- 畫面沒有 isProjected 堂次 → 前端不會呼叫 `ensure-projected`。
- 後端 `ensureProjected`：若 `session_date > EndDate` → **「堂次日期超過課程到期日」**（422）。
- 老師當日自動物化也會略過超過 `EndDate` 的日期。

因此必須：**延長 EndDate 涵蓋 2026-07-30** + **`ClassSessionMaterializationService::upsertSlot()`** 寫入唯一一筆。

---

## 1. 欄位／查詢校正（執行前必讀）

| 實體 | 正確欄位 | 常見錯用 |
|------|----------|----------|
| `Student` | `id`, `name` | ~~`ID`~~ / ~~`Name`~~ |
| `User`（老師；`StudentClass.TeacherID` = `User.id`，G-001） | `Name` | ~~`Teacher.Name`~~（Teacher 表是 `T_Name`，查老師請用 **User.Name**） |
| `StudentClass` | `ID`, `StudentID`, `TeacherID`, … | — |
| 科目 | `SubjectID` → Subject／BaseData「課程」Val；比對含 **數學**／**Math** | — |
| 週四 | ISO weekday **4**；`week`／`week1`…`week6` 之一 = 4，對應 `time*` 為 19:00 | — |

---

## 2. Backup（寫入前必做）

```bash
cd /home/admin/backend
TS=$(date '+%Y-%m-%d_%H%M%S')
mysqldump -h 127.0.0.1 -u admin -p"$(grep DB_PASSWORD .env | cut -d= -f2)" \
  --single-transaction AllTrue StudentClass ClassSession schedules \
  | gzip > /home/admin/backups/emergency/db_pre_muzha_gaorui_730_${TS}.sql.gz
ls -lh /home/admin/backups/emergency/db_pre_muzha_gaorui_730_${TS}.sql.gz
```

---

## 3. 單支腳本：唯讀守衛 →（通過才）transaction 寫入 → 驗證

⛔ 禁止：`php artisan test`／`RefreshDatabase`／`config:clear`／renew／任意改 Rate／Charge／Paid／settlement_day／invoice。

將下列整段貼上執行。任一守衛失敗會 `ABORT` 並 rollback（不 force）。

```bash
cd /home/admin/backend
php artisan tinker --execute='
use Illuminate\Support\Facades\DB;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\User;
use App\Models\Campus;
use App\Services\ClassSessionMaterializationService;

$TARGET_DATE = "2026-07-30";
$START = "19:00";
$END = "21:00";
$NOTE = "incident-repair-muzha-gaorui-2026-07-30";

$out = ["phase" => "start", "aborts" => [], "before" => [], "write" => null, "after" => []];

try {
  $campusIds = Campus::query()->where("name", "like", "%木柵%")->pluck("id")->map(fn ($id) => (int) $id)->all();
  if (count($campusIds) !== 1) {
    throw new RuntimeException("ABORT_CAMPUS_NOT_UNIQUE count=".count($campusIds)." ids=".json_encode($campusIds));
  }
  $campusId = $campusIds[0];
  $out["campus_id"] = $campusId;

  $students = Student::query()
    ->where("name", "like", "%高瑞樸%")
    ->where("CampusID", $campusId)
    ->get(["id", "name", "CampusID"]);
  if ($students->count() !== 1) {
    throw new RuntimeException("ABORT_STUDENT_NOT_UNIQUE count=".$students->count()." ".$students->toJson(JSON_UNESCAPED_UNICODE));
  }
  $student = $students->first();
  $out["student"] = $student->toArray();

  $teachers = User::query()->where("Name", "like", "%楊智超%")->get(["id", "Name", "type", "status"]);
  if ($teachers->count() !== 1) {
    throw new RuntimeException("ABORT_TEACHER_NOT_UNIQUE count=".$teachers->count()." ".$teachers->toJson(JSON_UNESCAPED_UNICODE));
  }
  $teacher = $teachers->first();
  $out["teacher"] = $teacher->toArray();

  $mathIds = DB::table("BaseData")->where("Name", "課程")
    ->where(function ($q) {
      $q->where("Val", "like", "%數學%")->orWhere("Val", "like", "%Math%");
    })->pluck("id")->map(fn ($id) => (int) $id)->all();
  if (empty($mathIds) && class_exists(\App\Models\Subject::class)) {
    $mathIds = \App\Models\Subject::query()
      ->where(function ($q) {
        $q->where("name", "like", "%數學%")->orWhere("name", "like", "%Math%");
      })->pluck("id")->map(fn ($id) => (int) $id)->all();
  }
  if (empty($mathIds)) {
    throw new RuntimeException("ABORT_MATH_SUBJECT_IDS_EMPTY");
  }
  $out["math_subject_ids"] = $mathIds;

  $classes = StudentClass::query()
    ->where("StudentID", (int) $student->id)
    ->where("TeacherID", (int) $teacher->id)
    ->where("ScheduleMode", "date")
    ->when(!empty($mathIds), fn ($q) => $q->whereIn("SubjectID", $mathIds))
    ->orderBy("ID")
    ->get();

  $matches = [];
  foreach ($classes as $sc) {
    $slots = [
      [(int) ($sc->week ?? 0), substr((string) ($sc->time ?? ""), 0, 5)],
      [(int) ($sc->week1 ?? 0), substr((string) ($sc->time1 ?? ""), 0, 5)],
      [(int) ($sc->week2 ?? 0), substr((string) ($sc->time2 ?? ""), 0, 5)],
      [(int) ($sc->week3 ?? 0), substr((string) ($sc->time3 ?? ""), 0, 5)],
      [(int) ($sc->week4 ?? 0), substr((string) ($sc->time4 ?? ""), 0, 5)],
      [(int) ($sc->week5 ?? 0), substr((string) ($sc->time5 ?? ""), 0, 5)],
      [(int) ($sc->week6 ?? 0), substr((string) ($sc->time6 ?? ""), 0, 5)],
    ];
    $hit = false;
    foreach ($slots as [$wd, $tm]) {
      if ($wd === 4 && $tm === $START) { $hit = true; break; }
    }
    if ($hit) { $matches[] = $sc; }
  }

  if (count($matches) !== 1) {
    $brief = collect($matches)->map(fn ($sc) => [
      "ID" => (int) $sc->ID,
      "StartDate" => $sc->StartDate,
      "EndDate" => $sc->EndDate,
      "Stop" => (int) ($sc->Stop ?? 0),
      "SubjectID" => (int) ($sc->SubjectID ?? 0),
      "week" => $sc->week,
      "time" => $sc->time,
    ])->values();
    throw new RuntimeException("ABORT_STUDENT_CLASS_NOT_UNIQUE count=".count($matches)." ".$brief->toJson(JSON_UNESCAPED_UNICODE));
  }

  $sc = $matches[0];
  $scId = (int) $sc->ID;
  $out["before"]["student_class"] = [
    "ID" => $scId,
    "StudentID" => (int) $sc->StudentID,
    "TeacherID" => (int) $sc->TeacherID,
    "SubjectID" => (int) ($sc->SubjectID ?? 0),
    "StartDate" => $sc->StartDate,
    "EndDate" => $sc->EndDate,
    "Stop" => (int) ($sc->Stop ?? 0),
    "Paid" => (int) ($sc->Paid ?? 0),
    "settlement_day" => $sc->settlement_day,
    "monthly_sessions" => $sc->monthly_sessions,
    "Charge" => $sc->Charge,
    "closed_reason" => $sc->closed_reason,
    "settlement_locked_at" => $sc->settlement_locked_at,
  ];

  if ((int) ($sc->Stop ?? 0) === 1) {
    throw new RuntimeException("ABORT_COURSE_STOPPED sc=".$scId);
  }
  if ($sc->isUsageSettlementLocked()) {
    throw new RuntimeException("ABORT_SETTLEMENT_LOCKED sc=".$scId);
  }

  $existingCs = ClassSession::query()
    ->where("StudentClassID", $scId)
    ->whereDate("SessionDate", $TARGET_DATE)
    ->whereRaw("SUBSTRING(StartTime,1,5) = ?", [$START])
    ->orderBy("id")
    ->get(["id","StudentClassID","SessionDate","StartTime","EndTime","Status","Note","SubjectID"]);
  $out["before"]["class_sessions_same_slot"] = $existingCs->toArray();

  $activeSame = $existingCs->filter(fn ($r) => strtolower((string) $r->Status) !== "cancelled");
  if ($activeSame->count() > 1) {
    throw new RuntimeException("ABORT_MULTIPLE_ACTIVE_SAME_SLOT ".$activeSame->toJson(JSON_UNESCAPED_UNICODE));
  }
  if ($activeSame->count() === 1) {
    $one = $activeSame->first();
    if (strtolower((string) $one->Status) === "scheduled"
        && substr((string) $one->EndTime, 0, 5) === $END) {
      $out["phase"] = "ALREADY_CONTAINED";
      $out["write"] = ["noop" => true, "session_id" => (int) $one->id];
      echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
      return;
    }
    throw new RuntimeException("ABORT_EXISTING_NON_SCHEDULED_OR_BAD_END ".$one->toJson(JSON_UNESCAPED_UNICODE));
  }

  $cancelledSame = $existingCs->filter(fn ($r) => strtolower((string) $r->Status) === "cancelled");
  // upsertSlot 若撞到 cancelled 會直接回傳舊列且不新建；若有 cancelled 改走「復原 Status」路徑

  $schedEx = Schedule::query()
    ->where("student_course_id", $scId)
    ->whereDate("schedule_date", $TARGET_DATE)
    ->get();
  $out["before"]["schedule_rows_on_date"] = $schedEx->toArray();
  $badSched = $schedEx->filter(function ($r) {
    $st = strtolower((string) ($r->status ?? ""));
    return in_array($st, ["leave", "rescheduled", "cancelled"], true)
      || (substr((string) ($r->start_time ?? ""), 0, 5) === "19:00" && $st !== "scheduled");
  });
  if ($badSched->isNotEmpty()) {
    throw new RuntimeException("ABORT_SCHEDULE_EXCEPTION ".$badSched->toJson(JSON_UNESCAPED_UNICODE));
  }

  // 學生／老師同日 19:00–21:00 其他有效堂次衝突
  $studentScIds = StudentClass::query()->where("StudentID", (int) $student->id)->pluck("ID");
  $studentConflicts = ClassSession::query()
    ->whereIn("StudentClassID", $studentScIds)
    ->whereDate("SessionDate", $TARGET_DATE)
    ->whereRaw("SUBSTRING(StartTime,1,5) = ?", [$START])
    ->whereRaw("LOWER(Status) <> ?", ["cancelled"])
    ->where("StudentClassID", "!=", $scId)
    ->get(["id","StudentClassID","SessionDate","StartTime","EndTime","Status"]);
  if ($studentConflicts->isNotEmpty()) {
    throw new RuntimeException("ABORT_STUDENT_SLOT_CONFLICT ".$studentConflicts->toJson(JSON_UNESCAPED_UNICODE));
  }

  $teacherScIds = StudentClass::query()->where("TeacherID", (int) $teacher->id)->pluck("ID");
  $teacherConflicts = ClassSession::query()
    ->whereIn("StudentClassID", $teacherScIds)
    ->whereDate("SessionDate", $TARGET_DATE)
    ->whereRaw("SUBSTRING(StartTime,1,5) = ?", [$START])
    ->whereRaw("LOWER(Status) <> ?", ["cancelled"])
    ->where("StudentClassID", "!=", $scId)
    ->get(["id","StudentClassID","SessionDate","StartTime","EndTime","Status"]);
  if ($teacherConflicts->isNotEmpty()) {
    throw new RuntimeException("ABORT_TEACHER_SLOT_CONFLICT ".$teacherConflicts->toJson(JSON_UNESCAPED_UNICODE));
  }

  $endDate = $sc->EndDate ? substr((string) $sc->EndDate, 0, 10) : null;
  $needExtend = ($endDate === null || $endDate < $TARGET_DATE);

  $writeResult = DB::transaction(function () use ($sc, $scId, $TARGET_DATE, $START, $END, $NOTE, $needExtend, $cancelledSame) {
    $locked = StudentClass::query()->where("ID", $scId)->lockForUpdate()->first();
    if (!$locked || (int) ($locked->Stop ?? 0) === 1 || $locked->isUsageSettlementLocked()) {
      throw new RuntimeException("ABORT_LOCK_STATE_CHANGED");
    }

    $endBefore = $locked->EndDate ? substr((string) $locked->EndDate, 0, 10) : null;
    $extended = false;
    if ($endBefore === null || $endBefore < $TARGET_DATE) {
      $locked->EndDate = $TARGET_DATE;
      $locked->save();
      $extended = true;
    }

    if ($cancelledSame->isNotEmpty()) {
      $row = ClassSession::query()->where("id", (int) $cancelledSame->first()->id)->lockForUpdate()->first();
      $row->Status = "scheduled";
      $row->EndTime = $END.":00";
      $row->StartTime = $START.":00";
      $row->SubjectID = $locked->SubjectID;
      $row->Note = $NOTE;
      if (\Illuminate\Support\Facades\Schema::hasColumn("ClassSession", "IsContractException")) {
        $row->IsContractException = 0;
      }
      $row->save();
      return [
        "end_date_extended" => $extended,
        "end_date_before" => $endBefore,
        "end_date_after" => substr((string) $locked->EndDate, 0, 10),
        "path" => "restore_cancelled",
        "session_id" => (int) $row->id,
        "created" => false,
      ];
    }

    $svc = app(ClassSessionMaterializationService::class);
    $result = $svc->upsertSlot([
      "StudentClassID" => $scId,
      "SubjectID" => (int) $locked->SubjectID,
      "SessionDate" => $TARGET_DATE,
      "StartTime" => $START,
      "EndTime" => $END,
      "Status" => "scheduled",
      "Note" => $NOTE,
      "IsContractException" => 0,
      "_student_class" => $locked,
    ]);
    $session = $result["session"];
    if (strtolower((string) $session->Status) !== "scheduled") {
      throw new RuntimeException("ABORT_UPSERT_RETURNED_NON_SCHEDULED id=".$session->id." status=".$session->Status);
    }
    return [
      "end_date_extended" => $extended,
      "end_date_before" => $endBefore,
      "end_date_after" => substr((string) $locked->fresh()->EndDate, 0, 10),
      "path" => "upsertSlot",
      "session_id" => (int) $session->id,
      "created" => (bool) $result["created"],
    ];
  });

  $out["write"] = $writeResult;

  $afterSc = StudentClass::query()->where("ID", $scId)->first([
    "ID","StudentID","TeacherID","SubjectID","StartDate","EndDate","Stop","Paid","Charge","settlement_day","monthly_sessions"
  ]);
  $afterCs = ClassSession::query()
    ->where("StudentClassID", $scId)
    ->whereDate("SessionDate", $TARGET_DATE)
    ->whereRaw("SUBSTRING(StartTime,1,5) = ?", [$START])
    ->whereRaw("LOWER(Status) <> ?", ["cancelled"])
    ->get(["id","StudentClassID","SessionDate","StartTime","EndTime","Status","Note","SubjectID","IsContractException"]);

  $out["after"]["student_class"] = $afterSc ? $afterSc->toArray() : null;
  $out["after"]["active_class_sessions"] = $afterCs->toArray();
  $out["after"]["active_count"] = $afterCs->count();
  $out["after"]["ok_unique_scheduled"] = (
    $afterCs->count() === 1
    && strtolower((string) $afterCs->first()->Status) === "scheduled"
    && substr((string) $afterCs->first()->StartTime, 0, 5) === $START
    && substr((string) $afterCs->first()->EndTime, 0, 5) === $END
    && (int) $afterCs->first()->StudentClassID === $scId
    && (int) $afterSc->StudentID === (int) $student->id
    && (int) $afterSc->TeacherID === (int) $teacher->id
  );
  $out["phase"] = $out["after"]["ok_unique_scheduled"] ? "CONTAINED" : "VERIFY_FAILED";

  echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
} catch (Throwable $e) {
  $out["phase"] = "ABORTED";
  $out["aborts"][] = $e->getMessage();
  echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
  exit(1);
}
'
```

> 註：腳本內 `Schema::hasTable("Subject")` 若 tinker 未 import，改為 `\Illuminate\Support\Facades\Schema::hasTable(...)`。下面「精簡修正版」已用完整 facade。

若上面因 `Schema` 未 import 失敗，用此替代開頭 import 區塊並把兩處 `Schema::` 改成 `\Illuminate\Support\Facades\Schema::`（建議直接用完整名）。

---

## 4. 寫入後人工／API 驗證（執行者勾選）

在 `phase=CONTAINED` 且 `ok_unique_scheduled=true` 後：

- [ ] 課程管理：高瑞樸該月結課可見 7/30 19:00 chip  
- [ ] 楊智超老師工作台：可見該堂  
- [ ] 出缺勤：可點名（勿真的亂點扣堂；確認列存在即可）  
- [ ] 無第二筆同 slot  
- [ ] 未改 Rate／Charge／Paid／invoice  

把完整 JSON stdout（before／write／after）貼回 **PR #1466**，並把本檔＋B1 狀態改為 **`CONTAINED`**。

---

## 5. Rollback

```bash
# 用 §2 的 mysqldump 還原 StudentClass / ClassSession / schedules（勿 migrate:fresh）
gunzip -c /home/admin/backups/emergency/db_pre_muzha_gaorui_730_<TS>.sql.gz \
  | mysql -h 127.0.0.1 -u admin -p"$(grep DB_PASSWORD /home/admin/backend/.env | cut -d= -f2)" AllTrue
```

或手動：把 `EndDate` 改回 before 值；將 Note=`incident-repair-muzha-gaorui-2026-07-30` 的該筆 `ClassSession` 設 `Status=cancelled`（勿 DELETE，除非 dump 還原）。

---

## 6. 變更紀錄

| 日期 | 內容 |
|------|------|
| 2026-07-28 | Owner 批准 Option 1；本 execution package 建立（本聊天環境無 Pi，無法代執行寫入） |
