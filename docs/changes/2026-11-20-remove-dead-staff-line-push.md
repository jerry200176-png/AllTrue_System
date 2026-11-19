## 2026-11-20 — chore(notifications): remove the staff LINE push that never fired (TD-065)
<!-- release-notes: silent_ship=silent-2026-11-20-remove-dead-staff-line-push -->
<!-- silent-reason: 刪除從未觸發過的員工 LINE 推播程式；實際行為（不推）與畫面都不變。 -->

- 員工高嚴重度通知的 LINE 推播（`NotificationObserver` → `NotificationLineDispatcher`）因註冊位置錯誤從未觸發，CI 實測確認；依 Founder 2026-11-19 決定刪除。新增測試擋住之後把 model observer 註冊在 `AppServiceProvider::boot()`（必須放在 `booted()`）。
