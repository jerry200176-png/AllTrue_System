## 2026-10-17 — fix(scheduling): connect guarded renewal-shift previews (#4210)
<!-- release-notes: silent_ship=silent-2026-10-17-successor-preview -->
<!-- silent-reason: Default-off renewal shifting is not enabled or announced by this UI preparation. -->
- Reuse the existing preview/token API and confirmation UI in Course Management, calendar and attendance; submit only the confirmed plan.
- Resolve exact materialized calendar occurrences and distinguish same-day lessons by session identity; provide blocker navigation through the existing calendar context.
- Fail closed on malformed, stale or blocked previews and retain existing campus, teacher, ordinary leave and retro-leave boundaries.
