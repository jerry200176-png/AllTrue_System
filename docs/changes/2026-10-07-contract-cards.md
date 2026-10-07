## 2026-10-07 — feat(billing): 學生帳務合約卡片（帳務大改版 V2）
<!-- release-notes: staff_update=staff-2026-10-07-contract-cards -->
- 學生帳務面板新增「合約」區：每筆合約一張卡片（科目・起迄日、計費方式一句話、合約備註全文可直接改、按月份列出每一堂與已付／未付、還沒排日期堂數），欠款多的合約排前面。資料來自 V1a `accounting/contracts/{id}/sessions`（加回傳科目／起迄日／計費方式）。PRD v2 D11b/D13/D15/D16/D17。
