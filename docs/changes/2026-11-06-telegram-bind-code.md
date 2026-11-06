## 2026-11-06 — fix(telegram): bind only by one-time guardian code (#1139)
<!-- release-notes: silent_ship=silent-2026-11-06-telegram-bind-code -->
<!-- silent-reason: Telegram 綁定入口目前未啟用（webhook 未設定密鑰），家長端尚無新畫面；先備好後端綁定碼。 -->
- Telegram bot now accepts only `/start <code>` from a verified-guardian one-time code (10 min, single use); name binding removed. `bindingTrust()` reports `verified_guardian`.
