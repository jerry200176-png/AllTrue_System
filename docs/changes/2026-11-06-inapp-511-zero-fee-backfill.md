## 2026-11-06 — fix(billing): 補登收款 on a zero-fee course says what to do (in-app #511)
<!-- release-notes: staff_update=staff-2026-11-06-zero-fee-backfill -->
- Frontend only. On 已收過，補登收款 for a course with a NT$0 fee, the form asked to tick a 免費／贈送 box that was hidden. The server refuses both NT$0 and 免費／贈送 in 補登 (and a legacy Paid flag rules out 免費／贈送), so the form now says up front, and on submit, that there is nothing to back-fill and what to do: 其實沒收，改成未繳 first, then 登記收款 with 免費／贈送, or set the fee in 課程管理. No API change.
