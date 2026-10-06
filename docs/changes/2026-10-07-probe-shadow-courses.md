## 2026-10-07 — chore(ops): read-only probe for the 5 courses the S5 shadow flagged
<!-- release-notes: silent_ship=silent-2026-10-07-probe-shadow-courses -->
<!-- silent-reason: 唯讀診斷，只輸出 ID 與金額，不影響畫面或資料 -->
- `production-case-dump` 新增 `paid_status_shadow_courses`：課程 1995/3621/1052/3852/3715 的旗標、resolver 狀態與帳單／收款摘要（只有 ID 與金額），用來確認外送提醒改用 resolver 前，這 5 門是否真的已結清。
