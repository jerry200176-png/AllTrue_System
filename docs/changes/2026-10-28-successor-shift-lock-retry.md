## 2026-10-28 — fix(scheduling): renewal-shift apply locks only the chosen renewal and retries a deadlock (#4210)
<!-- release-notes: silent_ship=silent-2026-10-28-successor-shift-lock-retry -->
<!-- silent-reason: 續約順延的確認寫入仍由 LEAVE_SUCCESSOR_SHIFT_APPLY_ENABLED 預設關閉，教職員看不到差異；開啟旗標的 PR 另附教職員版本卡。 -->
- Renewal-shift apply scans the student's same-subject contracts without locks, then locks only the chosen renewal and its lessons and judges it again on the current rows (#4210 P3-1); a renewal that left count mode meanwhile is still refused.
- A deadlock victim retries the whole apply (up to 3 attempts), which re-plans under locks and writes once.
- Tests now also cover a past-dated renewal lesson, a settled renewal, a live or voided sign-in on a renewal lesson, and that no blocker names another student, a classroom or another campus (#4210 P2-1).
