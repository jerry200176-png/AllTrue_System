# UX Internal Identifier Audit

> **目的**：盤點直接顯示給一般使用者（主任／老師／家長）的內部 ID。  
> **原則**：非工程人員不應依賴內部 ID 完成工作。  
> **本輪範圍**：只盤點，不全部修改。  
> **盤點日期**：2026-07-16  
> **觸發**：in-app #200（重疊審核主標籤 `SC #`）

---

## 本輪已修（#200 Minimal Fix）

| 位置 | 原顯示 | 修正 |
|------|--------|------|
| `DuplicateSessionReviewPage.vue` | 主標籤 `SC #{{ student_class_id }}` | `studentClassDisplay`：科目 · 老師 · 開課 · 堂數；SC 僅小字 `aria-hidden` |

共用 formatter：`frontend/src/lib/studentClassDisplay.js`

---

## 盤點結果（User-facing leaks）

| 優先 | 識別符 | 檔案 | 使用者可見文案 | 角色 | ROI 建議 |
|------|--------|------|----------------|------|----------|
| **P0** | `SC #` / StudentClassID | DuplicateSessionReviewPage | ~~主標籤 SC #~~ → **本輪已修** | 主任 | Done |
| P1 | `課程 #id` | TuitionCollectionPage.vue（結清確認） | `課程 #{{ settleTarget.id }}`、`新課程 #{{ newer_course_id }}` | 主任 | 改為科目·學生·開課日 |
| P1 | `課程 #id` | CourseManagement.vue / StudentsList.vue | toast／alert「新批次課程 #…」 | 主任 | 改為科目 + 學生名 |
| P1 | `COURSE-000xxx` | CourseManagement.vue、AccountingLedgerModal.vue | 帳單副標 fallback `COURSE-${student_class_id}` | 主任 | 有 `course_ref` 時優先；fallback 改科目 |
| P1 | `帳單 #invoice_id` / `Payment #` | AccountingLedgerModal.vue | 明細 small / detail | 主任 | 改收據號碼／日期／金額 |
| P2 | `課程 #course_id` | BulkLeaveModal.vue | 錯誤列 `課程 #{{ s.course_id }}` | 主任 | 改科目 + 日期 |
| P2 | `學生 #id` | DuplicateSessionReviewPage、DirectorDashboard、LearningRecordsPage | fallback 當無名時 | 主任／老師 | 保留 fallback；確保 API 盡量帶 name |
| P3 | `payment_id=` | ReceiptModal.vue | 僅 query／body，非主文案 | — | 維持（非展示） |
| — | `student_class_id` as `:key` / API param | 多處 | **非使用者可見** | — | OK，不改 |

### 明確排除（非外洩）

- Vue `:key="row.student_class_id"`、API query `student_class_id=`
- Telemetry 刻意剝離 ID（`adoptionTelemetry.js`）
- Formatter 內 `techId` 小字（技術識別，非決策主標）

---

## 後續消除順序（依 ROI，不一次全改）

1. **帳務／續報 toast**（P1）：主任每天看到 → 用科目＋學生名  
2. **AccountingLedger COURSE- / 帳單 #**（P1）：核帳路徑  
3. **BulkLeave 錯誤列**（P2）  
4. **學生 # fallback**（P2）：補 API name 覆蓋率優於改字串  

每批沿用 #200 formatter 精神：人類欄位優先，內部 ID 僅 tech 小字或完全隱藏。

---

## 搜尋指令（刷新盤點）

```bash
rg -n --glob '*.vue' -e 'SC\s*#' -e '課程\s*#' -e '帳單\s*#' -e 'COURSE-' -e '學生\s*#' frontend/src
```
