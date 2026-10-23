## 2026-10-22 — UI-foundation CI runs when the sidebar, hub tabs or 課程查找 payment entry change
<!-- release-notes: silent_ship=silent-2026-10-22-ui-foundation-filter-paths -->
<!-- silent-reason: CI path filter only; no product change -->

The `ui_foundation` path filter in `.github/workflows/ci.yml` now includes `frontend/src/App.vue`, `frontend/src/lib/navigationRegistry.js`, `frontend/e2e/fixtures/openPageFromSidebarSearch.js`, `frontend/src/pages/CourseManagement.vue`, `frontend/e2e/fixtures/coursePaymentEntry.js` and the helpers those specs import (`frontend/e2e/fixtures/dismissOverlays.js`, `frontend/e2e/fixtures/latestGetJson.js`). A PR that changes the sidebar search, the hub tabs, the 課程查找 payment entry or the shared helpers the production acceptance also uses now runs `ui-foundation-sidebar-navigation.spec.js` and `ui-foundation-course-payment-entry.spec.js` before merge instead of failing only after deploy. Rollback is a normal revert. Refs #4180.
