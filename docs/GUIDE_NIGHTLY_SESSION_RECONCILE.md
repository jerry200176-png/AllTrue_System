# 夜間堂數對帳

> **這不是銀行對帳，也不是學費入帳勾稽。**

## 給誰看

僅 **系統管理員**（`super_admin` / `User.type=S`）側欄「夜間堂數對帳」。主任、老師、帳務中心看不到這頁。

## 它在比什麼

每天 **02:00**（Asia/Taipei）跑 `reconcile:nightly`：

| 欄位 | 意義 |
|------|------|
| `recorded_used` | `StudentClass.UsedSessions`（畫面上的已用堂數） |
| `expected_used` | `SessionDeductionService` 權威口徑：出勤觀察值與扣堂帳本取大，再套合約堂數上限／部分時數 |
| `actual_attended` | 僅 `ClassSession` 狀態為 attended／completed／late 的筆數（診斷輔助，不是判定標準） |

不一致才寫入當日 JSON 報告，並通知系統管理員。**命令本身不改任何堂數。** 修復必須另走備份、核准、回滾。

## 它不是什麼

| 容易搞混的東西 | 實際模組 |
|----------------|----------|
| 學費／發票已勾稽 | `Invoice.reconciled_at`、帳務中心 |
| 銀行對帳單 | `BankReconciliationController` |
| 課程包餘額 | `packages:reconcile` |
| 出缺勤狀態回填 | `attendance:reconcile-session-status` |
| 契約時段被堂次回寫 | `reconcileWeekTimeFieldsFromSessions` |

作業登錄把 `reconcile-nightly` 標成 `payment_reconciliation` 是錯的；正確 domain 是 `session_deduction`。

## 失敗怎麼辦

1. 看面板分類：部分時數、合約上限、計數器高估、帳本領先、出勤領先。
2. 不要在面板上找「重算」按鈕（已移除；那個 API 從不存在）。
3. 資料修復走 Repair Manifest，不是排程自動修。

## 本系統裡叫「對帳／reconcile」但不是同一件事

同名會讓人以為功能重複。實際是**不同領域**的「兩邊對一下」：

| 名稱（畫面／命令） | 誰用 | 比什麼 | 會不會改資料 |
|--------------------|------|--------|--------------|
| **夜間堂數對帳** `reconcile:nightly` | 系統管理員 | `UsedSessions` vs 扣堂權威口徑 | **否**（只告警） |
| 繳費收款／課程管理按鈕「對帳」 | 主任／行政 | 打開帳務流水（收據／例外） | 依帳務操作，不是夜間報告 |
| `Invoice.reconciled_at`／家長「已核帳」 | 帳務／家長可見狀態 | 學費發票是否已勾稽 | 帳務流程寫入 |
| `BankReconciliationController` | API／帳務 | 銀行明細 vs 系統款項 | 手動勾稽寫入 |
| `packages:reconcile` | 工程／維運（**未排程**） | 課程包 `remaining_sessions` 快取 vs ledger | 僅 `--fix` 才改 |
| `attendance:reconcile-session-status` | 維運／一次性 | `ClassSession.Status` vs 最新簽到 | 可回填狀態 |
| `learning-records:drift-check` | 夜間 03:20 | 評量 vs 堂次漂移 | 可 `--fix` |
| `sessions:audit-stranded` | 夜間 03:40 | 有剩餘堂卻無未來堂次 | **否**（稽核） |
| `reconcileWeekTimeFieldsFromSessions` | 課程編輯路徑 | 契約 week/time vs 未來堂次 | 會回寫契約欄位 |
| 課表回報管理（ScheduleDiscrepancy） | 主任 | 老師回報的課表差 | 審核流程，與堂數無關 |

**結論：** 沒有兩套「夜間學費對帳」。重複感來自中文「對帳」與英文 `reconcile` 被多模組共用。真正結構相似、但物件不同的只有：

- `reconcile:nightly`（課程已用堂數，告警）
- `packages:reconcile`（課程包餘額快取，可選 `--fix`）

兩者不要合併成一個命令：領域邊界不同，且夜間路徑禁止自動改家長可見剩餘堂數。

