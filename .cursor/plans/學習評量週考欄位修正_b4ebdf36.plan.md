---
name: 學習評量週考欄位修正
overview: 在學習評量表中新增「下次週考範圍」欄位，並將「周考成績」標籤改正為「週考成績」（兩處：頁面與匯出圖）。
todos:
  - id: migration
    content: 建立 migration：LearningRecord 表新增 NextWeekTestScope TEXT nullable
    status: completed
  - id: model
    content: LearningRecord.$fillable 新增 NextWeekTestScope
    status: completed
  - id: controller
    content: LearningRecordController store/update 驗證新增 NextWeekTestScope nullable|string
    status: completed
  - id: frontend-form
    content: LearningRecordsPage.vue：修正「周考→週考」標籤 + 新增「下次週考範圍」textarea 欄位
    status: completed
  - id: frontend-export
    content: learningRecordExport.js：修正「周考→週考」標籤 + 新增「下次週考範圍」繪製
    status: completed
  - id: parent-portal
    content: ParentPortal.vue：報告卡新增「下次週考範圍」唯讀顯示
    status: completed
  - id: deploy
    content: npm run deploy 上線
    status: completed
isProject: false
---

# 學習評量：新增「下次週考範圍」+ 修正「周→週」

## 影響範圍

- **DB 欄位**：`LearningRecord` 表新增 `NextWeekTestScope`（TEXT，nullable）
- **後端**：`LearningRecordController` store / update 驗證規則
- **Model**：`LearningRecord.$fillable`
- **前端 — 評量頁**：[`frontend/src/pages/LearningRecordsPage.vue`](frontend/src/pages/LearningRecordsPage.vue) — 表單新增欄位 + 標籤修正
- **前端 — 匯出**：[`frontend/src/lib/learningRecordExport.js`](frontend/src/lib/learningRecordExport.js) — 標籤修正 + 新欄位繪製
- **前端 — 家長入口**：[`frontend/src/pages/ParentPortal.vue`](frontend/src/pages/ParentPortal.vue) — 報告卡新增唯讀顯示

## 步驟

### 1. Migration — 新增欄位
建立新 migration，在 `LearningRecord` 表加入：
```
$table->text('NextWeekTestScope')->nullable()->after('NextHomework');
```

### 2. Model
[`backend/app/Models/LearningRecord.php`](backend/app/Models/LearningRecord.php) 的 `$fillable` 陣列新增 `'NextWeekTestScope'`。

### 3. Controller — 驗證規則
[`backend/app/Http/Controllers/LearningRecordController.php`](backend/app/Http/Controllers/LearningRecordController.php)

- `store()` validation：加入 `'NextWeekTestScope' => 'nullable|string'`
- `update()` validation：同上

### 4. 前端 — LearningRecordsPage.vue

**標籤修正**（1 處）：
- `周考成績` → `週考成績`（QuizScore 欄位 label）

**新增欄位**（緊接 `NextHomework` textarea 之後）：
```html
<label>下次週考範圍</label>
<textarea v-model="form.NextWeekTestScope" ... />
```

`form` 初始化物件新增 `NextWeekTestScope: ''`。

### 5. 前端 — learningRecordExport.js

**標籤修正**（1 處）：
- `drawLabelValue('周考成績', ...)` → `drawLabelValue('週考成績', ...)`

**新增繪製**（緊接下次作業範圍之後）：
```js
drawLabelValue('下次週考範圍', record.NextWeekTestScope || '—', ...)
```

### 6. 前端 — ParentPortal.vue

在報告卡唯讀顯示區塊（`NextHomework` 後）新增一列：
```html
<div v-if="record.NextWeekTestScope">
  <span>下次週考範圍</span>
  <span>{{ record.NextWeekTestScope }}</span>
</div>
```

### 7. Deploy
```bash
cd frontend && npm run deploy
```

## 資料流示意

```mermaid
flowchart LR
    subgraph frontend [前端]
        LR[LearningRecordsPage\n表單送出]
        EX[learningRecordExport\n匯出圖]
        PP[ParentPortal\n唯讀報告卡]
    end
    subgraph backend [後端]
        CTRL[LearningRecordController\nstore / update]
        MODEL[LearningRecord Model]
        DB[(LearningRecord\nDB table)]
    end
    LR -->|"POST/PUT NextWeekTestScope"| CTRL
    CTRL --> MODEL --> DB
    DB -->|"GET index / show"| LR
    DB -->|"GET index"| EX
    DB -->|"GET parent/sessions"| PP
```
