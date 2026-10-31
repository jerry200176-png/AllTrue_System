## 2026-11-01 — refactor(billing): delete the unreachable 待處理 queue table (F-5-2)
<!-- release-notes: silent_ship=silent-2026-11-01-billing-ux-f5-2-delete-queue -->
<!-- silent-reason: removes code that F-5-1 (#4715) already made unreachable; no screen, amount or API change -->
- Deletes the per-course 待處理 table F-5-1 hid (about 800 lines in `TuitionCollectionPage.vue`) and the tests that only drove it. `TuitionDocActions.vue` stays: the 繳費單/收據 state contract (`SlipReceiptStateContract`, shared with PHPUnit) renders through it.
