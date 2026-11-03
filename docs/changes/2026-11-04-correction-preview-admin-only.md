## 2026-11-04 — fix: 預覽分期更正 only for super admins (in-app #486)
<!-- release-notes: silent_ship=silent-2026-11-04-correction-preview-admin-only -->
<!-- silent-reason: a headquarters-only button is no longer shown to directors; nothing new to learn -->
- The course drawer's 合約 card shows 預覽分期更正 only when `canPreviewCorrection` (super admin). Execution was already POP-only, so directors could open the form but never finish it.
