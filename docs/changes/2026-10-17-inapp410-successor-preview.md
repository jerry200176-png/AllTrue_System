## 2026-10-17 — fix(scheduling): connect guarded renewal-shift previews (#4210)
<!-- release-notes: silent_ship=silent-2026-10-17-successor-preview -->
<!-- silent-reason: User-visible preview preparation is not yet deployed or directly verified; its announcement is withheld. Merging this staged code requires the explicit publication-sequencing exception, not a no-UI claim. -->
- Reuse the existing preview/token API and confirmation UI in Course Management, calendar and attendance; submit only the confirmed plan.
- Resolve exact materialized calendar occurrences and distinguish same-day lessons by session identity; provide blocker navigation through the existing calendar context.
- Fail closed on malformed, stale or blocked previews and retain existing campus, teacher, ordinary leave and retro-leave boundaries.
- Preview reports the current apply capability without changing the plan token. Disabled or unknown capability cannot be confirmed or fall through to an ordinary write; the apply flag remains OFF.
- Attendance captures operation ownership before awaiting the token and invalidates it on context change or unmount.
