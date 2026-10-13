## 2026-10-13 — fix(billing): 帳單直接登記收款改依收款紀錄計算並設上限（#3873）
<!-- release-notes: silent_ship=silent-2026-10-13-invoice-record-payment-projection -->
<!-- silent-reason: 只改 API `POST /invoices/{id}/payments`，目前畫面沒有呼叫此路徑。 -->
- `POST /api/v1/invoices/{id}/payments` 改用和「確認繳費回報」相同的規則（R28）：已收金額以收款紀錄（Payment）計算，不再用帳單上存的 PaidAmount 往上加；金額超過帳單依收款紀錄還欠的數目會被擋下（422 `amount_exceeds_outstanding`），收款紀錄已收齊、但狀態仍顯示未繳的帳單也會被擋下（422 `course_already_paid`）。
- 未繳的月結帳單若依已上堂數重新計價，收款前先把帳單金額寫成該堂數金額，與確認回報流程一致。
- 重新計價時一併寫入與確認回報相同的帳單快照（billing_snapshot），之後改動堂次也能追溯當時收款的依據；含多門課程的帳單不依單一課程的堂數重新計價，維持帳單原金額。
