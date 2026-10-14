## 2026-10-14 — fix(deploy): 部署後驗證送出可被防火牆接受的 User-Agent
<!-- release-notes: silent_ship=silent-2026-10-14-post-deploy-verify-user-agent -->
<!-- silent-reason: 只改部署流程的自我檢查腳本，使用者畫面與 API 都不變 -->
- `scripts/post-deploy-verify.py` 改送 `AllTrue-post-deploy-verify/1`。正式站防火牆對 Python 預設的 `Python-urllib` 一律回 403，導致 2026-10-14 18:17 班部署（`3b8dd79`）與自動 rollback 都被判定失敗；實際上站台健康、curl smoke 全綠。
- 新增測試：模擬防火牆擋 `Python-urllib` 的本機伺服器，驗證腳本必須通過。
