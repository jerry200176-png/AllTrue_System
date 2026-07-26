# UI Foundation fixtures (test-only)

- `pilot-mount.html` / `pilot-mount.js` — Vite entry that mounts real `NotificationsCenter.vue` / `StudentsList.vue` for Playwright.

Never copy these into `frontend/public/` or production `dist_build/`.
Guard: `scripts/check-no-public-ui-fixtures.sh`.
