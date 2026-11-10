# AllTrue Engineering — Technical Design Doc
## Phase 2: Student Presence-Window Attendance
### 版本：v1.0 | 2026-04-23 | 狀態：Draft

---

## 📊 資料庫變更

**新增資料表：無**

**新增欄位：無**

**Migration：無需執行**

**現有資料表確認：**

| 表 | 關鍵欄位 | 確認狀態 |
|---|---|---|
| `ClassSession` | `StudentClassID`, `SessionDate`(date), `StartTime`(time), `EndTime`(time) | ✅ 確認（migration 2026_02_07） |
| `StudentSingIn` | `ClassSessionID`, `StudentClassID`, `SignInDT`, `SignOutDT`, `Memo`, `SessionDeducted`, `VoidedAt` | ✅ 確認 |
| `StudentClass` | `StudentID`, `Stop`, `TotalHours`, `TeacherID`, `GradeID`, `SubjectID` | ✅ 確認 |

→ **DBA Phase 可跳過（無 DB schema 異動）**

---

## 🔌 API 合約

### 修改路由：`POST /api/v1/swipe-rfid`

| 情境 | `action` 值 | `record.Memo` | 說明 |
|---|---|---|---|
| 刷進（有課） | `sign_in` | `'swipe-rfid'` | 不變 |
| 刷進（無課）| `sign_in` | `'self_study'`（**新**） | FR-001 新增 |
| 刷退 | `sign_out` | — | 不變；Presence Window 在後端靜默補建，不影響 response |

**Breaking Change：無** — response 結構完全向後相容

---

## 🖥️ 前端元件規劃

**無前端異動** → UX Phase（Phase 2b）可跳過

---

## 🔗 模組依賴圖

```
POST /api/v1/swipe-rfid
  └── SwipeRfidController::swipe()
        └── handleStudentSwipe()                    ← 修改
              ├── [不動] findMatchingClass()
              ├── [修改] sign_out 分支
              │     └── [新增] backfillPresenceWindow()
              │               ├── ClassSession        (query + whereDoesntHave)
              │               ├── StudentClass        (via studentClass() relation)
              │               ├── StudentSignIn       (create)
              │               └── SessionDeductionService::deductOnAttendance()
              └── [修改] sign_in 分支 Memo 值判斷
```

---

## 📐 核心邏輯詳細設計

### 1. `handleStudentSwipe` 修改摘要

**Before（現有邏輯）：**
```
openRecord 存在 → close → return sign_out
openRecord 不存在 → findMatchingClass → sign_in（Memo 寫死 'swipe-rfid'）+ deduct
```

**After（新邏輯）：**
```
openRecord 存在 → close → backfillPresenceWindow() → return sign_out   ← 新增一行
openRecord 不存在 → findMatchingClass:
    找到課堂 → sign_in（Memo='swipe-rfid'）+ deduct                     ← 不動
    找不到   → sign_in（Memo='self_study'）不 deduct                    ← FR-001 新增
```

**實際程式碼改動極小：**
1. sign_out 分支：`$openRecord->save()` 之後新增呼叫 `backfillPresenceWindow`
2. sign_in 建立時：`'Memo' => $studentClass ? 'swipe-rfid' : 'self_study'`

---

### 2. `backfillPresenceWindow` — 新增 private method

**方法簽章：**
```php
private function backfillPresenceWindow(
    Student $student,
    Carbon  $signInDT,
    Carbon  $signOutDT,
    Campus  $campus
): void
```

**呼叫時機：** 在 sign_out 分支，`$openRecord->save()` 完成後，仍在同一 `DB::transaction` 內。

**查詢邏輯（Eloquent）：**
```php
$today       = $signInDT->toDateString();
$signInTime  = $signInDT->format('H:i:s');
$signOutTime = $signOutDT->format('H:i:s');

$sessions = ClassSession::query()
    ->with('studentClass')
    ->whereHas('studentClass', fn($q) => $q
        ->where('StudentID', $student->id)
        ->where('Stop', 0)
    )
    ->whereDate('SessionDate', $today)
    ->whereTime('StartTime', '>=', $signInTime)   // 在場期間內開始
    ->whereTime('StartTime', '<=', $signOutTime)  // 在學生離開前開始
    ->whereDoesntHave('signIns', fn($q) => $q     // 幂等：已有記錄則排除
        ->whereNull('VoidedAt')
    )
    ->get();
```

> `ClassSession::signIns()` relation 已存在（`hasMany StudentSignIn, ClassSessionID`），
> 直接使用 `whereDoesntHave` 即可，不需手寫 subquery。

