## 2026-10-31 — fix(finance): 當月學收 allocation edge cases (#4682)
<!-- release-notes: silent_ship=silent-2026-10-31-monthly-tuition-edges -->
<!-- silent-reason: follow-up edge cases of the 當月學收 status report added 2026-10-30 (#4681); too new for staff to rely on the changed numbers -->
- A discounted count-mode contract with no invoice yet now spreads the verdict's owed over its lessons, instead of showing full lesson price as debt.
- The allocation denominator is the contracted lesson count (`SessionCount`, as `resolveRate()`), so an earlier month no longer changes as later lessons are attended.
- A date-mode invoice whose service range spans two months counts once, in the month the range starts; later covered months show its status with 0 due and 0 outstanding instead of 待確認.
- A shared-package member whose package has valid payment evidence is no longer 待確認.
