# B1：木柵 高瑞樸 × 楊智超 × 週四 19:00–21:00 數學 — 2026-07-30 排不進

> **狀態**：B1 偵查中 — **Awaiting production read-only evidence**  
> **禁止**：任何 production write（延長 EndDate／補物化／改 invoice／改 AlertController）在 owner 批准 + repair plan 之前  
> **事件語境日期**：2026-07-27（實際日曆可為 2026-07-28；兩者不影響核心判斷）  
> **核心事實**：2026-07-30 是 2026 年 7 月第 **5** 個週四（7/2、7/9、7/16、7/23、7/30）  
> **分校**：木柵（前端 `useBranches` CampusID **16**；查庫時仍以 `Campus.name` 核對）  
> **復發家族**：F2／F3 候選；§R22／R26／R64；**非**「builder 只排四週」generic limitation

---

## 0. 結論（code-side 已鎖定；production 單筆尚未收斂）

**目前不能誠實地把單筆 production 根因收斂成 H1–H6 的其中一個**，因為尚未取得該生 `StudentClass`／`ClassSession`／schedule exception 的唯讀輸出。

但 code-side 已確定：

1. `buildSessionsFromWeeklySchedule()` **不是「只排四週」**；逐日掃描完整 `StartDate..EndDate`，符合 weekday 的日期都生成。只要 `EndDate >= 2026-07-30` 且 slot 是週四，7/30 應進候選集合。
2. 既有 Feature Test 已用「每週四、EndDate=2026-07-31」跨月案例預期 13 個週四；該期間本身含 2026-07-30 →「第五個週四無法生成」**不是**已知 generic builder limitation。
3. 系統性問題是 **產品契約與入口行為不一致**：
   - recurring path：依 StartDate／EndDate 自動生成；
   - explicit `session_plan` path：只接受明確送入的堂次；
   - 前端仍初始化 `monthly_sessions=4`，預覽卻依期間即時計算實際星期出現次數。
4. **最高機率**：單筆觸發 = **H1 / H2 / H6**；系統性促成 = **H5**。

**在 production 唯讀證據完成前，不批准修改**：扣堂、繳費、renew、`AlertController`、或 ClassSession 核心物化服務。

---

## 1. 定義落差（摘要）

| 面向 | 產品權威意圖 | 現行系統 | 判斷 |
|------|--------------|----------|------|
| 期間邊界 | 使用者設開課日、結束日 | recurring builder 用 StartDate..EndDate | 基本一致 |
| 是否必須同月 | 通常同日曆月、不跨月（「通常」≠ invariant） | recurring 可跨月（最長 730 天）；explicit／legacy 又拒跨月 | **契約落差，需 owner** |
| 固定星期生成 | 期間內所有 matching weekday | `dayOfWeekIso` 逐日掃描 | 一致；非第五週 bug |
| 第五個週四 | 若在期間內預設應含 | recurring 會含；EndDate=7/23 或 explicit 只有四筆則不含 | 取決於期間／plan |
| `monthly_sessions` | 未明文化 | recurring 改寫成生成數；explicit 須等於 session rows | 應為 **derived snapshot** |
| `settlement_day` | 催繳日，不決定契約邊界 | 存 1–31；期間仍靠 Start／End | 建議明文化；**本案不改 Alert** |
| 日曆真相 | 老師／出缺勤要看到真堂次 | operational truth = 物化 `ClassSession` | 維持 |

### `monthly_sessions` 建議定義（待 owner 拍板）

> 該 `StudentClass` billing period 在建立或重排當下的「規劃／物化堂次數量快照」，**必須由實際 session rows 推導**；不是預付堂數、不是排課上限、也不是最終應收的唯一真相。

- recurring：= 期間內生成 session row 數  
- explicit：= `session_plan` row 數  
- **不應**由前端預設 `4` 控制  
- 實際月結應收仍由批准的 attendance／billing rule 決定，不可直接假定 `monthly_sessions × Rate`

