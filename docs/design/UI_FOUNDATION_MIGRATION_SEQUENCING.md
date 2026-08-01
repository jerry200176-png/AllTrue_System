# UI Foundation — migration sequencing

Companion to [`ALLTRUE_UI_FOUNDATION.md`](ALLTRUE_UI_FOUNDATION.md) and [`UI_AUDIT_2026-07-26.md`](UI_AUDIT_2026-07-26.md).

## Completed / in flight

| Order | Surface | PR | Scope |
|---|---|---|---|
| 0 | Tokens + inbox-used `At*` | PR A (inbox pilot) | Structure/states only |
| 1 | 主任收件匣 | PR A | Underline tabs, header, filters, skeleton/empty/alert/badge |
| 2 | 學生列表 | PR B (this) | Header/meta, filter bar, icon buttons, empty, denser table chrome |

## Next waves (no business logic)

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
