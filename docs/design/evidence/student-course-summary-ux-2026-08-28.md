# Student course summary UX — 2026-08-28

> Issue: [#2007](https://github.com/jerry200176-png/AllTrue_System/issues/2007)
> 
> Decision: make the expanded student master record task-first and scannable;
> keep course data and every existing mutation handler unchanged.

## Decision and boundary

The first slice changes only the active-course presentation inside
`frontend/src/pages/StudentsList.vue`. It does not change API routes, request
payloads, branch filtering, authorization, billing semantics, attendance,
scheduling, or database data. Existing handlers remain the source of truth for
payment status, renewal/add-on, invoice, payment information, edit, settlement,
and delete actions.

The intended behavior is closer to a learning progress cue than to
gamification: show what is true, show the next useful action, and keep advanced
operations available without making every action compete for first attention.
Duolingo documents a clear path on the home screen and has documented separating
the daily goal from streak mechanics; AllTrue adopts the clarity and separation,
not XP, streaks, leaderboards, or invented operational scores:
[clear path](https://blog.duolingo.com/new-duolingo-home-screen-design/) ·
[daily goal and streak separation](https://blog.duolingo.com/improving-the-streak/).

## Target-system evidence

Locally verified on the `c44ea6aff907d79f8ea80da56edd06619e899e32` baseline:

- The expanded student record currently renders an active-course table with
  nine columns (`StudentsList.vue:206-218`), which forces schedule, billing,
  teacher, progress, and actions into one wide scan.
- A course can render a payment-status control plus renewal/add-on, invoice,
  payment information, edit, settlement, and delete actions in the same row
  (`StudentsList.vue:295-315`). This is functionally complete but visually
  flattens primary and secondary decisions.
- Course data is already loaded through the branch-scoped student-class API and
  normalized locally (`StudentsList.vue:1396-1467`, `1469-1547`). The slice
  consumes those fields only; it adds no request or response contract.
- Historical courses already have a separate collapsible presentation. This
  slice changes active courses only and leaves history behavior intact.

## Evidence triangulation

### Official/product evidence

- Duolingo's official product writing describes a guided path that makes the
  next step clear, and separately describes progress toward a daily goal. The
  transferable pattern is explicit progress plus one next action; the
  non-transferable parts are consumer learning mechanics and competition.
- GitLab Pajamas' progress-bar guidance provides the relevant operational
  pattern: a progress indicator should expose a meaningful value and label,
  rather than relying on color alone. See
  [Pajamas progress bar](https://design.gitlab.com/components/progress-bar/).
- GitHub Primer's component guidance supports a consistent component language
  and accessible state communication. See
  [Primer components](https://primer.github.io/design/components/).

### Maintained open-source implementations

| Project | Pinned evidence | License / maintenance | Reusable principle |
|---|---|---|---|
| [GibbonEdu/core](https://github.com/GibbonEdu/core) | `v31.0.00`, commit `76b5286f81e17dcf793ab7357e410aa2dcd00ca4`; `modules/Students/student_view_details.php:565-610,1528-1535,2482-2737` | GPL-3.0; default branch is the current `v31.0.00` line, pushed 2026-08-24 | A student record composes timetable and attendance views in one record context, while access checks stay server-side. We borrow the record-centric hierarchy, not code. |
| [frappe/frappe](https://github.com/frappe/frappe) | `develop`, commit `013f68771ac342c70dc5886c9fe94b50e74fcacb`; `frappe/public/js/frappe/ui/sidebar/sidebar.js:464-500,611-735` and `frappe/public/js/frappe/list/list_view.js:333-358,770-809` | MIT; actively maintained develop branch pushed 2026-08-28 | A list surface has a clear primary action and filters, while the form/record surface carries detail and state. We borrow the distinction and progressive disclosure, not code. |

No source code is copied from either project; their licenses therefore create
no dependency or attribution change for this slice.

### Live behavior boundary

The prior public Duolingo capture in this workstream was unauthenticated and
limited to the public shell; it did not prove account-specific behavior or
internal architecture. A later bounded public read was rate-limited (HTTP 429),
so this decision relies on official public material for product claims and on
the local implementation for AllTrue behavior. No private account or
credentialed product data is used.

## Adapted information architecture

```text
學生：王小明                                  [學生資料操作]
課程 2 筆 · 目前最需要處理：英文剩 2 堂
┌─────────────────────────────────────────────────────────────┐
│ 英文  一對一  需續報                         [續報加購]      │
│ 剩餘 2 / 8 堂     ██████░░░░  已使用 6 堂                    │
│ 老師 林老師  ·  週二 18:00–20:00  ·  大安                   │
│ 未繳費 · 最近繳費資訊／備註（需要時閱讀）       [更多操作 ▾] │
└─────────────────────────────────────────────────────────────┘
┌─────────────────────────────────────────────────────────────┐
│ 數學  一對二  進行中                         [編輯課程]      │
│ 剩餘 6 / 12 堂    ██████████░░  已使用 6 堂                │
│ 老師 王老師  ·  週四 19:00–21:00  ·  新店                   │
│ 已繳費／月結 cadence／備註（次要資訊）          [更多操作 ▾] │
└─────────────────────────────────────────────────────────────┘
```

Rules:

1. Header answers “這是哪一門課、現在是什麼狀態”。
2. Progress answers “剩多少”，with units and accessible text; monthly courses
   show cadence and never receive a fake percentage.
3. One primary action is selected from an existing handler. Low session balance
   surfaces `續報加購`; otherwise `編輯課程` remains the primary entry.
4. Payment, invoice, details, settlement, and delete remain available under a
   native keyboard-accessible disclosure. Hiding visual noise must not remove
   capability.
5. Notes and latest payment summaries stay visible as secondary context, but do
   not compete with subject, status, progress, or the primary action.

## Acceptance, tests, and rollback

- 390/412/768/1280/1440px: cards remain readable with no required horizontal
  scroll; action controls remain at least 44px touch targets.
- Session progress exposes `role="progressbar"`, min/max/value, a text label,
  and the same remaining/total units. Missing totals show an honest “堂數未
  設定” state rather than an invented bar.
- Monthly, package, paused, low-balance, unpaid, paid, long-note, empty-active,
  and historical states keep their current semantics.
- Focused tests assert the new hierarchy and all preserved handler markers;
  `npm run lint:no-undef`, `npm run build`, and the existing UI foundation smoke
  suite are required. The production release gate remains CI → merge → deploy
  → version/health/smoke.
- Rollback is a source revert. There is no API, migration, or production-data
  write in this slice.

## Follow-up measurement

After release, compare the director's time-to-first-course-action and the rate
of opening the secondary disclosure against the previous table presentation.
Do not add telemetry or change data contracts in this slice; use existing
production-safe analytics/evidence only if already available. Issue #2007 stays
open for the broader course-management IA cleanup and other page-level debt.
