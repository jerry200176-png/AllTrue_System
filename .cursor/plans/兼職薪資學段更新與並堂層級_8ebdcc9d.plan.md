---
name: 兼職薪資學段更新與並堂層級
overview: 修正三個兼職薪資問題：(1) 更新學生學段後薪資頁不更新；(2) 並堂加給要依課程層級（高中>國中>國小>輔導）判斷哪個 session 主導，低層級 session 在重疊期間基本費歸 0；(3) 科目名稱顯示不一致（英文 key vs 中文標籤）。
todos:
  - id: issue1-student-cascade
    content: StudentController::update() — 更新 Student.ClassID 後，cascade 更新同學生所有 Stop=0 的 StudentClass.GradeID
    status: completed
  - id: issue2-config
    content: config/payroll.php — 新增 level_weights 常數（high=4, junior=3, elementary=2, tutoring=1）
    status: completed
  - id: issue2-concurrency-map
    content: FinanceController::buildConcurrencyBonusMap() — 加入 level_weight/base_rate，sweep 改為高層主導、低層被覆蓋扣除，回傳 net_amount（可負）；接受 $rateMap 參數
    status: completed
  - id: issue2-callers
    content: buildTeacherRows / buildCSVRows / buildSummaryRows — 呼叫前先建 $rateMap，傳入 buildConcurrencyBonusMap
    status: completed
  - id: issue2-build-session-row
    content: buildSessionRow() — 加 max(0, $total) 防溢；確認 concurrency_bonus_amount 可回傳負值
    status: completed
  - id: issue2-frontend
    content: ParttimePayrollPage.vue — 並堂欄只在 concurrency_bonus_amount > 0 時顯示 +N
    status: completed
  - id: tests
    content: ParttimePayrollTest.php — 更新受影響 golden case，新增層級優先 / 同層保持 / 升學 cascade 三個測試
    status: completed
  - id: issue3-subject-normalize
    content: FinanceController::buildSessionRow() — subject 欄位加正規化：English→英文、Chinese→國文、Math→數學 等，解決 LR.Subject 存英文 key 時顯示不一致
    status: completed
  - id: prd
    content: PRD_PARTTIME_TEACHER_PAYROLL.md — 更新 §4.3 並堂加給層級說明與 §14.1 golden cases
    status: completed
isProject: false
---

# 兼職薪資：學段同步 & 並堂層級優先計畫

## 問題一：學段更新後薪資頁不更新

**根因**：`StudentController::update()` 更新 `Student.ClassID`，但不回寫 `StudentClass.GradeID`；薪資計算讀 `$r->studentClass->GradeID`，因此學生升年級後舊課程仍顯示舊學段。

**修改位置**：[`backend/app/Http/Controllers/StudentController.php`](backend/app/Http/Controllers/StudentController.php) L195–199

在 `$student->save()` 之前，補上 cascade：
```php
if (isset($input['grade']) || isset($input['GradeID'])) {
    \App\Models\StudentClass::where('StudentID', $student->id)
        ->where('Stop', 0)
        ->update(['GradeID' => $student->ClassID]);
}
```

---

## 問題二：並堂加給加入層級優先（高中>國中>國小>輔導）

### 新規則

| 狀況 | 計算 |
|---|---|
| 同層級並堂（如兩 LR 都是國中） | 維持原行為：各 LR 各得 `(n-1) × 50 × dt` |
| 不同層級（如 國中 + 輔導） | 高層 LR **主導**，取得 `(n_total-1) × 50 × overlap` 加給；低層 LR 在重疊區間基本費歸 0（扣除 `base_rate × overlap`） |

**範例**（04-15，Ruth蔣，2h 完全重疊）：
- 莊承皓 國中（350/h）：主導 → `350×2 + (2-1)×50×2 = 800` ✓
- 魏迦勒 輔導（200/h）：被覆蓋 → `200×2 + (-200×2) = 0` ✓
- 04-08 同理 → 莊承皓 800，魏迦勒 0

---

