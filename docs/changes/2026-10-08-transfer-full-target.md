## 2026-10-08 — fix(courses): 轉課遇到堂數已滿的目標課程，改白話提示並提供加購入口 (in-app #379)
<!-- release-notes: staff_update=staff-2026-10-08-transfer-full-target -->
- `transfer-sessions` 的 `target_capacity_exceeded` 訊息改為白話：「目標課程堂數已滿（8/8）。請先在目標課程加買堂數，再轉課。」（未滿但不夠時說明剩餘堂數；沒買堂數時另有說法）。回應新增 `next_actions: open_target_purchase`（帶目標課程 ID），轉移視窗顯示「前往目標課程加購」，接既有的加購入口。仍不會自動加堂、不搬任何紀錄。Refs #3798
