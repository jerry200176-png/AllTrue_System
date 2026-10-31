## 2026-11-01 — refactor(billing): delete the unreachable 待處理 queue table (F-5-2)
<!-- release-notes: silent_ship=silent-2026-11-01-billing-ux-f5-2-delete-queue -->
<!-- silent-reason: removes code that F-5-1 (#4715) already made unreachable; no screen, amount or API change -->
- F-5-1 hid the per-course 待處理 table. This deletes it: about 800 lines of template, state, summary cards and sorting in `TuitionCollectionPage.vue`, plus the tests that only exercised it (RemainingSort, RemainingVisibility, the activeTab TDZ guard).
- `TuitionDocActions.vue` stays. The cross-stack 繳費單/收據 state contract (`SlipReceiptStateContract`, the same fixture PHPUnit checks) renders through it.
