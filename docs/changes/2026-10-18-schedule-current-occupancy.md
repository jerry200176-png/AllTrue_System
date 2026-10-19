## 2026-10-18 — scheduling: current occupancy read foundation
<!-- release-notes: silent_ship=silent-2026-10-18-schedule-current-occupancy -->
<!-- silent-reason: Optional server-owned guard foundation; existing callers and feature flags unchanged. -->

- Add current locking reads across canonical concrete teacher occupancy and substitute/stale provenance for atomic apply callers.
- Keep previews unlocked and existing capacity, campus and self-exclusion rules; verify against real stale MySQL snapshots.
- Bound current schedule locks with a teacher/day index and exact date predicate; read room capacity under a fresh row lock instead of the preview cache. Production index execution remains separately gated.
- Discover makeup membership with covering current shared keys, then exclusively read the matched LR graph; protect membership keys/gaps while leaving unrelated payload and other-date teacher keys writable. Recheck stale snapshots, new canonical graphs, caller writes and actual covering plans.
- Keep current provenance locks within canonical course/date lookups, including stale/occurrence/legacy paths and LR fallback; other dates remain writable even under alternate valid optimizer settings.
- Bound ordinary course locks by (StudentID,TeacherID); read proposed metadata through a covering secondary index before shared occupancy, then acquire its clustered lock. Real two-process callers reproduce/fix the prior per-course deadlock without retry.
- Make lookup index creation retryable after partial DDL; guarded adoption leaves indexes in place on code rollback. Production DDL remains separately gated.
