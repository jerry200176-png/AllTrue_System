# 家長首頁與夜間堂數檢查 UX 研究

> 研究日期：2026-08-20
>
> 目的：在不新增資料模型或第二套狀態來源的前提下，改善家長登入後的下一步可見性，並讓主任知道「夜間對帳」實際檢查什麼。
>
> 執行範圍：家長 `ParentPortal`、主任 `NightlyReconcilePanel`、既有 Issue #912 與 #1080。這次不新增 Issue。

## 1. AllTrue 現況與決策

現有家長 dashboard 已提供 `progress_summary`（本週學習、下次課程、`pending_actions`、繳費狀態）、近期課程的請假狀態、帳務提醒與逐堂評量回饋。缺口不是「沒有資料」，而是登入後要自己到三個分頁找下一步。

本次採用低風險切片：以既有回應資料產生唯讀的「需要留意」摘要，每列跳到學習、課表或帳務分頁。它只呈現能由現有資料證明的狀態，不宣稱跨來源的完成狀態，也不暴露 session/class ID。

跨來源、可排序的「最近處理狀態」時間線與主動通知仍屬 #912 的後續資料契約工作；不在這次用前端猜測補出來。

夜間頁的正式語意固定為「夜間堂數檢查」：

1. 課程記錄的已用堂數。
2. `SessionDeductionService` 依扣堂規則算出的權威應用堂數。
3. `ClassSession` 的已到班、完成、遲到等出席證據。

差異只產生報告與通知，不自動改寫堂數；它不是銀行或學費入帳對帳。真正的資料修復仍需備份、核准與回滾流程。

## 2. 外部證據

| 證據 | 可驗證觀察 | 本次採用 |
|---|---|---|
| [Seesaw：家長導覽](https://help.seesaw.me/hc/en-us/articles/203729445-Navigating-Seesaw-as-a-family-member) | 家長登入後先看到孩子的 Home／journal 與教師回饋，Messages、Journals、Notifications 是清楚的後續入口。 | 把家長最需要知道的下一步放在首頁；完整紀錄仍留在分頁。 |
| [Seesaw：Messages](https://help.seesaw.me/hc/en-us/articles/115003335423-How-do-families-use-Messages) | 家長與教師／主任的溝通集中在同一處，且支援多孩子切換。 | 清楚寫出「收到回覆」與目的地，不把家長丟回整個頁面找。 |
| [Seesaw：通知設定](https://help.seesaw.me/hc/en-gb/articles/206528005-How-to-Manage-Family-Account-Notifications) | 通知頻率與內容分開管理，提供 All／Once Per Day／Never。 | 本次只做站內摘要，不假裝已完成 LINE 主動通知；通知整合仍留在 #912。 |
| [ERPNext：Bank Reconciliation](https://docs.frappe.io/erpnext/bank-reconciliation) | 對帳的目標是比較 statement 與 ledger，差異代表待釐清項目，最後才追求差額為零。 | 在 AllTrue 明確區分堂數檢查與銀行／學費對帳，異常先人工確認。 |
| [ERPNext：Banking](https://docs.frappe.io/erpnext/banking-in-erpnext) | statement-side transaction 與 payment／journal 是不同資料來源，需保留來源與未對帳狀態。 | 夜間頁用三個來源的白話說明，避免把「對帳」誤解成收款核銷。 |
| [Stripe：Reporting and reconciliation](https://docs.stripe.com/plan-integration/get-started/reporting-reconciliation?locale=en-GB) | 以不可變的 line-level balance transactions 作為報表與 payout reconciliation 的依據。 | AllTrue 堂數檢查保持診斷與來源證據，不提供無法追溯的自動修正按鈕。 |

## 3. 開源實作交叉檢查

- [GibbonEdu/core `v31.0.00`](https://github.com/GibbonEdu/core/tree/v31.0.00)（GPL-3.0）在 [student_view_details.php](https://github.com/GibbonEdu/core/blob/v31.0.00/modules/Students/student_view_details.php) 的學生主檔頁整合 timetable 與 attendance/history 視圖。這支持「家長首頁先有總覽、細節仍依情境展開」的取捨，但不表示 AllTrue 要合併所有頁面。
- [ERPNext `bank_reconciliation_tool.py`](https://github.com/frappe/erpnext/blob/2328e6da94d3787180251a339384e4ecfebdbef5/erpnext/accounts/doctype/bank_reconciliation_tool/bank_reconciliation_tool.py) 與同目錄測試（commit `2328e6da94d3787180251a339384e4ecfebdbef5`，GPL-3.0）保留 auto-reconcile、no match、partial reconcile 與已對帳數量的測試案例。AllTrue 因此只在有明確差異時呈現人工確認，不把診斷頁變成無審批修復工具。
- 自家 [Backer Web](https://backerwebapp.netlify.app/) 的公開 shell 觀察到 dashboard／mobile drawer 的工作情境切換；登入後資料與權限未以公開頁面驗證，故只採用「工作情境清楚、行動版可進入」的結構，不把未驗證流程當成產品證據。

## 4. 驗收與未完成項

- 家長首頁：有請假等待確認、新回覆、帳務提醒、可留言或今日課程時，首頁出現對應摘要；點擊後進入正確分頁；無項目時有白話空狀態。
- 夜間頁：標題、摘要卡、說明區與欄位名稱不再把它混成銀行／學費對帳；異常狀態保留唯讀與人工修復邊界。
- 測試：純函式覆蓋資料缺省、請假、新回覆、帳務、留言與今日課程；既有夜間對帳 composable、release-notes、lint、build 仍須全綠。
- 未完成：#912 的跨來源時間線、回覆／請假／繳費的統一狀態語意與 LINE 主動通知；#1080 的 canonical read model／forward materialization。這兩者不因本次 UI 說明改善而標記完成。
