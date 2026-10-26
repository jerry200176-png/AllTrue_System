## 2026-10-17 — fix(ops): nightly reconcile paging race + overlapping-run publish (#4068)
<!-- release-notes: silent_ship=silent-2026-10-17-4068-reconcile-overlap -->
<!-- silent-reason: Super-admin reconcile panel integrity; no staff product copy change -->
- Frontend: drop paged rows if `loadReport()` replaced the report mid-flight (stale-generation guard, same family as #4076).
- Backend: Cache::lock serializes overlapping `reconcile:nightly` runs; JSON published via temp+rename so readers never see a half-written file.
