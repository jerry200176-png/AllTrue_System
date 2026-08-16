---
owner: jerry (CEO)
status: Phase 1 in this PR; Phase 2 not started
review_cycle: as-needed
last_reviewed: 2026-08-16
---

# RFC: 行政已回報 ≠ 會計已入帳（繳費狀態拆分）

> **Status:** Phase 1 實作中（行政登錄 = pending；confirm 才 Paid／收據）。Phase 2 尚未開始。  
> **Date:** 2026-08-16  
> **Issue:** [#1827](https://github.com/jerry200176-png/AllTrue_System/issues/1827)  
> **Debt / lessons:** `docs/TECH_DEBT.md` TD-080 · F7 / R95 已繳費雙真相 · `docs/DIRECTOR_PAYMENT_ALERT_RULES.md`  
> **Related (do not solve here):** TD-068 Receipt Domain T3（法定完整收據／PDF）  
> **Source:** 嗨森建議（行政／會計分工、批次回報、課程頁看帳務）。示意圖只當概念，不是畫面規格。

Agent 在改 `PaymentReportController`、`PaymentEntryModal`、催繳名單、課程管理繳費切換、或 `StudentClass.Paid` 寫入路徑前，必須讀本檔。若實作把「行政登錄」再次做成一次 `Paid=1`＋核銷＋開收據，停下來。

---

## 1. Problem

現行主任／行政「核帳登記」走 `POST /api/v1/payment-reports/director-record`，**一筆請求同時**：

1. 建 `Payment`
2. 更新 `Invoice` 為 paid／partial，並寫 `reconciled_at` / `reconciled_by`
3. 課程 `StudentClass.Paid=1`、`PayDate`
4. `PaymentReport.status=confirmed`
5. 前端可立刻開電子收據

這是 2026-04「核帳簡化（移除家長自填）」的刻意設計，不是漏做。現場痛點是作業被這條捷徑卡住：

| 現場 | 系統 |
|---|---|
| 行政看官方 LINE 想先登錄 | 一登就等於正式入帳 |
| 會計還沒對銀行 | 不敢按核帳 → 催繳與名單停在未繳、或用口頭帳 |
| 家長說繳了但還沒匯 | 沒有「已回報、未入帳」；若硬登會假結清 |

課程管理已能開 `PaymentEntryModal`／帳單／對帳，但仍常要到收費頁再搜一次名字。批次一次登多筆也不存在。

---

## 2. Goal

行政先記「家長宣稱已繳」，會計對到銀行後才變成帳上已繳並開收據。

```text
未繳費
  → 行政登錄已回報（LINE／口頭；日期、方式、後五碼、金額、備註）
  → pending_report（催繳仍在；標籤「已回報、待對帳」）
  → 會計確認入帳 → Paid + reconciled + 電子收據
  → 對不到／未匯 → reject → 回到未繳費
```

**硬規則：** 行政登錄不得把課程顯示成綠色「已繳費」，也不得開收據。

---

## 3. Non-goals

| 不要做 | 原因 |
|---|---|
| 照示意圖做一站式行銷風大表 | 帳務是 Ops 密度畫面；品牌大氣氛只在 Auth／Landing |
| 行政一登 `Paid=1` | 催繳、家長徽章、月結提醒會吃假已繳 |
| 本 epic 做法定電子發票／immutable PDF | TD-068；電子收據仍是內部繳費證明 |
| Phase 1 就做 LINE／Email／SMS 自動推收據 | 跟狀態機拆分解耦；可另開 follow-up |
| 改 `alerts/tuition` **列入條件** | 未繳（含已回報未入帳）仍要催；只動顯示用 `payment_status` |
| 與排課 occurrence RFC、Course Continuity 同 PR | 不同 bounded context |

---

## 4. Current vs target（狀態機）

後端其實已有 `pending` / `confirm` / `reject` 與 `payment_status=pending_report`。缺口是 **`directorRecord` 跳過 pending，直接 confirmed**。

| 語意 | 現況 `directorRecord` | 目標 |
|---|---|---|
| 家長／行政宣稱已繳 | 不存在（直接已繳） | `PaymentReport.status=pending` |
| 課程 `Paid` | 立刻 `1` | 仍 `0`，直到 confirm |
| Invoice / Payment | 立刻建 Payment 並可能結清 | confirm 時才建／結清（pending 不寫收款） |
| `reconciled_at` | 立刻寫 | 僅 confirm |
| 電子收據 | 可立刻開 | 僅 confirmed report |
| 催繳列入 | 已繳則依規則可能離開 unpaid | 仍 unpaid／pending_report，**不得當 paid 排除** |

顯示用六態維持後端計算、前端不自行推導：`unpaid` / `partial` / `pending_report` / `paid` / `renew_needed` / `monthly_due_soon`。`pending_report` 優先於 `unpaid`（既有 `computePaymentStatus` 順序）。

### 權限

| 角色 | Phase 1 | Phase 2 |
|---|---|---|
| 行政／主任 | 登錄已回報、看狀態 | 批次登錄；課程頁看該生帳務 |
| 會計 | confirm / reject（可暫與主任同 API，前端收斂入口） | 待核銷工作台批次核銷；預設不改課程主檔 |
| 家長 | 仍見未結清，直到 confirm | 可顯示「已回報審核中」文案（非已繳費） |

分校隔離不變：`CampusID` / `auth_campus_ids`。

### API 邊界（Phase 1 實作時鎖定，本檔先定契約）

**建議（最小改動）：** 把 `directorRecord` 改成只建立 `status=pending` 的 `PaymentReport`（不建 `Payment`、不改 `Paid`、不寫 `reconciled_at`）。確認繼續走既有 `PUT .../confirm`；退回走 `PUT .../reject`。

若必須保留「主任當場現金且會計已在現場」的捷徑：另開明確 endpoint（例如 `director-record-and-confirm`），預設 UI **不要**用它；測試證明兩條路徑不會雙寫。禁止在同一個「行政看 LINE」按鈕上走捷徑。

防重（既有、必須保留）：課程已 `Paid=1` 或已有 paid Invoice → 422 `course_already_paid`。Pending 重複登錄：同一 `StudentClass` 已有 pending report → 422 或冪等回傳既有 id（實作 PR 二選一並寫測試）。

---

## 5. Phases

Worktrees: `agent-start alltrue <task-id>`。實作 PR **Refs #1827**；最後一個完成驗收的 PR 才 **Closes #1827**。

### Phase 0 — 本 PR（docs only）

- 本 RFC、INDEX、TD-080、CHANGELOG silent_ship
- 無 `backend/` / `frontend/src/` 行為變更

### Phase 1 — 狀態語意（T3／R2）— **本 epic 第一步實作**

**Finish:** 行政可登錄且不假結清；會計 confirm 後才收據。

Deliverables:

1. `directorRecord`（或後繼 API）只寫 pending report。
2. `PaymentEntryModal`／課程管理切未繳→已繳：文案改為「登記已回報」，成功後狀態 `pending_report`，不是 `paid`。
3. `TuitionCollectionPage`：`pending_report` 顯示確認入帳／退回（既有按鈕可重用）。
4. `confirm` 才建 Payment、更新 Invoice、`Paid=1`、開收據路徑。
5. `reject` 清 pending；課程維持 unpaid。
6. 測試：`PaymentReportApiTest`、`TuitionAlertsApiTest`（pending 仍列入催繳；`payment_status=pending_report`）、家長端不顯示已繳（R38）。
7. 同步 `DIRECTOR_PAYMENT_ALERT_RULES.md`：**列入條件不改**；補充「已回報待對帳」顯示規則。產品方已在 #1827 同意此方向；改 `AlertController` 顯示欄位仍須測試，禁止順便改列入 query。

**Rollback:** revert SHA。Pending 列可 reject；不得留下 Paid=1 而無 Payment 的半套。

### Phase 2 — 同學生脈絡＋批次（T2 UI）— **Phase 1 merge 之後**

**Finish:** 不必換頁重搜姓名；一次可登 ≥5 筆。

Deliverables:

1. 課程管理：同一學生卡／同一搜尋結果內切換「課程資料｜帳務資料」（繳費日、課程期間自動帶帳單、方式、後五碼、收據狀態）。不要做成第二套帳務模組。
2. 行政「今日批次回報」：共用繳費日／方式／備註，多列學生＋後五碼＋金額；送出 = 多筆 pending。
3. 會計批次核銷：對帳戶＋後五碼勾選 confirm。
4. 權限：會計預設看不到改課／排課寫入；主任可看全程。
5. UI：Ops／表格密度、design tokens、無 mesh；Primary CTA 每區 ≤ 1。

**Out of Phase 2:** 多通道自動發送收據；Excel 匯入可列 follow-up。

---

## 6. Risks

| 風險 | 緩解 |
|---|---|
| 催繳把 pending 當 paid 拿掉 | 列入條件繼續用 `Paid != 1`；測試鎖 pending 仍出現 |
| 兩套「已繳」判斷（F7／R95） | 新路徑只經 PaymentReport 狀態機；禁止第三種 Paid 推導 |
| 現金當日入帳變慢 | 會計 confirm 工作台；可選明確「當場核銷」endpoint，不當預設 |
| 前端再超前後端開收據（R79） | 收據只 `payment-reports/{id}/receipt` 且 status=confirmed |
| 跨校看到別人的待核銷 | campus filter 測試 |

---

## 7. Founder decisions（已拍板）

- **D1：** 兩步走：先狀態機，後同畫面＋批次。
- **D2：** 行政已回報 ≠ 已繳費；收據綁會計入帳。
- **D3：** 示意圖非實作規格。
- **D4：** 不在本 epic 做 TD-068 法定收據。

未決（Phase 1 PR 可帶預設，需在 PR 寫明）：

- 同一課程第二筆 pending：拒絕 vs 冪等。
- 是否保留「當場 confirm」捷徑 endpoint。

---

## 8. Verification

Phase 0：`node scripts/docs-integrity-check.mjs --strict`；Presubmit CHECK 4A silent_ship。

Phase 1 最低測試（實作 PR）：

```text
行政登錄 → report pending、Paid=0、無 Payment、無收據
會計 confirm → Paid=1、有 Payment、receipt OK
reject → Paid=0、無收款、催繳仍在
pending 期間 alerts/tuition 仍列入；payment_status=pending_report
已 Paid 再 director-record → 422
跨校 403
```
