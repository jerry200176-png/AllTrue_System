# [UX] 家長評量回饋 UI/UX 規格

對應文件：
- PRD：`.cursor/plans/parent_learning_feedback_prd_2026-04-25.md`
- ARCH：`.cursor/plans/arch_parent_learning_feedback_2026-04-25.md`

## 1. UX 目標

家長看完評量後，可以在同一張評量卡中自然留下回饋；老師與主任看到的是清楚、低干擾、可追蹤的回饋提示，而不是新的聊天系統。

設計語氣：
- 家長端：溫和、鼓勵補充，不製造壓力。
- 老師端：清楚提示「這筆有家長回饋」，但不打斷評量流程。
- 主任端：管理視角，重點是未讀、學生、老師、分校與日期。

## 2. ParentPortal.vue — 家長端

### 2.1 放置位置

在每張 `學習評量` expandable card 的 detail 區塊底部，位於老師 `Comment` / 「學習建議與家長溝通」之後。

資訊層級：

1. 評量內容
2. 老師學習建議
3. 家長回饋區

### 2.2 區塊內容

標題：
- `給老師的回饋`
- icon：`rate_review` 或 `forum`

說明文字：
- 未送出：`有想補充給老師的嗎？可留下問題、觀察或鼓勵。`
- 已送出：`已送出給老師與主任查看。`

Textarea placeholder：
- `例如：孩子回家說這個單元還不太熟，想請老師下次協助加強。`

按鈕：
- 未送出：`送出回饋`
- 已送出：`更新回饋`

### 2.3 狀態規格

| 狀態 | UI |
|---|---|
| loading | 回饋區顯示 1 行 skeleton + disabled button |
| empty | 顯示說明文字、textarea、字數 0/500、送出按鈕 |
| existing | textarea 預填既有 content，顯示 `上次更新：M/D HH:mm` |
| submitting | 按鈕 spinner + `送出中...`，textarea disabled |
| success | toast：`已送出給老師`，區塊顯示更新時間 |
| validation error | textarea 下方 inline error，不清空內容 |
| API 401 | toast / inline：`登入已逾時，請重新登入` |
| API 403 | inline：`此評量不屬於目前登入的學生，無法送出回饋` |
| API 500/network | inline：`暫時無法送出，請稍後再試`，保留輸入 |

### 2.4 表單防呆

- trim 後空白不可送出。
- 500 字上限，超過時：
  - 字數顯示改 warning / danger 色。
  - button disabled。
  - inline：`最多 500 字，目前 X 字。`
- 送出時再次 trim。
- 不需要二次確認，因為回饋可更新。

### 2.5 手機版

- textarea 寬度 100%。
- 最小高度 96px。
- button 高度 >= 44px。
- 字數提示與錯誤訊息放在 textarea 下方，不擠在同一列。
- 不出現水平 overflow。

### 2.6 空狀態與文案

若尚無已核准評量，沿用既有空狀態，不顯示回饋入口。

若有評量但沒有回饋：
- 不顯示負面字眼「未回饋」。
- 用鼓勵型文字：`有想補充給老師的嗎？`

## 3. LearningRecordsPage.vue — 老師端

### 3.1 列表提示

在評量列表每筆 record 的 meta / status 區加入 badge：

| 條件 | Badge |
|---|---|
| 有回饋且老師未讀 | `家長回饋` warning badge |
| 有回饋且老師已讀 | `有家長回饋` neutral badge |
| 無回饋 | 不顯示 badge |

Badge 文案必須有文字，不只靠顏色。

### 3.2 詳情區

在評量 detail / modal 中新增區塊：

標題：`家長回饋`

內容：
- 學生姓名
- 回饋時間 / 更新時間
- 回饋本文

無回饋：
- 顯示小型 muted text：`尚無家長回饋`
- 不佔太多空間

### 3.3 已讀行為

