# UI Foundation — migration sequencing

Companion to [`ALLTRUE_UI_FOUNDATION.md`](ALLTRUE_UI_FOUNDATION.md) and [`UI_AUDIT_2026-07-26.md`](UI_AUDIT_2026-07-26.md).

## Completed / in flight

| Order | Surface | PR | Scope |
|---|---|---|---|
| 0 | Tokens + inbox-used `At*` | PR A (inbox pilot) | Structure/states only |
| 1 | 主任收件匣 | PR A | Underline tabs, header, filters, skeleton/empty/alert/badge |
| 2 | 學生列表 | PR B (this) | Header/meta, filter bar, icon buttons, empty, denser table chrome |
| 3 | 主任總覽儀表板 | Workbench redesign | Action-first hierarchy, responsive task queue, lazy secondary analysis |

## Next waves (no business logic)

### Director dashboard workbench

This is a structure and interaction redesign. It must preserve the existing API, permission, billing, leave, attendance, and approval contracts.

- Primary view: compact header → director task queue → today snapshot.
- Secondary view: schedule, payment alerts, leave inbox, evaluation review, discrepancy status, notifications, adoption history, fill-rate, and monthly statistics.
- Remove duplicate counts and low-frequency import actions from the primary view.
- Priority data loads first; secondary analysis data loads on demand.
- The parent-leave task must land on the existing director decision UI with its primary action visible on mobile.

| Wave | Surfaces | Allowed | Forbidden |
|---|---|---|---|
| 1 | TeachersList → CourseManagement chrome | Header/toolbar/filter/`shape=rect` | Schedule/charge/leave logic |
| 2 | Billing / Tuition tables | Density, toolbar placement, status text+tone | Payment rules, `Paid`/invoice OR logic |
| 3 | Attendance list chrome | Empty/loading/filter consistency | RFID / deduction paths |
| — | SmartCalendar | Read-only visual defer | Any occurrence merge / G-007 paths |

## Definition of done per page

1. Uses foundation tokens + only needed `At*` (no unused primitives).
2. Unit/a11y coverage for new controls.
3. Real Vue Playwright evidence at 390 / 768 / 1440 (mocked API).
4. Fixtures stay out of `public/` / `dist_build`.
5. No API / DB / permission / billing logic changes.

For the dashboard workbench, also require: no unintended horizontal overflow at 390/412/768/1280/1440, visible primary CTAs at the two mobile widths, and a production read-only smoke walk after deploy.