### Owner 決策（建議本次先採 interim，不全面硬改）

| # | 決策 | 建議 interim |
|---|------|----------------|
| 4.1 | 是否強制同日曆月 | **暫不 hard reject**；新建 UI 預設月底；跨月明示提示；另開 ADR + inventory |
| 4.2 | `monthly_sessions` | derived snapshot（如上） |
| 4.3 | 五個週四月份 | EndDate 含第五週四 → 預設五堂；可縮 EndDate／排除，但不得靠預設 4 **靜默**砍掉 |
| 4.4 | `settlement_day` | 催繳／帳務工作日；不參與生成／跨期 |

---

## 2. H1–H6 與決策樹

| 假設 | 狀態 | production 排除條件 |
|------|------|---------------------|
| **H1** EndDate < 7/30 | 最高優先，未證實 | `StudentClass.EndDate`；若 =7/23 → 收斂 H1（UI 不清則 +H5） |
| **H2** 舊期 Stop／續期空窗 | 未排除 | 同生／科／師所有 SC 的 Start／End／Stop；7/30 是否無 active period |
| **H3** 契約涵蓋但未物化 | 條件式 | EndDate≥7/30、weekday=4、無 `ClassSession` 7/30 19:00 |
| **H4** 衝突／409 | 次優先 | 操作 endpoint + HTTP status + error code + 同 slot 其他堂次 |
| **H5** 定義落差 | 系統性已確認 |  alone 不能解釋單筆；須搭 H1／H6 |
| **H6** UI／入口路徑 | 高度可能 | network／錄影：課程管理／行事曆加課／renew 走哪條 command |

**決策樹**：EndDate<7/30→H1；Stop+新期空窗→H2；涵蓋但無 CS→H3；CS cancelled／rescheduled→workflow；API 409／422→H4／H6。

**可寫的一句話（尚非定案）**：Code-side 已排除「月結 builder 天生只排四週」；production 單筆最可能是本期 EndDate／續期邊界未涵蓋 2026-07-30，或操作已轉 explicit plan 而未含第五個週四。**不可寫成「H1 已證實」。**

---

## 3. 修復方針（證據後才動）

| 層 | Option | 何時 |
|----|--------|------|
| 單筆 | **Option 1 containment**（incident repair：延長 EndDate 且／或補物化） | 唯讀證實 H1／H2／H3 且 owner 批准；先查 invoice／attendance／slot conflict |
| 防再犯 | **Option 2** UX（EndDate 預設月底、`monthly_sessions` derived、預覽列日期、截斷確認、explicit 模式標示） | 單筆修完，或發現多筆「默認四堂漏第五週」 |
| 不單獨採 | Option 3 只加警告 | 最多當 Option 2 內 targeted warning |
| **拒絕本案** | Option 4「月結＝日曆月帳單」domain migration | 另案 ADR；不混進第五週修復 |

**暫不改**：`AlertController`、payment／invoice calc、`SessionDeductionService`、核心 materialization、`renew-monthly`、calendar merge／synthetic（除非證據指向該模組）。

---

## 4. Production 唯讀診斷（必跑；本 cloud agent **無 Pi SSH**）

在 Pi（或具 read-only SSH 的 agent）執行。⛔ 禁止 `php artisan test`／`RefreshDatabase`／任何 UPDATE／INSERT。

### 4.1 找學生與月結課

