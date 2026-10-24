## 2026-10-23 — fix(scheduling): renewal-shift integrity checks before the apply flag (#4210)
<!-- release-notes: silent_ship=silent-2026-10-23-successor-shift-integrity -->
<!-- silent-reason: 續約順延的確認寫入仍由 LEAVE_SUCCESSOR_SHIFT_APPLY_ENABLED 預設關閉，教職員看不到差異；只因旗標關閉才可不公告，開啟旗標的 PR 必須附 docs/staff-updates/ 教職員版本卡。 -->
- Renewal-shift preview and apply compare the plan's own destinations with each other and with the old contract's staying lessons, so the moved renewal can no longer double-book the student inside one plan.
- Apply judges successor candidates with the same rule as preview on current locking reads (legacy blank modes count as count mode); a renewal switched to date mode after preview is refused.
- The leave lesson itself is checked (live or voided attendance, deduction, approved evaluation), a settled old contract names the old course, both contract EndDates are bound into the preview token, the tail keeps the leave's exact length, and cross-day or inverted intervals refuse.
- The student check reuses the materializer's write-time guard (schedule-only bookings included); teacher and classroom blockers no longer repeat other students' names. The shift writes the same calendar leave marker as an ordinary leave and locks the selected lesson before its course.
