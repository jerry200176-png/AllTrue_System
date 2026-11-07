## 2026-11-07 — feat(ui): overlay templates, confirm dialog host on ConfirmDialog (#4989)
<!-- release-notes: staff_update=staff-2026-11-07-ui-confirm-dialog -->
- Adds `frontend/src/components/app-overlay/` (ConfirmDialog, FormDialog, DetailSheet, ActionMenu, toast helper) on `components/ui`.
- `ConfirmDialogHost` (shared `askConfirm` host) now renders `ConfirmDialog`; call signature unchanged. Focus starts on 取消.
