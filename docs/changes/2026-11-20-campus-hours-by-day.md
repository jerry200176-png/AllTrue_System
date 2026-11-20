## 2026-11-20 — feat(payroll): 各分校時數報表多給每日時數
<!-- release-notes: silent_ship=silent-2026-11-20-campus-hours-by-day -->
<!-- silent-reason: 只在既有報表的 JSON 回應多一個 by_day 欄位，畫面與 CSV 都沒變。 -->

- `GET /api/v1/finance/teacher-eligibility/campus-hours` 與唯讀金鑰版的每位老師多一個 `by_day`：每天的合計時數與各分校時數。
- 每日合計加起來等於當月合計；算法沒有改。
- 來源：Founder 2026-11-20（要每日上課時數明細）。

Refs #5549
