## 2026-10-29 — fix(attendance): a retried identical 補卡 adds no second audit row (#4144)
<!-- release-notes: silent_ship=silent-2026-10-29-teacher-adjust-idempotent -->
<!-- silent-reason: Only a duplicate request (double click or network retry) behaves differently; the screen shows the same result. -->
- `POST /teacher-attendance/{id}/adjust` returns the existing adjustment only for an exact retry (same actor, reason and times, within 2 minutes); anything else writes its own audit row (#4144 item 3).
