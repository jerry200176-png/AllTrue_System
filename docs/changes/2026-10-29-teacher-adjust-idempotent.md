## 2026-10-29 — fix(attendance): a retried identical 補卡 adds no second audit row (#4144)
<!-- release-notes: silent_ship=silent-2026-10-29-teacher-adjust-idempotent -->
<!-- silent-reason: Only a duplicate request (double click or network retry) behaves differently; the screen shows the same result. -->
- `POST /teacher-attendance/{id}/adjust` now returns the existing adjustment when the sign-in already has exactly the requested times, instead of writing another audit row (#4144 item 3).
