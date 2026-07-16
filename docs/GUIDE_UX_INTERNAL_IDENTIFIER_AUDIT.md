# UX Internal Identifier — Audit & Backlog

> **原則權威**：[`RULE_USER_FACING_DISPLAY.md`](RULE_USER_FACING_DISPLAY.md)  
> **策略**：依 ROI **逐批**消除；禁止一次全改。  
> **最後刷新**：2026-07-16  
> **觸發**：in-app #200（工程師語言 `SC` 當成使用者語言）

---

## Done

| ID | 畫面 | Identifier | 改法 | Impact | Priority |
|----|------|------------|------|--------|----------|
| UXID-001 | 重疊課程審核 `DuplicateSessionReviewPage` | `SC #` / StudentClassID | `studentClassDisplay`：科目·老師·開課·堂數；SC 小字 | 主任可決策 | **P0 Done**（#200 / PR #1253） |

---

## Open Backlog（ROI 排序）

| ID | 畫面 | Internal Identifier | 建議改法 | Impact | Priority | ROI |
|----|------|---------------------|----------|--------|----------|-----|
| UXID-002 | 帳務收款／結清 `TuitionCollectionPage` | `課程 #id`、`新課程 #newer_course_id` | 科目 · 學生名 · 開課日 · 剩堂；ID 小字 | 主任每日結清／續報路徑 | P1 | **最高**（高頻＋帳務） |
| UXID-003 | 課程管理 toast／續報成功 `CourseManagement`、`StudentsList` | `課程 #id`、`原課程#` | toast：學生名 + 科目 + 「新一期／新批次」；ID 小字或省略 | 續報後第一眼確認 | P1 | 高（高頻） |
| UXID-004 | 帳本／帳單副標 `AccountingLedgerModal`、`CourseManagement` 帳單區 | `COURSE-000xxx`、`帳單 #invoice_id`、`Payment #` | 優先 `course_ref`／收據號／日期／金額；無 ref 時用科目·學生 | 核帳決策 | P1 | 高（帳務正確性認知） |
| UXID-005 | 批次請假錯誤列 `BulkLeaveModal` | `課程 #course_id` | 科目 + 上課日 + reason | 請假失敗可自助理解 | P2 | 中 |
| UXID-006 | 多頁 fallback「學生 #id」 | `學生 #` / student_id | API 確保帶 `student_name`；fallback 保留但列監控 | 無名時才出現 | P2 | 中（修資料優於改字） |
| UXID-007 | ReceiptModal query | `payment_id=` | 維持非展示；確認 UI 無主文案洩漏 | 無使用者決策依賴 | P3 | 低（可不改） |

### 明確排除（非 Backlog）

- `:key`、API query、telemetry strip、formatter `techId` 小字  
- Debug／Developer Tool 顯示

---

## 執行節奏

1. 每批最多 **1 個 UXID**（或同畫面相關的一小組）  
2. 必須：共用／擴充 `studentClassDisplay`（或同級 formatter），禁止模板硬拼  
3. 必須：unit test「主標不含內部 ID」  
4. Production Verify → 有 in-app 關聯才請 Reporter Verify  
5. 完成後本表改 **Done**，並在 `CHANGELOG` 一行

---

## 刷新盤點

```bash
rg -n --glob '*.vue' -e 'SC\s*#' -e '課程\s*#' -e '帳單\s*#' -e 'COURSE-' -e '學生\s*#' -e 'Payment\s*#' frontend/src
```
