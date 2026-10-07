# In-app 342 / #3260 — lesson status on calendar + contract lesson list

Goal: each calendar lesson shows its real state in words (已上 / 請假 / 取消 / 還沒上, plus existing 漏點名),
and a director can open the contract's full lesson list from the calendar.

Research (enterprise-software-research): My Music Staff marks attendance with an icon on the event and keeps
"Unrecorded" distinct; Teachworks maps every lesson to Scheduled/Attended/Missed/Cancelled and lists lessons per
student; Google Calendar keeps declined events visible but struck-through. → status = glyph + text + aria,
never color-only; cancelled stays visible but muted; list per contract with status column.

Files
- frontend/src/lib/lessonStatusBadge.js (new, pure): row + now → {kind, label, text}. Extracted from
  SmartCalendar.rollCallBadge; adds `upcoming` (還沒上) for future scheduled rows.
- frontend/src/pages/SmartCalendar.vue: use the helper; legend gains 取消 / 還沒上; session modal gets
  「看全部堂次」 → course management intent `lessons`.
- frontend/src/components/... badge renderer: aria-label/title = full text.
- frontend/src/pages/CourseManagement.vue: intent `lessons` opens CourseManager on the sessions tab.
- Staff card + change fragment. AI_REGRESSION_LESSONS only if a lesson emerges.
- No change to calendarOccurrenceMerge (occurrence logic untouched, G-007); still run `npm run test:calendar`.
- Not touched: billing files (TuitionCollectionPage, AccountingLedgerModal, components/tuition/**, billing controllers).

Tests (TDD): lessonStatusBadge unit test first (attended/late/sign-in → 已上; leave family → 請假; cancelled →
取消; past scheduled → 漏點名; future scheduled → 還沒上; no row → null). Source test for the intent handoff.

Stop points: if CourseManager has no sessions tab to open, link to the course card instead (no new screen).
