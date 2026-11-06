# UI Foundation — migration sequencing

Companion to [`ALLTRUE_UI_FOUNDATION.md`](ALLTRUE_UI_FOUNDATION.md) (look and rules) and [`UI_AUDIT_2026-07-26.md`](UI_AUDIT_2026-07-26.md). Decision: #4659 (shadcn-vue template, Apple-style look). This is the single order; the older At*-pilot wave tables are retired.

## Order

| # | Step | Status |
|---|---|---|
| 1 | PR-0 template foundation (shadcn-vue on `--ds-*`) | done, #4951 |
| 2 | 主任首頁 (next) | #4949 |
| 3 | 課程管理 | #4918 |
| 4 | App shell (sidebar, top bar, mobile tabs) | planned |
| 5 | 老師首頁 | planned |
| 6 | 帳務中心 v3 | planned |
| 7 | Remaining pages, one PR each | planned |
| P | Parent track #4986 | step 1 #4987; steps 2-6 planned |

The Apple-style look is delivered per page, not by changing global tokens; global `--ds-*` values stay until the last pages migrate. Every migrated page uses `components/ui` plus the `ParentHome.vue` pattern (small label over big value, one dark primary pill). `At*` components are not restyled; they retire as pages move.

## Constraints that outlive the waves

- SmartCalendar: visual change only, no occurrence merge / G-007 paths.
- Billing / tuition: no payment, `Paid` or invoice logic changes.
- Attendance: no RFID / deduction path changes.
- Director home keeps its work-queue structure: priority data first, secondary analysis on demand, no duplicate counts, primary CTA visible at 390.

## Definition of done per page

1. Uses `components/ui` + foundation tokens (no new literals, no unused primitives).
2. Unit/a11y coverage for new controls.
3. Real Vue Playwright evidence at 390 / 768 / 1440 (mocked API), plus `toHaveScreenshot` baselines at 1280 and 390 (`npm run test:visual:update`, reviewer looks at the PNG diff).
4. Fixtures stay out of `public/` / `dist_build`.
5. No API / DB / permission / billing logic changes.
6. No unintended horizontal overflow at 390/412/768/1280/1440; primary CTA visible at the two mobile widths; for the director home, a production read-only smoke walk after deploy.
7. Design QA (in this order, vendored under `.claude/skills/`): `impeccable` critique/audit, then polish; the `make-interfaces-feel-better` checklist; the existing `web-design-guidelines` review; `ui-ux-pro-max` for data-heavy pages (tables, forms, charts). Brand: ink #0B0B0C + gold #F7D917/#F2C40F, amber #E9A51A, red only for danger; body >=16px, touch targets >=44px.

## Motion layer

Copy-paste source like shadcn, never npm dependencies. Sources: Inspira UI (`unovue/inspira-ui`, MIT), Vue Bits (`DavidHDev/vue-bits`, MIT + Commons Clause: use in the app is fine, reselling is not), Uiverse (`uiverse-io/galaxy`, MIT, CSS). Each copied component carries a header comment naming upstream, SHA and licence.

- Where: Login; parent home; director home (number count-up, gold shine border on the primary action, blur-fade entrance); page and dialog transitions; notifications list.
- `prefers-reduced-motion` turns all motion off.
- No WebGL or particle backgrounds on daily staff work screens (attendance, calendar, billing, course management). Motion never delays input.
- UI feedback is at most 300 ms.
- Brand colours only: ink and gold.
- Visual baselines are captured with motion disabled.
