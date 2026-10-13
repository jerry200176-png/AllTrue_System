## 2026-10-12 — chore(telegram): campus 15 webhook secret rotation workflow (#1139)
<!-- release-notes: silent_ship=silent-2026-10-12-telegram-webhook-secret-1139 -->
<!-- silent-reason: 只新增 Founder 手動觸發的後台流程與維運指令，合併本身不變更任何使用者可見行為，也不會執行輪替。 -->
- 新增 `pop-telegram-webhook-secret.yml`（僅限 campus 15）與 `telegram:rotate-webhook-secret` 的 token 遮蔽、失敗補償、`--rollback`、Telegram 註冊驗證；Telegram 回覆失敗日誌不再含 bot token。
- 啟用前檢查：Telegram 綁定流程必須只信任「同學生、同分校」的已驗證監護人綁定，否則指令拒絕啟用、不呼叫 Telegram 也不寫 DB（目前依姓名綁定，因此會拒絕）。
