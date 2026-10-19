## 2026-10-18 — scheduling: current occupancy read foundation
<!-- release-notes: silent_ship=silent-2026-10-18-schedule-current-occupancy -->
<!-- silent-reason: Optional server-owned guard foundation; existing callers and feature flags unchanged. -->

- Add current locking reads across canonical concrete teacher occupancy and substitute/stale provenance for atomic apply callers.
- Keep previews unlocked and existing capacity, campus and self-exclusion rules; verify against real stale MySQL snapshots.
- Bound current schedule locks with a teacher/day index and exact date predicate; read room capacity under a fresh row lock instead of the preview cache. Production index execution remains separately gated.
- Bound ClassSession locks by campus/course/date and makeup provenance by the queried teacher; refresh existing proposed-course attributes in current mode. Independent-PDO regressions cover unrelated-campus course/session writes, unrelated-teacher makeup writes and stale proposed teacher/type/room.
- Keep current provenance locks within canonical course/date lookups, including stale/occurrence/legacy paths and LR fallback; other dates remain writable even under alternate valid optimizer settings.
