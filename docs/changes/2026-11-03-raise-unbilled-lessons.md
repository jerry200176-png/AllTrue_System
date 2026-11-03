## 2026-11-03 — fix(billing): 未開帳單的合約可把堂數補到已上過的堂數（in-app #466）
<!-- release-notes: staff_update=staff-2026-11-03-raise-unbilled-lessons -->
- Founder 2026-11-03：「未付款，更正堂數」（`POST /student-classes/{id}/billing-correction`）除了改少，也可以把堂數補多，但只能補到已上過的堂數，合約必須沒有帳單、沒有收款與繳費回報，金額必須等於單堂 × 堂數。之後要上的課仍走「續課」（Q8=A）。
- 新拒絕碼：`billing_correction_increase_above_usage`、`billing_correction_increase_billed`、`billing_correction_unchanged`（取代原本的 `billing_correction_reduction_only`）。確認時在鎖內重新檢查。
