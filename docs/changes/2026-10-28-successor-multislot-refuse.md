## 2026-10-28 — fix(scheduling): renewal shift refuses a renewal with two slots on one weekday
<!-- release-notes: silent_ship=silent-2026-10-28-successor-multislot -->
<!-- silent-reason: Behind LEAVE_SUCCESSOR_SHIFT_APPLY_ENABLED (off); the flag-on PR carries the staff card. -->
- #4210 checklist (Codex r4296589467): the successor tail steps by date, so a renewal with two slots on the same weekday would skip that day's later slot and land a week late. Preview now blocks it with a clear message instead of planning it.