- 老師打開有回饋的評量 detail 後，前端呼叫 mark read API。
- mark read 失敗時：
  - 不阻擋 detail 顯示。
  - 不跳大型錯誤 toast。
  - 可在 console 也不印 content；若要記錄，只記 feedback id 與 status。

### 3.4 老師端不可做的事

- 不可編輯家長回饋。
- 不可刪除家長回饋。
- 不提供「回覆」欄位。
- 不把家長回饋混入老師評量內容欄位。

## 4. 主任 / Admin 視角

### 4.1 入口策略

MVP 不新增新頁，先整合在 `LearningRecordsPage.vue`：

- 篩選列新增：
  - `只看有家長回饋`
  - `只看未讀回饋`
- 評量列表顯示與老師端相同 badge。
- 詳情區顯示完整回饋。

理由：
- 避免新增側欄 active key 與權限入口。
- 主任本來就會在評量審核/查詢脈絡中看評量。

### 4.2 主任列表欄位

當 director/admin 開啟「只看有家長回饋」時，列表每筆應至少看見：

- 學生姓名
- 老師姓名
- 科目
- 評量日期
- 回饋更新時間
- 未讀 badge

### 4.3 已讀行為

- 主任打開 detail 後，標記 `last_read_by_director_at`。
- 老師已讀與主任已讀互不影響。

## 5. API 與前端資料策略

### ParentPortal

MVP 建議 parent dashboard 的 `learning_records` 每筆附帶：

```json
{
  "parent_feedback": {
    "id": 1,
    "content": "...",
    "created_at": "...",
    "updated_at": "..."
  }
}
```

送出 / 更新後：
- 直接更新該 record 的 `parent_feedback` local state。
- 不強制 reload 整個 dashboard。

### LearningRecordsPage

MVP 建議 `GET /learning-records` 每筆附帶 summary：

```json
{
  "parent_feedback": {
    "id": 1,
    "content": "...",
    "updated_at": "...",
    "unread_for_teacher": true,
    "unread_for_director": false
  }
}
```

如果擔心 payload，可先只回摘要：
- `id`
- `updated_at`
- `unread_for_*`
- `preview` 前 60 字

但 detail 仍需可取得完整內容。DEV 可依實作成本選擇，需保持 UI 行為一致。

## 6. 視覺樣式建議

### Parent feedback card

- 背景：沿用評量 detail 淺色背景，或使用 `rgba(92, 107, 192, 0.06)`。
- Border：1px solid 淺灰 / 淺藍。
- 圓角：與 `.pp-report-field` 或 card 系統一致。
- 間距：top margin 12-16px，padding 12px。

### Badge

- 未讀：warning 色系，文案 `家長回饋`
- 已讀：neutral / info 色系，文案 `有家長回饋`

## 7. 無障礙

- textarea 必須有 label 或 aria-label：`給老師的回饋`。
- 錯誤訊息以文字顯示，不只靠紅框。
- submit button disabled 時仍保留可讀 title / 說明。
- badge 文字完整，不只用 icon 或顏色。
- 手機觸控目標 >= 44px。

## 8. QA UI Checklist

- [ ] ParentPortal 展開評量後看到回饋區。
- [ ] 空白不可送出，錯誤文案清楚。
- [ ] 超過 500 字不可送出，字數提示正確。
- [ ] 送出中不能重複點擊。
- [ ] 成功後顯示更新時間，不清空成空白狀態。
- [ ] 老師端未讀 badge 出現，打開後可消失或更新為已讀狀態。
- [ ] 主任端可用「只看有家長回饋」篩選。
- [ ] 手機版無水平 overflow。
- [ ] 不在 console 顯示回饋全文。

## 9. UX Exit Checklist

- [x] ParentPortal 版面、空狀態、loading、錯誤、防呆已定義。
- [x] 老師視角列表 badge、詳情、已讀行為已定義。
- [x] 主任視角 MVP 入口與篩選策略已定義。
- [x] 手機與無障礙規格已定義。
- [x] 未做雙向聊天與 LINE 推播，符合 PRD v1 範圍。

