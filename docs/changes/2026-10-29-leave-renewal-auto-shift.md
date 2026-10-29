## 2026-10-29 — feat(scheduling): 請假時續約課自動往後排，給主任使用 (#4210)
<!-- release-notes: staff_update=staff-2026-10-29-leave-renewal-auto-shift -->
- `LEAVE_SUCCESSOR_SHIFT_APPLY_ENABLED` now defaults to on (env override kept). When a director leaves a lesson and the make-up lesson would land in a renewal that was already scheduled, the calendar leave modal, course management and the attendance page show every move first; the director confirms, and only then are the renewal lessons moved back (preview token, one transaction, no deduction on either contract).
- Founder decision 2026-10-28: 「好」 to the recommendation to open it to directors. The #4210 pre-enable checklist (undo guard, previews on all three screens, locking, retry, evidence rules) is done on main.
- Rollback: set `LEAVE_SUCCESSOR_SHIFT_APPLY_ENABLED=false`; applies are refused again, shifts already made stay and keep their undo guard.
