---
name: 修復出缺勤與教師手機顯示
overview: 修復兩個問題：(1) `whereNull('si.VoidedAt')` 導致今日出缺勤紀錄全部消失；(2) 老師手機上行事曆與出缺勤持續空白（分校 context 錯誤 + mobile branch bar 未顯示）。
todos:
  - id: fix-attendance-voidedAt
    content: AttendanceController::index：移除 whereNull(VoidedAt)，並用 try/catch 保護 supplemental query
    status: completed
  - id: fix-teacher-mobile-branch-bar
    content: App.vue：mobile-branch-bar 條件改為 teacherBranches.length > 0（單一分校老師也顯示）
    status: completed
  - id: fix-ensureTeacherBranch
    content: App.vue：ensureTeacherBranch() 加 fallback — teacherBranches 為空時也嘗試設定有效 currentBranch
    status: completed
  - id: fix-attendance-error-display
    content: AttendancePage：fetchRecords / fetchPendingSessions 加非 200 錯誤訊息顯示
    status: completed
  - id: deploy
    content: npm run deploy 並驗證老師手機出缺勤與行事曆正常
    status: completed
isProject: false
---

# 修復出缺勤與教師手機顯示

## 問題一：今日出缺勤紀錄清空

### 根因
[`backend/app/Http/Controllers/AttendanceController.php`](backend/app/Http/Controllers/AttendanceController.php) 的 `index` method 新增了：

```php
$query->whereNull('si.VoidedAt');
```

這條件會把 cascade 過程中被 void 的 `StudentSingIn` 記錄全部隱藏。若對應的替換 `excused` 記錄未成功建立（任一 cascade 路徑中途失敗），該堂次完全消失。

此外，後面的 supplemental query（大型 `whereNotExists` 子查詢）若拋出 MySQL 錯誤（例如 correlated subquery 問題），整個 API 回 500，前端顯示空白。

### 修復方案

**`AttendanceController::index`（約 line 100）**：
1. 移除 `$query->whereNull('si.VoidedAt');` — 回復原本行為（顯示所有記錄，含 voided）
2. 將整個 supplemental query 區塊包在 `try/catch` 內，防止 SQL 異常破壞主回應
3. 如需隱藏 voided 重複記錄，改用 OUTER query 在 transform 階段過濾，不在 DB 層過濾

修改後的 supplemental 區塊變為：
```php
// 移除: $query->whereNull('si.VoidedAt');

// Supplemental 用 try/catch 保護
if ($request->filled('date')) {
    try {
        // ... leaveQ 邏輯不變 ...
        if ($leaveRows->isNotEmpty()) {
            $records->getCollection()->push(...$leaveRows->all());
        }
    } catch (\Exception $e) {
        // 不讓 supplemental 失敗影響主資料
        \Log::warning('AttendanceController supplemental query failed: ' . $e->getMessage());
    }
}
```

---

## 問題二：老師手機看不到行事曆與出缺勤

### 根因（既有問題）
1. `mobile-branch-bar` 條件：`v-if="isTeacher && teacherBranches.length > 1"` — 單一分校的老師在手機上**完全沒有分校 UI**，且看不到目前選擇的是哪個分校
2. `ensureTeacherBranch()` 在 `teacherBranches.length === 0` 時（`me.campuses` 空或未對應）靜默 return，`currentBranch` 可能停留在舊的或其他分校的 ID
3. `AttendancePage.fetchRecords` 在 API 返回非 200 時（如 403 Forbidden）**靜默清空**，使用者看到「今日尚無出缺勤紀錄」而非錯誤說明

### 修復方案

**[`frontend/src/App.vue`](frontend/src/App.vue)**：

A. `mobile-branch-bar` 條件放寬：
```diff
- <div v-else-if="isTeacher && teacherBranches.length > 1" class="mobile-branch-bar">
+ <div v-else-if="isTeacher && teacherBranches.length > 0" class="mobile-branch-bar">
```
讓單一分校老師也能看到目前分校名稱（顯示文字，不顯示下拉）。

B. `ensureTeacherBranch()` fallback（約 line 805）：
```php
// 若 teacherBranches 為空但有任何 branch，改選第一個 public branch
function ensureTeacherBranch() {
  if (!isTeacher.value) return;
  const allowed = teacherBranches.value;
  if (allowed.length > 0) {
    const allowedIds = new Set(allowed.map(b => b.id));
    if (currentBranch.value != null && allowedIds.has(currentBranch.value)) return;
    const preferred = allowed[0].id;
    currentBranch.value = preferred;
    localStorage.setItem('app_branch', String(preferred));
  }
  // 新增：即使 teacherBranches 空，也確保 currentBranch 是有效值（取第一個 public branch）
  else if (branches.value.length > 0 && !currentBranch.value) {
    currentBranch.value = branches.value[0].id;
    localStorage.setItem('app_branch', String(branches.value[0].id));
  }
}
```

**[`frontend/src/pages/AttendancePage.vue`](frontend/src/pages/AttendancePage.vue)**：

C. `fetchRecords` 非 200 時顯示錯誤（約 line 336）：
```diff
  } else if (res.status === 403) {
    fetchError.value = '無此分校權限，請確認分校設定';
+ } else {
+   fetchError.value = `載入出缺勤記錄失敗（HTTP ${res.status}），請重新整理`;
  }
```

D. `fetchPendingSessions` 同樣加非 200 提示。

---

## 涉及檔案

- [`backend/app/Http/Controllers/AttendanceController.php`](backend/app/Http/Controllers/AttendanceController.php) — 移除 `whereNull(VoidedAt)` + try/catch 保護
- [`frontend/src/App.vue`](frontend/src/App.vue) — mobile-branch-bar 條件 + ensureTeacherBranch fallback  
- [`frontend/src/pages/AttendancePage.vue`](frontend/src/pages/AttendancePage.vue) — fetchRecords/fetchPendingSessions 錯誤訊息
- 完成後執行 `cd frontend && npm run deploy`
