# 帳務「待核對」對照成熟產品（2026-11-02）

起因：#4790。6 門已結案月結課帳單全收，卻因 B15 `$ambiguous` 被標 review_required；畫面只說「待核對」，沒說為什麼。

## 對照
| 概念 | 成熟做法 | AllTrue 現況 | 差距 |
|---|---|---|---|
| 審核項目要帶原因 | Stripe Radar `Review.reason`（rule / manual / …）每筆審核都有機器原因碼 | `review_required` 只有布林 | 員工不知道要查什麼 |
| 帳單狀態與付款狀態分開 | Lago（AGPL-3.0）invoice `status`（draft/finalized/voided）與 `payment_status`（pending/succeeded/failed）分兩欄 | `closed_reason`（settled_pending）混了「結案」與「待收」兩件事 | 已收清仍顯示待對帳（#4795 先修畫面） |
| 人工標記「已檢查」 | Odoo（LGPL-3.0）分錄有「待檢查 / 已檢查」旗標，會計核完即消失 | 沒有「已核對」動作，settled_pending 只在收款確認時自動翻轉 | 待核對項目無法被人關閉 |

只借做法，不引入程式碼或套件（授權：Stripe 為服務 API；Lago AGPL、Odoo LGPL 僅參考）。

## 計畫（每項一個 PR，可單獨回滾）
1. ✅ 本 PR：`MonthlyPeriodPaymentService` 回傳 `review_reasons` 原因碼，課程頁「待核對」顯示原因。純顯示，不改金額判斷。
2. 帳務中心 review 清單也顯示同一組原因（AccountingController `review` 狀態列）。
3. 「已核對」動作：主任確認原因後關閉 review，留稽核紀錄（需 Founder 決定規則：誰能核、核完是否改 closed_reason）。
4. 檢討 `item_period_outside_contract`：月中開課、整月帳單是常態，是否應視為正常（改金額判斷 = R3，需 Founder 決定）。

## 驗證
- 讀取用 case `settled_pending_paid_scan` 可重跑全站檢查（runs 41409612482、41412982163）。
