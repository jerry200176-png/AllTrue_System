---
name: fix cancel session no extend
overview: 取消已上（或已排）堂次後，`ClassSessionController::update()` 正確沖回計數器，但沒有呼叫 `tryExtendOnLeave`，導致購買堂數框內永遠少一堂。修正方法是在兩條取消分支末尾補呼叫現有的 `tryExtendOnLeave`。
todos:
  - id: cancel-extend-attended
    content: ClassSessionController.php：attended-like → cancelled 分支末尾（recomputeCounters 之後）補呼叫 tryExtendOnLeave，並更新 response message
    status: completed
  - id: cancel-extend-scheduled
    content: ClassSessionController.php：generic scheduled → cancelled 分支（syncCounters 之後）補呼叫 tryExtendOnLeave，並更新 response message
    status: completed
  - id: deploy
    content: 僅後端異動，不需 npm run deploy（前端不受影響）
    status: completed
isProject: false
---

# 取消堂次後未補建第 N+1 堂 — 修正計畫

## 根因分析

### 問題還原

| 項目 | 值 |
|---|---|
| 學生 | 林聖晏 |
| 課程 | 英文 一對二，週五 13:00–15:00 |
| 購買堂數 | 8 堂 |
| 取消的堂次 | 03/06（過去，排課中或已上） |
| 期望結果 | 取消後系統補建第 8 堂（原 第7堂 05/01 之後再加一堂） |
| 實際結果 | 計數器正確沖回（RemainingSessions = 4），但第 8 堂 ClassSession 未被建立，課程列表只顯示第 1–7 堂 |

### 程式行為

[`ClassSessionController.php`](backend/app/Http/Controllers/ClassSessionController.php) 的 `update()` 有兩條與取消相關的分支：

**分支 A — `attended-like → cancelled`（第 376–394 行）**

```
voidAttendanceArtifacts(...)
SessionDeductionService::reverseForSession(...)
session->Status = 'cancelled'
session->save()
SessionDeductionService::recomputeCounters(...)   ← 計數器沖回 ✅
← 沒有呼叫 tryExtendOnLeave                        ← 缺少補建 ❌
```

**分支 B — generic `scheduled → cancelled`（第 425–430 行）**

```
session->Status = 'cancelled'
session->save()
SessionDeductionService::syncCounters(...)        ← 計數器沖回 ✅
← 沒有呼叫 tryExtendOnLeave                        ← 缺少補建 ❌
```

相較之下，`scheduled → leave` 分支（第 397–422 行）**已正確呼叫** `$this->tryExtendOnLeave($studentClass, $session)`，且該方法在同一個 Controller 中已實作完整（含 `effectiveCount < sessionCount` 的防重複累加保護）。

### 為什麼 `tryExtendOnLeave` 可以直接重用？

`tryExtendOnLeave`（第 547 行）的核心邏輯：

1. `ScheduleMode === 'date'` → 不順延（固定日期制不補建）
2. 計算 `effectiveCount = count(非 cancelled/leave/leave_adjusted 的 sessions)`
3. 若 `effectiveCount >= sessionCount` → 不補建（防止無限累加）
4. 從最後一堂隔日起，按合約星期補建一堂

當一堂變成 `cancelled` 後，`effectiveCount` 就會比 `sessionCount` 少 1，條件自然成立，補建行為正確。

---

## 修正計畫

### 變更 1：`attended-like → cancelled` 分支末尾補呼叫 `tryExtendOnLeave`

**位置：** [`ClassSessionController.php`](backend/app/Http/Controllers/ClassSessionController.php) 第 393 行（`recomputeCounters` 之後）

在 `return $this->sessionUpdateResponse(...)` 之前加入：

```php
$extended = $this->tryExtendOnLeave($studentClass, $session);
$msg = '已更新為' . $newStatus . '，並完成堂數沖回';
if ($extended) {
    $msg .= '，已自動補建一堂至 ' . substr((string) $extended->SessionDate, 0, 10);
}
return $this->sessionUpdateResponse($session, $msg);
```

（同時移除原本 hard-coded 的 `return $this->sessionUpdateResponse($session, '已更新為' . $newStatus . '，並完成堂數沖回');`）

### 變更 2：generic `scheduled → cancelled` 分支末尾補呼叫

**位置：** [`ClassSessionController.php`](backend/app/Http/Controllers/ClassSessionController.php) 第 430 行（`syncCounters` 之後）

在 `return $this->sessionUpdateResponse(...)` 之前，針對 `$newStatus === 'cancelled'` 加入：

```php
$extMsg = '狀態已更新為' . $newStatus;
if ($newStatus === 'cancelled') {
    $extended = $this->tryExtendOnLeave($studentClass, $session);
    if ($extended) {
        $extMsg .= '，已自動補建一堂至 ' . substr((string) $extended->SessionDate, 0, 10);
    }
}
return $this->sessionUpdateResponse($session, $extMsg);
```

---

## 受影響範圍

- [`backend/app/Http/Controllers/ClassSessionController.php`](backend/app/Http/Controllers/ClassSessionController.php)：兩處 `cancelled` 分支補呼叫 `tryExtendOnLeave`
- 後端其他檔案 / 前端：**不需異動**（`tryExtendOnLeave` 已存在且邏輯正確）

---

## 不需要修改的情境

- `ScheduleMode === 'date'`（固定日期制）：`tryExtendOnLeave` 內部已判斷，直接 return null，安全
- `SessionCount = 0` 或 `effectiveCount >= sessionCount`：同上，內部已保護，不重複補建
- 請假（`scheduled → leave`）：已正常呼叫 `tryExtendOnLeave`，不受影響
