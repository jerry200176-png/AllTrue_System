## 2026-11-06 — fix(billing): 補登收款 on a zero-fee course says what to do (in-app #511)
<!-- release-notes: staff_update=staff-2026-11-06-zero-fee-backfill -->
- Frontend only. On 已收過，補登收款 for a course with a NT$0 fee, the form asked to tick a 免費／贈送 box that was hidden. The server treats that course as already settled at NT$0 (no payment obligation) and refuses 補登, 其實沒收，改成未繳 and 免費／贈送 for it, so the form now says so up front and on submit: nothing to back-fill, and if the course should charge, set the fee in 課程管理 first. No API change.
