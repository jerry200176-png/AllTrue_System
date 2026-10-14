## 2026-10-13 — feat(security): AI 分診唯讀金鑰後端（bug 回報只讀 API）
<!-- release-notes: silent_ship=silent-2026-10-13-triage-read-token-api -->
<!-- silent-reason: 只有超級管理員與雲端分診工具會用到；老師與主任的畫面和操作完全不變。 -->
- 新增「AI 分診唯讀金鑰」後端：超級管理員可建立／撤銷只能讀 in-app bug 回報（列表、單筆含留言、附件下載）的金鑰；金鑰預設 90 天到期（`config/triage_tokens.php`）、只存雜湊、每次使用都記到 `security_audit_events`，拿去打其他任何 API 一律 403。
