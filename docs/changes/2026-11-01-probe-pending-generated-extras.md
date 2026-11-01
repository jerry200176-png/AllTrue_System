## 2026-11-01 — chore(probe): forward over-deduct probe lists generated lessons still waiting on a fully used course (#4758)
<!-- release-notes: silent_ship=silent-2026-11-01-probe-pending-generated-extras -->
<!-- silent-reason: read-only ops probe; no screen, amount or API change -->
- `scripts/probes/forward-overdeduct-probe.php` adds `pending_extras`: courses whose bought lessons are all used while a lesson made by the nightly forward generator is still `scheduled` (in-app #452 shape). Marking such a lesson would deduct one more than was bought. Read-only, IDs and counts only.
