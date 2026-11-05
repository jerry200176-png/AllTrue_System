## 2026-11-05 — feat(billing): 帳務中心改成帳單清單（v3 PR1，#4659）
<!-- release-notes: staff_update=staff-2026-11-05-bill-list-home -->
- Frontend only. 帳務中心's default tab is now 帳單: one row per contract from `/api/v1/alerts/tuition` (`billRows`), opening on 要處理 with counts, a summary strip and a primary button per row kind that opens the clicked contract (not the student's first owing one). 要開帳單 / 月結要核對 move to a 月結 tab. Money rules, API and DB are unchanged; PRD v3 §0 supersedes v2 D1/D3/D10/D12 and part of D2.
