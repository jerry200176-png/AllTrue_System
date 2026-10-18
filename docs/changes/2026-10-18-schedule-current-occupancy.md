## 2026-10-18 — scheduling: current occupancy read foundation
<!-- release-notes: silent_ship=silent-2026-10-18-schedule-current-occupancy -->
<!-- silent-reason: Optional server-owned guard foundation; existing callers and feature flags unchanged. -->

- Add current locking reads across canonical concrete teacher occupancy and substitute/stale provenance for atomic apply callers.
- Keep previews unlocked and existing capacity, campus and self-exclusion rules; verify against real stale MySQL snapshots.
- Bound current schedule locks with a teacher/day index and exact date predicate; read room capacity under a fresh row lock instead of the preview cache. Production index execution remains separately gated.
