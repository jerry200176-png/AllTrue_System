## 2026-10-12 — fix(billing): 共用方案在帳務面板只有一個收款入口
<!-- release-notes: staff_update=staff-2026-10-12-package-one-payment-action -->
- 同一共用方案（CoursePackage）的多門課，帳務面板只在第一張卡顯示「登記這筆」，其他卡標示「共用方案，在同方案第一張卡登記收款」；下方「登記收款」把整個方案算一份合約。
- 後端同步把關：方案內任一門課已有「等你確認」的回報時，其他成員不能再登記第二筆（422 `pending_report_exists`）。Refs #3753
