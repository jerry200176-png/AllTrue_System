## 2026-10-17 — fix(scheduling): connect guarded renewal-shift previews (#4210)
<!-- release-notes: staff_update=staff-2026-10-27-successor-preview-availability -->
- Reuse the existing preview/token API and confirmation UI in Course Management, calendar and attendance; submit only the confirmed plan.
- Resolve exact materialized calendar occurrences and distinguish same-day lessons by session identity; provide blocker navigation through the existing calendar context.
- Fail closed on malformed, stale or blocked previews and retain existing campus, teacher, ordinary leave and retro-leave boundaries.
- Preview reports the current apply capability without changing the plan token. Disabled or unknown capability cannot be confirmed or fall through to an ordinary write; the apply flag remains OFF.
- Attendance captures operation ownership before awaiting the token and invalidates it on context change or unmount.
