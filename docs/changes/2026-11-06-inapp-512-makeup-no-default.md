## 2026-11-06 — fix(attendance): 待補點名 no default status, monthly top-up capped at SessionCount, void reverts completed (in-app #512)
<!-- release-notes: staff_update=staff-2026-11-06-makeup-no-default -->
- 待補點名 row had 到班 preselected; one click on 補登 deducted a lesson the student never attended. The select now starts empty and 補登 stays disabled until a status is chosen.
- `ensureMonthlyFutureScheduledSessions` (date mode) no longer plans past `SessionCount` (4 bought, 5 Sundays in the period created 5 lessons after a start-date rebuild that already planned 4).
- Voiding the sign-in that debited a `completed` lesson now returns the lesson to `scheduled` (was only attended/late/absent), once no other live sign-in remains.