**補建每筆 session：**
```php
foreach ($sessions as $session) {
    $sc = $session->studentClass;
    if (!$sc) {
        continue;
    }

    $sessionSignInDT  = Carbon::parse($today . ' ' . $session->StartTime);
    $sessionSignOutDT = Carbon::parse($today . ' ' . $session->EndTime);

    $newSignIn = StudentSignIn::create([
        'StudentClassID'   => $sc->ID,
        'StudentID'         => $student->id,
        'TeacherID'        => $sc->TeacherID,
        'RecordedByUserID' => null,
        'GradeID'          => $sc->GradeID,
        'SubjectID'        => $sc->SubjectID,
        'Get1byID'         => $sc->by1,
        'Hours'            => $sc->TotalHours ? (int) $sc->TotalHours : null,
        'Memo'             => 'presence-window',   // 來源標記，方便 debug
        'SignInDT'         => $sessionSignInDT,
        'SignOutDT'        => $sessionSignOutDT,
        'MDT'              => now(),
        'ClassSessionID'   => $session->id,
        'Status'           => 'present',
        'CampusID'         => $campus->id,
        'PersonType'       => 'student',
        'SessionDeducted'  => false,
    ]);

    SessionDeductionService::deductOnAttendance($sc, $newSignIn);

    Log::info('presence_window_backfill', [
        'student_id'       => $student->id,
        'student_name'     => $student->name,
        'class_session_id' => $session->id,
        'sign_in_dt'       => $sessionSignInDT->toDateTimeString(),
        'sign_out_dt'      => $sessionSignOutDT->toDateTimeString(),
    ]);
}
```

---

### 3. Transaction 邊界與錯誤處理

```
DB::transaction {                               ← 現有 transaction
    close openRecord（SignOutDT = swipeAt）      ← 現有邏輯
    backfillPresenceWindow() {
        query sessions（whereDoesntHave 幂等）
        foreach session {
            StudentSignIn::create()             ← transaction 保護
            deductOnAttendance()                ← 內部已有 try/catch，不拋異常
            Log::info(...)
        }
    }
}                                               ← transaction 結束
// Telegram 推播在此（P1，transaction 外，非同步）
return sign_out response
```

**關鍵設計決策：**
- `deductOnAttendance` 內部已有 `try/catch(\Throwable $e)` → 不會使 transaction rollback
- 若某一 session 的 `$sc` 為 null（StudentClass 已被刪除）→ `continue` 跳過
- 整個 `backfillPresenceWindow` 不另外包 try/catch，若發生意外 DB 錯誤，讓外層 transaction rollback（刷退本身也要 rollback，保持一致性）

---

### 4. 幂等保護分析

| 情境 | 幂等保護機制 | 結果 |
|---|---|---|
| 刷進的 A 課已有 `StudentSignIn`（`ClassSessionID = X`） | `whereDoesntHave('signIns')` → X 被排除 | ✅ 不重複 |
| 老師已手動點 B 課（有 `StudentSignIn`，`ClassSessionID = Y`） | `whereDoesntHave('signIns')` → Y 被排除 | ✅ 不重複 |
| 學生忘刷退，隔天補刷 → Presence Window 重跑 | `whereDate('SessionDate', $today)` → 只看當日 | ✅ 不跨日 |
| 學生同日誤觸兩次刷退 | 第一次補建後，`whereDoesntHave` 排除已建紀錄 | ✅ 第二次無補建 |

---

## ⚠️ 需使用者決策的設計問題

**無** — 所有設計問題已在 PRD v1.1 §8b Decision Log 決定：

| 決策 | 結論 |
|---|---|
| Smart Swipe | 移除（一對一補習班不適用） |
| Presence Window 排課模式 | 只支援 ClassSession，不支援 week/time |
| 補建 SignInDT 時間來源 | `ClassSession.StartTime`（業界標準） |
| Telegram 推播時機 | Transaction 後非同步（P1） |

---

## 📋 DEV 實作順序

| Step | 工作 | 檔案 | 預估難度 |
|---|---|---|---|
| 1 | 修改 sign_in 分支 `Memo` 值（FR-001）：`$studentClass ? 'swipe-rfid' : 'self_study'` | `SwipeRfidController.php` | 低 |
| 2 | 修改 sign_out 分支，在 `$openRecord->save()` 後加入 `$this->backfillPresenceWindow(...)` | `SwipeRfidController.php` | 低 |
| 3 | 實作 `backfillPresenceWindow()` private method | `SwipeRfidController.php` | 中 |
| 4 | 確認 `use Illuminate\Support\Facades\Log;` import 已存在 | `SwipeRfidController.php` | 低 |
| 5 | 寫 Pest Feature Tests（FR-001 / FR-002a / FR-002b / FR-002c） | `tests/Feature/StudentSwipePresenceWindowTest.php` | 中 |

---

## 🔍 QA 需重點測試的場景

1. **FR-001**：無課時刷進 → `Memo = 'self_study'`，`SessionDeducted = 0`
2. **FR-002a 主場景**：10:00 刷進（A 課）+ 14:00 刷退（B 課 12:00-14:00）→ B 課被補建
3. **FR-002b 老師手動點名共存**：老師已點 B 課 → 刷退後 B 課不重複建立
4. **FR-002c 幂等**：連續兩次刷退 → B 課只建一筆
5. **Regression**：`handleTeacherSwipe` 不受影響

---

## ✅ ARCH 審查清單

- [x] 無 DB schema 異動
- [x] 無前端異動
- [x] 無 Breaking Change（API response 相容）
- [x] 多校區隔離：`CampusID` 在補建記錄中帶入
- [x] 幂等保護：`whereDoesntHave` 在 DB 層保護
- [x] 不動高風險模組：`SessionDeductionService` 唯讀
- [x] 不動 `handleTeacherSwipe`、`findMatchingClass`
- [x] Transaction 邊界清晰：補建在 transaction 內，Telegram 在外

---

*文件版本歷史*

| 版本 | 日期 | 說明 |
|---|---|---|
| v1.0 | 2026-04-23 | 初版 |
