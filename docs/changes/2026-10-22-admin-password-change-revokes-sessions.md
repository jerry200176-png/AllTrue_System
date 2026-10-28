## 2026-10-22 — fix(auth): 主任／超級管理員改別人的密碼後，對方所有裝置登出並留下稽核紀錄
<!-- release-notes: staff_update=staff-2026-10-22-admin-password-change-signs-out -->

- Founder 2026-10-22：主任或超級管理員改「別人」的密碼時，結束對方所有登入並寫一筆安全稽核；不記錄密碼、雜湊或任何 token。
- 三個入口：`PUT /api/v1/profiles/{id}`（帶 `password`）、`POST /api/v1/profiles/{id}/reset-password`、`POST /api/v1/directors/{id}/reset-password`。在寫入新 `PSW` 的同一個 transaction 刪除對方的 `auth_tokens`，用新增的 `AuthToken::revokeAllForUser()`（與自己改密碼 `PUT /me`、刪除帳號相同的刪法；那些既有路徑仍各自 inline 刪除，尚未改用）；操作者自己的登入不受影響。
- 稽核：`staff.password.admin_changed`（`reason_code` 為 `admin_password_set`／`admin_password_reset`，`method` 為操作者角色，另有 `source`、`revoked_session_count`）；超級管理員重設主任沿用 `director.password.reset`（#1813）並加上 `revoked_session_count`。只存 HMAC 參照與數量。這兩種稽核都是必寫：稽核寫入失敗時整筆回滾（密碼不變、不登出、回 500），#1813 主任重設因此由「稽核失敗照樣成功」改為「稽核失敗即不重設」。
- 權限不足（例：其他分校的主任）照舊 403：不改密碼、不登出任何人、不寫稽核。自己改自己的密碼（`PUT /me`）原本就會登出其他裝置，不變；管理員在老師管理頁改自己的密碼不登出（不在本次範圍）。
- AI 分診唯讀金鑰（Sanctum `personal_access_tokens`，僅超級管理員）是另一種憑證，改密碼不會失效；要停用請到「個人資料 → 安全性」另行撤銷。
- 不需 migration。Rollback：revert 本 PR。
