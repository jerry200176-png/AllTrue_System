## 2026-11-01 — fix(scheduling): a group shared pool fills a member into a sibling's far-moved lesson (#4733)
<!-- release-notes: silent_ship=silent-2026-11-01-group-pool-far-slot -->
<!-- silent-reason: edge case of the #4730 group fill (a member missing one far-moved group lesson row); nothing new for staff to learn -->
- `ContractSessionSchedule::fillSharedPool` now reads every member's rows first. For a group pool, it adds each sibling's planned group lesson that sits on a member's own weekly pattern as a candidate. Joining one costs nothing, so a member gets the row even when that lesson was moved past the member's candidate dates. Off-pattern rows (a sibling's own make-up) are never joined.
