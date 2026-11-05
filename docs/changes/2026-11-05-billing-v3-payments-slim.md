## 2026-11-05 — feat(billing): 收款紀錄瘦身，已結清課程入口改成連結（v3 步驟 6，#4659）
<!-- release-notes: staff_update=staff-2026-11-05-billing-v3-payments-slim -->
- 收款紀錄 tab: 7 summary cards → 3 (現金, 匯款, 合計); the counts (筆數, 涵蓋課程, 預收, 同課程多筆收款 with its red warning) are one text line. 起日/迄日 stay; 學生, 科目, 收據狀態, 付款方式 moved into one 「篩選」 control with a count badge. Same query params, CSV/PDF export unchanged.
- 已結清課程 keeps its own list (`/alerts/tuition` has no stopped, fully paid contracts, so the bill list's 已收 filter is not the same list). Only the entry moves: the reminder bar above the bill list becomes one link 「已結清課程」 under it. `tab=settled` links, the #348 tags and help are unchanged.
- Page tour updated. Frontend only; no API, money-rule or DB change. Deleting `StudentBillingList.vue` / `TuitionDocActions.vue` is a separate PR.
