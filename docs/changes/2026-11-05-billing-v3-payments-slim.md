## 2026-11-05 — feat(billing): 收款紀錄瘦身，已結清併入帳單「已收」（v3 步驟 6，#4659）
<!-- release-notes: staff_update=staff-2026-11-05-billing-v3-payments-slim -->
- 收款紀錄 tab: 7 summary cards → 3 (現金, 匯款, 合計); the counts (筆數, 涵蓋課程, 預收, 同課程多筆收款) moved to one text line. 起日/迄日 stay; 學生, 科目, 收據狀態, 付款方式 moved into one 「篩選」 control. Same query params, CSV/PDF export unchanged.
- The separate 已結清課程 view and its reminder bar are gone. `tab=settled` deep links open the bill list with the 已收 filter. 已核對 / 到課程處理 for 待核對 courses stay on the bill list as a 「待核對課程」 strip (same endpoints).
- Deleted unused `StudentBillingList.vue` and `TuitionDocActions.vue`; their slip/receipt rule tests now run on `billingDocState.js`. Page tour updated. Frontend only; no API, money-rule or DB change.
