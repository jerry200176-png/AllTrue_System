# Founder exceptions (process control — not crypto identity)

Waive **one** check for **one** PR head SHA. File must already be on **`main`**:

`PR-<number>-<exact-head-sha>.json`

Required fields: `pr_number`, `head_sha`, `risk_class`, `classified_line_counts`, `why_unsplittable`, `waived_check` (e.g. `reviewability`), `independent_review_evidence`, `rollback`, `expires_at`.  
Agents must not self-approve. SHA change invalidates the exception.
