## 2026-10-08 — feat(billing): 面板內一次登記多份合約收款（D18/D19，Founder GO #3797） (UX B-PR8)
<!-- release-notes: staff_update=staff-2026-10-08-panel-allocate -->
- 學生帳務面板底部「登記收款」改為面板內的分配步驟（`PaymentAllocation.vue`）：每份未繳（或還沒開帳單）的合約一格金額，預設把最舊合約填到它的未繳餘額；選填「實收總額」會從最舊的開始分配；某份金額超過該合約未繳或總額超過全部未繳時擋下並說明（D19）。
- 送出走既有 `POST payment-reports/director-record-batch`（每份合約一筆 entry，附最舊未繳帳單）。因為這個端點不是全成功或全失敗，結果畫面逐份寫「已登記 NT$ x」或「沒有登記：原因」，部分成功不會顯示成功；只重送沒登記的那幾份。合約卡片上的「登記這筆」仍開原本的單一合約視窗。後端與金額計算不變。
