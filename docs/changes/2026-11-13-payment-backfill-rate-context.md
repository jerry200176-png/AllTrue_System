## 2026-11-13 — fix(billing): 補登收款避免將零總額誤認為無須繳費
<!-- release-notes: staff_update=staff-2026-11-13-payment-backfill-rate-context -->

- 合約總額為零但仍有正單價時，補登表單不再誤顯示「無須繳費」。金額與入帳流程依既有規則處理；缺少單價資訊時也不直接判定免繳。