### 後端改動

#### `config/payroll.php`
新增 level weight 常數：
```php
'level_weights' => [
    'high'       => 4,
    'junior'     => 3,
    'elementary' => 2,
    'tutoring'   => 1,
],
```

#### `FinanceController::buildConcurrencyBonusMap()` — 核心改動
- 在 `$intervals[]` 中新增 `level_weight` 和 `base_rate` 欄位
- 為每個 LR 加入 level/base_rate 解析（ClassType=tutoring → weight=1）
- 傳入 `array $rateMap = []`（`[lr_id => ['base_rate' => X, 'level_weight' => Y]]`），讓呼叫端可傳入 custom 費率
- Sweep 邏輯修改：
  - 每個 segment 找 `$maxLevel` = 該 segment 所有並行 LR 的最高 level_weight
  - 若 `$iv['level_weight'] == $maxLevel`：bonus += `(n_total - 1) × 50 × dt`（主導）
  - 若 `$iv['level_weight'] < $maxLevel`：deduction += `-$iv['base_rate'] × dt`（被覆蓋）
- 回傳 `[lr_id => net_amount]`（net 可為負值）

#### `buildTeacherRows()` / `buildCSVRows()` / `buildSummaryRows()` — 呼叫端
在呼叫 `buildConcurrencyBonusMap()` 前，先建 `$rateMap`（用 `resolveEffectiveRate` 取每筆 LR 的實際費率）

#### `buildSessionRow()` — 接收負數 net
- 既有 `$total = $subTotal + $concurrencyBonus` 已能處理負值
- 回傳中 `concurrency_bonus_amount` 可為負（表示被覆蓋扣除）
- 加一行 `$total = max(0, $total)` 防溢

---

### 前端改動

[`frontend/src/pages/ParttimePayrollPage.vue`](frontend/src/pages/ParttimePayrollPage.vue)

- 並堂欄：只有 `s.concurrency_bonus_amount > 0` 才顯示 `+N`，其餘顯示 `—`（被覆蓋的 session 也顯示 `—`）
- 薪資欄：0 正常顯示 0（已能運作）

---

### 測試改動

[`backend/tests/Feature/ParttimePayrollTest.php`](backend/tests/Feature/ParttimePayrollTest.php)

更新受影響 golden cases，新增：
- `test_level_dominance_junior_over_tutoring`：國中 + 輔導並堂 2h，高者 800，輔導 0
- `test_same_level_both_earn`：兩個 國中 LR 並堂，各得 +50/h concurrency（舊行為保持）
- `test_student_grade_update_cascades_to_studentclass`：升年級後 StudentClass.GradeID 更新

---

---

## 問題三：科目名稱英中不一致

**根因**：`buildSessionRow()` L1018 直接回傳 `LearningRecord.Subject`，如果建立 LR 時存入的是前端常數英文 key（如 `"English"`），就直接顯示英文；其他 LR 若 Subject 為空則 fallback 到 `displaySubjectName()` 才顯示中文。

**修改位置**：[`backend/app/Http/Controllers/FinanceController.php`](backend/app/Http/Controllers/FinanceController.php) — 在 `buildSessionRow()` 中加入正規化方法：

```php
private function normalizeSubjectLabel(string $subject): string
{
    return match($subject) {
        'English'   => '英文',
        'Chinese'   => '國文',
        'Math'      => '數學',
        'Physics'   => '物理',
        'Chemistry' => '化學',
        'Science'   => '自然',
        'Social'    => '社會',
        default     => $subject,
    };
}
```

然後 L1018 改為：
```php
'subject' => $this->normalizeSubjectLabel($r->Subject ?: ($sc->displaySubjectName())),
```

---

### PRD 更新

[`docs/PRD_PARTTIME_TEACHER_PAYROLL.md`](docs/PRD_PARTTIME_TEACHER_PAYROLL.md)
- §4.3 並堂加給：補充層級優先說明與範例
- §14.1 Golden Cases：更新 04-08 / 04-15 相關 cases
