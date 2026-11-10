---
name: Auto Update Notification
overview: When a new frontend version is deployed, users see a banner prompting them to refresh instead of having to be told manually. Uses build-time stamp injection + a polled `/version.json` file.
todos:
  - id: vite-plugin
    content: Add emit-version-json Vite plugin to vite.config.js
    status: completed
  - id: copy-script
    content: Add version.json to ROOT_ASSETS in copy-to-backend.cjs
    status: completed
  - id: composable
    content: Create frontend/src/composables/useUpdateChecker.js
    status: completed
  - id: app-banner
    content: Integrate useUpdateChecker and update banner into App.vue
    status: completed
isProject: false
---

# Auto Update Notification

## Approach

Vite already injects `__APP_BUILD_TIME__` into the bundle. We emit a matching `version.json` at build time and serve it statically. The frontend polls it and shows a banner when the version drifts.

## Files to Change

### 1. [`frontend/vite.config.js`](frontend/vite.config.js)
Add a Vite plugin after `vue()` that emits `version.json` into `dist_build/` using the same `buildTimeIso` already defined:

```js
{
  name: 'emit-version-json',
  generateBundle() {
    this.emitFile({
      type: 'asset',
      fileName: 'version.json',
      source: JSON.stringify({ t: buildTimeIso }),
    });
  },
}
```

### 2. [`frontend/scripts/copy-to-backend.cjs`](frontend/scripts/copy-to-backend.cjs)
Add `'version.json'` to the existing `ROOT_ASSETS` array so it gets copied to `backend/public/`:

```js
const ROOT_ASSETS = ['manifest.json', 'logo.png', ..., 'version.json'];
```

### 3. [`frontend/src/composables/useUpdateChecker.js`](frontend/src/composables/useUpdateChecker.js) *(new)*
Polls `/version.json`, compares `t` to `__APP_BUILD_TIME__`, exposes `updateAvailable` ref:
- Poll every 5 minutes via `setInterval`
- Re-check immediately on `visibilitychange` (user switches back to tab)
- Silently skip if fetch fails (network offline, dev mode 404, etc.)
- Stop polling once update is detected (no need to keep checking)

### 4. [`frontend/src/App.vue`](frontend/src/App.vue)
- Import and call `useUpdateChecker()`
- Add a fixed banner element (shown when `updateAvailable`) above the main layout — visible in all states (login, main app, parent portal)
- Banner text: `「系統已更新，請重新整理頁面」` + a `重新整理` button calling `location.reload()`
- Banner has a dismiss (×) button so it's not blocking
- CSS: fixed top bar, accent color, high z-index, won't block modals below

## Behavior
- No change to deploy flow — `npm run deploy` still works identically
- Works in all roles (director, teacher, parent portal)
- No service worker required
- Gracefully no-ops when `/version.json` returns 404 (dev server, first deploy before file exists)