```bash
cd /home/admin/backend && php artisan tinker --execute='
$campusIds = \App\Models\Campus::query()->where("name", "like", "%木柵%")->pluck("id","name");
echo "campuses=".json_encode($campusIds, JSON_UNESCAPED_UNICODE)."\n";
$students = \App\Models\Student::query()
  ->where("Name", "like", "%高瑞樸%")
  ->get(["ID","Name","CampusID"]);
echo "students=".$students->toJson(JSON_UNESCAPED_UNICODE)."\n";
foreach ($students as $s) {
  $scs = \App\Models\StudentClass::query()
    ->where("StudentID", $s->ID)
    ->where("ScheduleMode", "date")
    ->orderBy("ID")
    ->get(["ID","StudentID","TeacherID","SubjectID","StartDate","EndDate","Stop","Paid",
           "settlement_day","monthly_sessions","SessionCount","Charge","closed_reason",
           "week","time","week1","time1","week2","time2","week3","time3"]);
  echo "SC_student_{$s->ID}=".$scs->toJson(JSON_UNESCAPED_UNICODE)."\n";
}
'
```

### 4.2 七月堂次 + 7/30 19:00

將 `{SC_IDS}` 換成上一步 ID 列表：

```bash
php artisan tinker --execute='
$ids = [{SC_IDS}];
$rows = \App\Models\ClassSession::query()
  ->whereIn("StudentClassID", $ids)
  ->whereBetween("SessionDate", ["2026-07-01","2026-07-31"])
  ->orderBy("SessionDate")->orderBy("StartTime")
  ->get(["id","StudentClassID","SessionDate","StartTime","EndTime","Status","Note"]);
echo $rows->toJson(JSON_UNESCAPED_UNICODE)."\n";
$hit = $rows->first(fn($r) => $r->SessionDate==="2026-07-30" && str_starts_with((string)$r->StartTime,"19:00"));
echo "has_2026_07_30_1900=".($hit ? "yes#".$hit->id." status=".$hit->Status : "no")."\n";
'
```

### 4.3 schedules 例外（同生課程）

```bash
php artisan tinker --execute='
$ids = [{SC_IDS}];
$sched = \App\Models\Schedule::query()
  ->whereIn("student_course_id", $ids)
  ->whereBetween("schedule_date", ["2026-07-01","2026-07-31"])
  ->get(["id","student_course_id","schedule_date","start_time","end_time","status","teacher_id"]);
echo $sched->toJson(JSON_UNESCAPED_UNICODE)."\n";
'
```

### 4.4 老師／學生 7/30 19:00–21:00 slot 衝突（H4）

先從 SC 取得 `TeacherID`／`StudentID`，再查同日同時段其他 `ClassSession`。

### 4.5 貼回本檔的欄位清單

- [ ] CampusID（確認木柵）
- [ ] 所有相關 `StudentClass` 列（含 Stop／期間／week／monthly_sessions）
- [ ] 7 月全部 `ClassSession`
- [ ] 是否存在 2026-07-30 19:00 及其 Status
- [ ] 同日 schedules 例外
- [ ] 若有操作失敗：endpoint + HTTP + error code

貼回後依 §2 決策樹收斂 **單一** 根因，再決定 Option 1 是否開 repair plan。

---

## 5. 證據後執行順序（停止條件）

1. 唯讀診斷 → 收斂 H*  
2. H1／H2／H3 單筆 → `docs/incidents/` repair plan → **等人批准** → containment  
3. 多筆「默認四堂漏第五週」→ Option 2 UX PR（先測後改 production）  
4. EndDate 涵蓋但未物化 → **停 UX-only**；轉 F3 materialization  
5. 409／422 → 依 error code 查 H4／H6；不繞 guard  
6. 需改 billing／renew boundary／跨月 invariant → **停**；另送 owner（勿混 Option 4）

---

## 6. Option 2 預留（未開工）

影響檔案（證據後才動）：`frontend/src/components/UniversalClassScheduler.vue` + 既有 monthly recurring／renew Feature Test exact-date assertions。  
建議測試名見討論稿 §6（`test_monthly_recurring_july_2026_thursday_includes_2026_07_30` 等）。

---

## 7. 變更紀錄

| 日期 | 內容 |
|------|------|
| 2026-07-27／28 | B1 文件建立；code-side 排除「只排四週」；等待 Pi 唯讀 |
