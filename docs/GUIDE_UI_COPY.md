# AllTrue UI 文案規範（去模板感 / 白話優先）

> **單一真相來源。** 所有面向主任/老師/家長的 UI 文字一律照本文件生成。  
> 適用範圍：頁面標題、空狀態、loading、錯誤訊息、按鈕文字、placeholder、說明文字。  
> 對齊：[`docs/RULE_DESIGN_SYSTEM.md`](RULE_DESIGN_SYSTEM.md)（視覺）、[`.cursor/rules/user-facing-communication.mdc`](../.cursor/rules/user-facing-communication.mdc)（公開留言）

---

## 1. 語氣原則

AllTrue 是補習班**營運後台**，不是消費者 App 或行銷頁：

| 要 | 不要 |
|---|---|
| 簡短、能掃 | 長段「溫馨提示」 |
| 動詞開頭（建立/確認/查看）| 名詞 + 說明（「關於…」）|
| 說得出「下一步」 | 單純陳述現狀 |
| 繁體中文為主 | 夾雜英文 UI 字串 |
| 具體範例（`例：大安國中`）| 空泛 placeholder（`請輸入`）|

---

## 2. 空狀態公式

**公式：`[情境說明] + [下一步行動]`**

| 情境 | ❌ 舊寫法 | ✅ 新寫法 |
|---|---|---|
| 學生無課程 | 無資料 | 尚無課程記錄。請至學生管理建立課程。 |
| 本日無排課 | — | 今日沒有排課。可至智慧行事曆新增。 |
| 待審評量為零 | 無待審項目 | 目前沒有等待審核的評量。 |
| 搜尋無結果 | 無結果 | 找不到符合「`{{ keyword }}`」的結果。請調整搜尋條件。 |
| 歷史記錄空白 | — | 尚無操作記錄。 |

Empty state 元件：用 `AtEmpty`（#688），圖示用 `material-symbols-outlined`，**禁止 emoji**。

---

## 3. Loading 狀態

全站統一：

```
載入中…
```

- **全形省略號**（`…`），非英文 `...`
- 不加「請稍候」、不加 spinner 自訂色（用 `--ds-primary`）
- 骨架屏（skeleton）優先於整頁 spinner

---

## 4. 錯誤訊息

**公式：`[發生什麼] + [可以做什麼]`**

| 情境 | ❌ | ✅ |
|---|---|---|
| 儲存失敗 | Error 500 | 儲存失敗，請稍後再試。若問題持續，請聯絡管理員。 |
| 表單驗證 | invalid input | 請填入學生姓名。 |
| 權限不足 | 403 Forbidden | 您沒有執行此操作的權限。 |
| 網路逾時 | Request timeout | 網路連線逾時，請確認網路後重試。 |

**禁止**：
- `Oops`、`Something went wrong`、`Unexpected error`（英文 UI 字串）
- 直接暴露欄位名（`rate_unit`）、Controller 路徑（`XxxController::method`）、SQL
- 技術細節寫進 **internal note**，公開留言只寫白話

---

## 5. Placeholder 規範

| 欄位類型 | 格式 | 例子 |
|---|---|---|
| 姓名 | `請輸入全名` 或空白 | — |
| 學校 | `例：大安國中` | 具體範例勝過說明 |
| 電話 | `09xxxxxxxx` | — |
| 金額 | `0` 或空白（不寫「請輸入金額」）| — |
| 備註/自由文字 | 說明可填什麼 | `特殊需求、過敏、家長偏好等…` |

---

## 6. 按鈕文字

| 情境 | 文字 | 層級 |
|---|---|---|
| 主要行動 | `建立課程` / `儲存` / `確認` | Primary（一區塊一顆）|
| 取消/退出 | `取消` | Ghost |
| 刪除/危險 | `刪除` / `移除` | Danger |
| 匯出 | `匯出 Excel` | Secondary |
| 審核通過 | `核准` | Primary |
| 審核退回 | `退回` | Secondary 或 Danger（擇一，全站統一）|

**禁止**：`OK`、`Submit`、`Confirm` 等英文按鈕（除 Login 頁刻意英文外）。

---

## 7. 說明文字長度

- **卡片/列表 tooltip**：1 句以內
- **表單 hint**：≤ 2 句
- **Modal 說明**：≤ 3 句，超過改用 `AtEmpty` 下一步 + 連結說明文件
- **導覽步驟（pageGuideConfig）**：description ≤ 2 句；詳細說明放 Help Guide

---

## 8. 禁止清單

- 裝飾性 emoji（`🎯`、`✨`、`💡`）當 UI 文案或狀態圖示
- 「溫馨提示：」前置詞
- 英文 UI 字串（`No data`、`Loading...`、`Please fill in`）
- 同義重複（「確定要刪除嗎？此操作不可還原，刪除後資料將永久消失」→「確定刪除？此操作無法復原。」）

---

## 9. 首批需改熱點（Wave 2 順便修）

| 檔案 | 問題 | 建議 |
|---|---|---|
| `StudentsList.vue` | textarea `placeholder="特殊需求、過敏、家長偏好等..."` | 改全形省略號 `…` |
| 各頁 `.empty-text` | 只寫「無資料」無下一步 | 套用第 2 節公式 |
| `BugReportsPage.vue` | 若有機器人語氣說明 | 改為「回報問題」簡短說明 |
| `pageGuideConfig.js` | description 超過 2 句 | 截短；詳細放 Help Guide |

---

## 參考

- Stripe Dashboard microcopy（短句、動詞開頭、不寫「點擊」）
- GitHub Primer content guidelines（empty state 公式）
- Epic #687、`docs/RULE_DESIGN_SYSTEM.md`
