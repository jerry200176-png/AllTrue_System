---
name: Fix course start date session anchor
overview: 「開課日」填入過去日期時，第一堂課不會落在那天，而是悄悄移到「今天以後的第一個合約星期」。修正方法是：當開課日為過去日、且符合合約星期，自動將它加入「已上課」清單，確保第一堂真正建在開課日上。
todos:
  - id: ucs-auto-confirmed
    content: UniversalClassScheduler.vue：新增 watch，當 course_start_date < today 且符合合約星期時，自動加入 form.confirmed_dates
    status: completed
  - id: ucs-hint-update
    content: UniversalClassScheduler.vue：更新開課日欄位說明文字，補加 past-date warning hint
    status: completed
  - id: deploy
    content: npm run deploy 前端
    status: completed
isProject: false
---

# 林煒澔課程「開課日跑掉」根因與修正計畫

## 根因分析

### 問題還原

| 項目 | 值 |
|---|---|
| 使用者設定的開課日 | 2026-01-29（週四） |
| 建立課程當天（推算） | 2026-02-04 左右 |
| 合約星期 | 週四 |
| 期望第一堂 | 2026-01-29 |
| 實際第一堂 | **2026-02-05（下一個週四）** |

### 程式行為

[`UniversalClassScheduler.vue`](frontend/src/components/UniversalClassScheduler.vue) 的 `futureSessionOccurrences` computed：

```js
// 第 882–883 行
const courseStart = form.course_start_date || '';
const effectiveToday = (courseStart > todayYmd) ? courseStart : todayYmd;
```

- 只有當 `course_start_date > today` 時，才以開課日為起點
- 當 `course_start_date < today`（即開課日已過），`effectiveToday = today`，開課日完全失效
- 第一個自動預排的週四 = **今天以後的第一個週四 = 2026-02-05**
- 結果：`StudentClass.StartDate = 2026-02-05`，1/29 從未被建立

### 欄位提示具有誤導性

目前 UI 說明（第 296 行）：
> 「系統自動排課將從此日起算，不會在此日之前建立預排堂次。」

實際行為是「從 max(開課日, 今天) 起算」，過去日期靜默被忽略，使用者無從得知。

### 為什麼不能直接讓 future session 落在過去？

後端 [`EnrollmentService.php`](backend/app/Services/EnrollmentService.php) 第 244–251 行明確擋住 `kind: 'future'` 的過去日期：

```php
if ($normalizedDate < $today) {
    return response()->json(['message' => '未來預排不可早於今天', ...], 422);
}
```

過去的堂次只能以 `kind: 'confirmed'`（已上課）送出，因此前端必須把過去的開課日自動加入 `confirmed_dates`。

---

## 修正計畫

### 變更 1：`UniversalClassScheduler.vue` — 開課日自動進入 confirmed_dates

**目標：** 當 `course_start_date` 符合以下所有條件，自動將其加入 `form.confirmed_dates`：
- 日期 < today
- 該日的 weekday 在 `form.days_of_week` 裡（合約星期）
- `form.confirmed_dates` 尚未包含該日期

在 `watch(() => form.course_start_date, ...)` 或現有 `watch([() => form.course_start_date, () => form.days_of_week], ...)` 的位置增加邏輯：

```js
// 偽代碼示意
if (courseStart < today && daySet.has(dow(courseStart)) && !form.confirmed_dates.includes(courseStart)) {
  form.confirmed_dates = [courseStart, ...form.confirmed_dates];
}
```

加入後使用者在日曆上會看到 1/29 已被選入「已上課」，視覺立即確認。

### 變更 2：`UniversalClassScheduler.vue` — 更新欄位提示文字

將第 296 行的說明改為：

> 「若開課日為未來日，系統預排從此日起算。若已是過去，請在下方日曆點選各已上課日期（或設定後系統自動加入）。」

### 變更 3：`UniversalClassScheduler.vue` — 補加視覺提示

在 `course_start_date` 欄位下方，當開課日 < today 時顯示 warning hint：

> 「開課日 `{date}` 已過，已自動標記為已上課日期。請確認下方日曆是否正確。」

---

## 受影響範圍

- [`frontend/src/components/UniversalClassScheduler.vue`](frontend/src/components/UniversalClassScheduler.vue)：自動加入 confirmed_dates 邏輯 + 提示文字
- 後端 / 其他頁面：**不需異動**（EnrollmentService 對 confirmed 過去日期的處理已正確）

---

## 不需要修改的情境

- 開課日 > today：現有邏輯正確，future sessions 從開課日開始排
- 課程編輯（`CourseManagement.vue` / `CourseEditForm.vue`）的 `first_class_date`：屬於另一條 PUT 路徑，不走 `UniversalClassScheduler`，且該欄位僅更新 `StudentClass.StartDate`，不重排已有堂次
