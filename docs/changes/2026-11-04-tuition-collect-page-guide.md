## 2026-11-04 — feat(guide): tuition-collect page guide teaches the billing and renewal SOP (in-app #496)
<!-- release-notes: staff_update=staff-2026-11-04-tuition-collect-page-guide -->
- `pageGuideConfig.js`: new `tuition-collect` guide (6 steps: filter chips, fewest-lessons sort, 開帳單/繳費單, 登記收款/確認入帳, 需核對, where 續課 is done). No new policy; wording follows existing labels.
- `StudentBillingList.vue`: `data-guide` attributes only (chips, list). `TuitionCollectionPage.vue` already had `tuition-header`.
