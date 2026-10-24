## 2026-10-24 — feat(billing): capture ordinary-close billing source manifest
<!-- release-notes: silent_ship=silent-2026-10-24-ordinary-close-billing-census -->
<!-- silent-reason: Internal unwired evidence reader; no staff-facing behavior is enabled. -->
- Add a bounded, read-only HMAC manifest for billing source rows used by ordinary-close review.
- Retain valid receipt/reversal histories and validate complete restatement operation/proof links and exact ownership.
- Refuse missing discovery columns or changed selector reads, reject foreign headers before child traversal, and keep driver errors private.
