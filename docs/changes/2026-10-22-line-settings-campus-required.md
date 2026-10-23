## 2026-10-22 — LINE settings and the payment message require the director's campus
<!-- release-notes: silent_ship=silent-2026-10-22-line-settings-campus-required -->
<!-- silent-reason: authorization hardening; directors' own-campus LINE settings are unchanged -->

The route group for LINE status/settings, notify settings and the parent payment message now carries `require_campus`, and the campus checks in `LineWebhookController::status`/`saveSettings` and `ParentPortalController::paymentMessage` no longer pass when the caller's campus list is empty or the student has no campus. A director without an approved campus gets 403; a director's own campus works as before. Regression: `LineSettingsCampusRequiredTest`. Rollback is a normal revert.
