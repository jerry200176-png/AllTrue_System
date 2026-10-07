## 2026-10-07 — fix(billing): a 0-total trial has no payment obligation (in-app #361, #3203)
<!-- release-notes: silent_ship=silent-2026-10-07-zero-fee-trial-no-payment -->
<!-- silent-reason: 0 元課程不再能建立付款回報、不進學費提醒；正常收費課程不變。 -->
- 付款回報閘門與 `/alerts/tuition` 改用 resolver 的 `free` 判斷（原本只認輔導課），0 元試聽課不再被當成待繳。
