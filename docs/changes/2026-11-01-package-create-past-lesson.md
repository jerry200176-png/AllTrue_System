## 2026-11-01 — fix(scheduling): a shared package created after its first lesson ended plans only what is left (#4757)
<!-- release-notes: staff_update=staff-2026-11-01-package-create-past-lesson -->
- `ContractSessionSchedule::fillSharedPool()` counts a new member's contract dates that already ended (backfilled as completed lessons by `extendSessionsIfNeeded()`) against the pool before it pre-plans future lessons. A package created on its start date after the first lesson ended used to get 9 lessons for 8 bought.
