## 2026-11-04 — fix(ui): thin loading bar at the top while a slow screen loads (in-app #493)
<!-- release-notes: staff_update=staff-2026-11-04-loading-bar -->
- New `AtLoadingBar.vue` (mounted once in `App.vue`) plus `lib/loadingBar.js`: a 3px brand-colour bar shows when requests or lazy page chunks have been in flight for more than 300ms. Requests made through `authedFetch` and `api.js` and every lazy page load are counted. Respects reduced motion. Refs #4879.
