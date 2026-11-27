## 2026-11-27 — feat(security): 設定與重設 PIN 留下稽核紀錄 (#2332)
<!-- release-notes: silent_ship=silent-2026-11-27-pin-audit-events -->
<!-- silent-reason: 只多寫一筆後台稽核紀錄；畫面、流程與回應都不變。 -->
- `POST /me/pin/set`、`POST /me/pin/reset`：成功時寫一筆 `security_audit_events`（`staff.pin.changed`，`reason_code` = `pin_set` / `pin_reset`）；重設時帳號密碼錯誤也寫一筆（`pin_reset_password_invalid`，outcome `failure`）。
- 紀錄內容：操作人（雜湊）、時間、角色、原因代碼。PIN、PIN 雜湊、密碼都不會寫進去。
- 寫入是 best-effort：稽核表有問題時不會讓設定 PIN 失敗（和其他自助登入類事件一致）。
