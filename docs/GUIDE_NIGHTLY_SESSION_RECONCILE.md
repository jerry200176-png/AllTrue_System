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
