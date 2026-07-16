# RULE — User-facing Display Guidelines

> **永久 UX 原則**（in-app #200 UX Discovery）  
> **Root Cause 定調**：系統不得把工程師識別當成使用者語言。  
> **權威**：本文件。實作共用 formatter：`frontend/src/lib/studentClassDisplay.js`。  
> **Backlog**：[`GUIDE_UX_INTERNAL_IDENTIFIER_AUDIT.md`](GUIDE_UX_INTERNAL_IDENTIFIER_AUDIT.md)

---

## 一句話

給主任／行政／老師／家長／學生看的畫面，**預設使用人類可理解的資訊**；內部 ID 不得成為主要決策資訊。

---

## 適用對象（User-facing）

| 角色 | 適用 |
|------|------|
| 主任／行政 | ✅ |
| 老師 | ✅ |
| 家長 | ✅ |
| 學生（若有畫面） | ✅ |
| 工程師／Debug／CI log | ❌ 本規則不限制 |

---

## 禁止當「主要資訊」的 Internal Identifier

| ID | 別名／常見文案 |
|----|----------------|
| StudentClassID | `SC #`、`SC#`、`課程 #123`（僅數字） |
| SessionID / ClassSessionID | `session_id`、堂次 # |
| InvoiceID | `帳單 #`、`Invoice #` |
| PaymentID | `Payment #`、`payment_id=` 當主文案 |
| CourseID | `COURSE-000xxx`、純數字課程 # |
| ReservationID | 預約 # |
| 其他內部 PK／FK | `學生 #id` 作為唯一識別（有姓名時禁止） |

---

## 允許出現的位置

內部 ID **只能**出現在：

1. **小字**（次要、muted、非決策主標）
2. **Tooltip** / 長按說明
3. **Debug Mode** / Developer Tool
4. **工程 log／telemetry payload**（且不得當主任決策 UI）

**不得**：

- 當 radio／CTA／卡片標題的唯一內容
- 當「請選擇哪一側／哪一門課」的主要依據
- 在 toast／alert 裡只寫 `#123` 而無科目／姓名／日期

---

## 顯示優先序（強制）

組 label 時依序取用（缺則跳過），**不要在 Vue 模板直接拼字串**——走共用 formatter：

1. 課程名稱／科目  
2. 老師  
3. 必要時：開課日、班級、堂數／剩餘  
4. 學生姓名（若決策對象是人）  
5. **最後**才是內部 ID（小字）

驗收標準：

> 第一次看到畫面、不知道 SC／COURSE／Invoice ID 是什麼的人，仍能正確完成決策。  
> 若仍必須理解內部 ID → **修正失敗**。

---

## 與 #200 的關係

| 狀態 | 意義 |
|------|------|
| UX Discovery 完成 | 根因已確認；Minimal Fix 已上線並 Production Verify |
| Resolved | 等待 Reporter Verify |
| **不是** Bug 結束／Completed | 未 Closed 前不得宣稱完成閉環 |

---

## 相關

- Backlog（ROI 逐批）：`GUIDE_UX_INTERNAL_IDENTIFIER_AUDIT.md`
- 設計系統視覺：`RULE_DESIGN_SYSTEM.md`
- 公開留言白話：`user-facing-communication.mdc` / `CHAT_BUG_SYSTEM.md` §3.8
