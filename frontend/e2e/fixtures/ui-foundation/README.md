# UI Foundation fixtures (test-only)

- `ui-foundation-harness*.html` — **design exploration only** (static HTML). Not merge-acceptance evidence.
- `pilot-mount.html` / `pilot-mount.js` — Vite entry that mounts real `NotificationsCenter.vue` / `StudentsList.vue` for Playwright.

Never copy these into `frontend/public/` or production `dist_build/`.
Guard: `scripts/check-no-public-ui-fixtures.sh`.
