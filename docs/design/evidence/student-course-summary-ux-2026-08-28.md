# Student course summary UX — 2026-08-28

> Issue: [#2007](https://github.com/jerry200176-png/AllTrue_System/issues/2007)
> Decision: make the expanded student master record task-first while keeping
> course data and every existing mutation handler unchanged.

## Scope and local evidence

This slice changes only active-course presentation in `frontend/src/pages/StudentsList.vue`. It adds no API route, payload, authorization, billing, attendance, scheduling, database, or production-data change. Payment status, add-on/renewal, invoice, payment information, edit, settlement, and delete continue through their existing handlers.

On baseline `c44ea6aff907d79f8ea80da56edd06619e899e32`, the expanded student record rendered active courses as a nine-column table and put seven actions in the same row (`StudentsList.vue:206-318`). Course data was already loaded through the branch-scoped student-class API (`:1396-1547`), with history in a separate collapsible view; this slice keeps both boundaries and changes scan order:

```text
英文（一對一）  即將用完                         [續報加購]
剩餘 2 / 8 堂   ██████░░░░  已使用 6 堂
老師 林老師 · 週二 18:00–20:00 · 大安
付款／費用／備註／其他操作（按需展開）           [更多操作]
```

## Four-layer evidence and adaptation

- Official/product: Duolingo describes a guided home path and daily-goal progress. We adapt “clear next step + explicit progress”, not XP, streaks, leaderboards, or invented operational scores:
  [home-screen path](https://blog.duolingo.com/new-duolingo-home-screen-design/),
  [daily goal and streak](https://blog.duolingo.com/improving-the-streak/).
- Maintained design systems: GitLab Pajamas documents labeled progress bars; Primer documents a consistent component language:
  [progress bar](https://design.gitlab.com/components/progress-bar/),
  [components](https://primer.github.io/design/components/).
- Maintained OSS implementations: [GibbonEdu/core](https://github.com/GibbonEdu/core)
  `v31.0.00`, commit `76b5286f81e17dcf793ab7357e410aa2dcd00ca4`, GPL-3.0,
  uses a student record context to compose timetable/attendance views
  (`modules/Students/student_view_details.php:565-610,1528-1535,2482-2737`).
  [Frappe](https://github.com/frappe/frappe) `develop`, commit
  `013f68771ac342c70dc5886c9fe94b50e74fcacb`, MIT, separates list primary
  actions/filters from record detail (`frappe/public/js/frappe/ui/sidebar/sidebar.js:464-500,611-735`,
  `frappe/public/js/frappe/list/list_view.js:333-358,770-809`). No code is copied.
- Target repository: local baseline, design tokens, copy rules, and UI foundation tests are the source of truth for AllTrue behavior.

The bounded public Duolingo read was unauthenticated; the retry was rate limited with HTTP 429. No private account behavior or credentials were used.

## Interaction rules

1. Header answers course and status; one primary button exposes `續報加購` for low session balance, otherwise `編輯課程`.
2. Session courses show verified remaining/total units and an accessible
   progressbar. Missing totals show `堂數未設定，請編輯課程確認。`; monthly
   courses show cadence and never receive a fake percentage.
3. Payment, invoice, payment information, settlement, and delete remain in
   native keyboard-accessible `更多操作`; hiding visual noise does not remove
   capability.
4. Cards use existing `--ds-*` tokens, keep 44px controls, wrap long notes,
   and remain readable at 390/412/768/1280/1440px.

## Verification and rollback

Focused source-contract tests cover hierarchy, honest progress/monthly states,
and all preserved handlers. Required checks are `npm run lint:no-undef`,
`npm run build`, and the real Vue UI foundation smoke suite. Rollback is a
source revert; there is no migration or production-data repair.

Issue #2007 stays open for the broader course-management IA cleanup. After
release, compare time-to-first-course-action and secondary-disclosure opens
using only existing production-safe evidence; this slice adds no telemetry.
