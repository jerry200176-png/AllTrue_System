## 2026-11-07 — feat(ui): overlay templates, confirm dialog host on ConfirmDialog (#4989)
<!-- release-notes: staff_update=staff-2026-11-07-ui-confirm-dialog -->
- Adds `frontend/src/components/app-overlay/` (ConfirmDialog, FormDialog, DetailSheet, ActionMenu, toast helper) on `components/ui`.
- `ConfirmDialogHost` (shared `askConfirm` host) now renders `ConfirmDialog`; call signature unchanged. Focus starts on 取消.

### Rendered layout and review regressions

- Register `components/app-overlay` in the explicit Tailwind source scan so production CSS includes the form grid, viewport constraints and sheet widths.
- Normal FormDialog layouts use a bounded three-row grid with body scrolling and sticky actions. When measured header/footer heights leave less than four root-text lines for fields, the existing panel becomes one scroll area with actions in normal flow. This keeps fields reachable at enlarged text and short viewport heights, including `320x480/root32px` and `320x240/root16px`.
- Shared confirmations retain the existing confirmation layer above legacy modals and DetailSheet, with safe initial focus and restoration after a choice. AtToast preserves its existing z-index20000 so an export failure remains visible above LearningRecordsPage's z-index13000 modal.
- Dedicated browser regressions use real components and emitted production CSS: field hit targets, typing, keyboard actions, live resizing, focus trapping, dirty-form confirmation, shared promise results, and the real export-error toast path. The synthetic fixture blocks non-loopback requests; no API credentials or production data are used.
- Keep this loopback fixture in its dedicated Playwright configuration. Default authenticated smoke excludes it; actual `--list` collection regressions verify both local and explicit deployment base URLs while retaining smoke tests and all16 dedicated overlay cases.
- Reproduce from `frontend/`: `PLAYWRIGHT_CHROMIUM_EXECUTABLE=/usr/bin/chromium npx playwright test -c playwright.overlay-layout.config.js` and `node --test src/lib/overlayLayoutRouting.test.js`. Omit the Chromium override when using Playwright's installed browser. Screenshots are evidence only; no visual baselines, existing assertions, workflows or allowlists change.
