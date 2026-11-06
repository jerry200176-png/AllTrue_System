## 2026-11-06 — fix(billing): 補登收款 shows the 免費／贈送 box for a zero-fee course (in-app #511)
<!-- release-notes: staff_update=staff-2026-11-06-zero-fee-backfill -->
- Frontend only. 已收過，補登收款 on a course with a NT$0 fee now opens 登記收款, which has the 免費／贈送 box, instead of a 補登 form whose NT$0 check asks for a box that was hidden. The server already refuses NT$0 and 免費／贈送 in 補登, so no API change.
