## 2026-11-07 — feat(telegram): directors review and unbind Telegram chats (#5012)
<!-- release-notes: staff_update=staff-2026-11-07-telegram-binding-review -->
- LINE 通知設定 page: 「Telegram 綁定確認」 lists the campus's bound chats (`GET /telegram/bindings`) and 解除 clears one slot (`POST /telegram/bindings/unbind`, audited in `student_identity_audit_logs`). Director-only, campus-scoped; Telegram name via best-effort `getChat`.
