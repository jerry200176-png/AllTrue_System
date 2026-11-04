## 2026-11-04 — fix(tuition): 輔導課卡片不再寫「還沒繳費」；「已結清課程」只在學生頁（in-app #475, #480）
<!-- release-notes: staff_update=staff-2026-11-04-tutoring-card-and-settled-link -->
- `ContractCard.vue`: a tutoring (no payment obligation) card shows only 輔導課不用繳費. The pay line (還沒繳費), the NT$ 0 contract amount, the per-lesson payment chips (還沒開帳單) and the monthly 堂數・付款 summary are hidden for it. Refs #4849.
- `TuitionCollectionPage.vue`: the reminder bar (已結清 = settled tuition courses) renders only on the student list, not above 收款紀錄 or 代收代付, and is labelled 已結清課程. Refs #4852.
