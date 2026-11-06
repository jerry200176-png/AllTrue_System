## 2026-11-06 — feat(telegram): parents link Telegram with a one-time code (#1139)
<!-- release-notes: staff_update=staff-2026-11-06-telegram-bind-code -->
- Parent portal 通知設定 shows 「綁定 Telegram 通知」 (only when the campus bot is configured): a 10-minute single-use code to send as `/start <code>`. Name binding removed; existing bound chats untouched. `bindingTrust()` reports `verified_guardian`.
