## 2026-10-21 — feat(billing): staff dialog for the existing agreed-fee correction API (hidden until activation)
<!-- release-notes: silent_ship=silent-2026-10-21-agreed-fee-correction-ui -->
<!-- silent-reason: the entry is invisible until a separate Founder-gated activation (schema deploy + allowlist + flag PR); the staff card ships with that activation -->

`GET /api/v1/student-classes/{id}/invoices` now returns a read-only
`agreed_fee_correction_available` boolean computed by
`AgreedFeeCorrection::isAvailableFor()` — the same flag, role/campus and
course/campus allowlist gates the correction controller enforces (the
controller now calls the same helpers; its status codes are unchanged).

The 課程管理 → 學生帳務 tab shows 「更正約定費用」 only when that boolean is
true. The dialog previews first (old → new agreed fee, invoice replacement,
"no payment/receipt/refund/notification"), then applies with the same
idempotency key and the preview token. A 409 clears the preview and requires
a new one; server refusal messages are shown verbatim.

With `billing.agreed_fee_correction_enabled` false (the committed default) or
an empty allowlist, the boolean is false and nothing is visible.
