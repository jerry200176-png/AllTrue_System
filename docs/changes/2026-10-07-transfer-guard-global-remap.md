## 2026-10-07 — fix(schedule): 轉課 that adds a weekday no longer gets a false 409 (#3502)
<!-- release-notes: silent_ship=silent-2026-10-07-transfer-guard-global-remap -->
<!-- silent-reason: 只移除轉課時的一種誤擋，真正的時段衝突照樣擋，畫面不變。 -->
- 轉課換新時段且新增週幾時，防撞改用與同步相同的 `planFutureSync()` 落點判斷（同 #3699 的課程編輯修正），不再把「多出來那堂」誤判為自我重疊。
