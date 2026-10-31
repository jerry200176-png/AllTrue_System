## 2026-11-01 — fix(scheduling): a shared lesson plan pre-plans only what was bought
<!-- release-notes: staff_update=staff-2026-11-01-shared-pool-preschedule -->
- Creating a shared count plan used to pre-plan the full total for every subject (2 subjects × 16 = 32 lessons for 16 bought). Auto-fill now plans the pool once, alternating subjects by date, and stops at the unplanned remainder. The same cap applies to raising the total and to every other `extendSessionsIfNeeded` refill. Manual adds still go past it with the existing 續課 warning.
- Plans that were already over-planned before this change are left as they are.
