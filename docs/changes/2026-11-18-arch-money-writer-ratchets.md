## 2026-11-18 — chore(arch): two shrink-only checks for money (who writes it, who computes it)
<!-- release-notes: silent_ship=silent-2026-11-18-arch-money-writer-ratchets -->
<!-- silent-reason: 只新增 CI 檢查與基準數字，不變更產品畫面、資料或操作。 -->

- 架構檢查新增兩項，數字只能變小：寫入合約「已繳／應收金額」的產品程式檔（現在十三個）、前端自己用單價乘堂數算錢的檔（現在兩個）。新程式要走帳務服務、畫面只顯示伺服器給的金額。
- 屬於 #4991 根因家族「錢」的預防措施；不改任何既有行為。一次性資料修復程式不列入計數。
