## 2026-10-07 — feat(rfid): directors can unbind student and teacher cards (in-app #381)
<!-- release-notes: staff_update=staff-2026-10-07-rfid-unbind -->
- 新增 `DELETE /api/v1/students/{id}/bind-card`（分校閘門 `denyOutsideCampus`）與學生編輯視窗「解除綁定」；老師各分校卡號可清除（存檔寫 NULL）。解除後同一張卡可再綁給別人。
