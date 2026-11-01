## 2026-11-02 — fix(scheduling): a shared group slot already debited is not reserved twice
<!-- release-notes: silent_ship=silent-2026-11-02-shared-pool-ended-slot -->
<!-- silent-reason: scheduling-correctness fix, no new staff-facing feature -->
- A group package created after its shared first slot ended, with that slot sent in `confirmed_dates` by only one subject, reserved the same slot again for the other subject (7 group lessons for 8 bought). `fillSharedPool` now skips ended group slots a member already holds (#4767).
