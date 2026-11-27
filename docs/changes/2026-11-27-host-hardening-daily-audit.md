## 2026-11-27 — ops(security): 主機安全檢查改成每天自動跑 (#887)
<!-- release-notes: silent_ship=silent-2026-11-27-host-hardening-daily-audit -->
<!-- silent-reason: 只在每日健康檢查多一個唯讀步驟；不影響任何使用者功能。 -->
- 每日的 Pi 健康檢查（`pi-health.yml`）多一步唯讀檢查：防火牆是否啟用、SSH 是否關閉密碼登入與 root 登入、fail2ban 是否在跑、對外開的 port 是否只有 22/80/443。
- 結果只有 PASS/FAIL 與 port 號碼。有問題時在該次執行顯示警告，不會讓整個檢查變紅。
- 不改主機任何設定、不需要密碼。要修正檢查出的問題仍需另外核准。
