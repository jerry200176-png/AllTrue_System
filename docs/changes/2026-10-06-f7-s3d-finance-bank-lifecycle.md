## 2026-10-06 — refactor(billing): F7 S3d finance counts, bank matching and course lifecycle read the resolver
<!-- release-notes: silent_ship=silent-2026-10-06-f7-s3d -->
<!-- silent-reason: 財務統計與對帳建議改用帳單口徑；部分繳不再算已繳，與帳務中心一致 -->
- 財務儀表板已繳／未繳課程數與待繳清單、銀行對帳建議金額、課程生命週期的「已繳」判斷，改依帳單解析器狀態（部分繳不算已繳、輔導與零學費不算未繳）。
