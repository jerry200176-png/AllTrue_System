## 2026-10-06 — feat(billing): one money/status answer per course on every screen (Founder option A)
<!-- release-notes: staff_update=staff-2026-10-06-money-status-unify -->
- 顯示與分類統一（不改「是否已繳」判定 G-009、不改任何金額或寫入）：確認不收在學費提醒階梯也回 `waived`（課程／學生列表仍為 `paid`，以 closed_reason 顯示「確認不收」）；課程列表部分繳回 `partial`；家長帳務紀錄 Charge 0 回 `free`；月結一律以 `payment_type==='monthly'`（家長端 API 新增 `payment_type`）；共用方案 `PackageID`／`package_id` 皆算；文案統一「部分繳」「已入帳」。移除 `isNonSessionPayment`／`isNonCountSchedule`／`strictPackageId` 分歧選項。
