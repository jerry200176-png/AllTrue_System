# Plan B — 月繳「本月待開帳單」清單（主任確認後開立）

Founder decisions 2026-10-06: system proposes, **campus director confirms** (option A).
Benchmark: Stripe draft invoice → finalize; ERPNext "Delivered Items To Be Billed".

## Design (lazy, no new status/table)
- A monthly contract is one period (`ScheduleMode=date`, `EndDate`). Next period = existing
  `renewMonthly` (prices, creates contract + invoice, closes old as settled/settled_pending).
- The "draft" is a **computed proposal**, not a stored invoice: nothing new can be counted as debt
  or leak to parents, so none of the 32 payment readers change.
- Blocked rows (attended lessons outside any contract = taught but not billed) are surfaced as
  「需補開」 — covers plan C's safety net and the 30-contract / 127-lesson backlog.

## Steps
1. **PR1 backend (read-only)**: extract renewMonthly's period/charge preview into one shared method;
   `GET accounting/monthly-drafts?month=YYYY-MM` (director campus-scoped) returns per contract:
   proposed start/end, sessions, amount, due date, status `ready|blocked|lapsed_no_lessons`, blocker
   message/count. Candidates: Stop=0, ScheduleMode=date, PackageID=0, ClassType not tutoring/trial,
   EndDate before end of the month, no duplicate renewal. Tests: ready, blocked, lapsed, duplicate,
   package/tutoring excluded, campus scope, preview amount == amount renewMonthly actually charges.
2. **PR2 frontend**: 帳務中心「本月待開帳單」tab: list, per-row 確認開立 (calls existing renew
   endpoint with end_date = proposed end), select-all batch confirm (sequential, per-row result),
   blocked rows show 「需補開 N 堂」. Staff card after deploy + verify.
3. **Later (separate GO)**: 補開 action for blocked rows (past-period catch-up, POP pattern from
   宥翰); daily digest line counting blocked rows.

## Verification
- Local `scripts/phpunit-isolated.sh` targeted; CI green; after deploy, read-only probe compares
  list count to `unbilled_monthly_sessions` probe (blocked ≈ 30 contracts).

## Stop points
- Any write path change beyond calling existing renewMonthly → Founder GO.
- Production data fixes for blocked rows → POP + Founder GO.
