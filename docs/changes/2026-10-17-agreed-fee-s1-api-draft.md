## 2026-10-17 — feat(billing): agreed-fee S1 API Draft (flag off, no ALTER)

Ship default-off `AgreedFeeCorrection` service + director preview/apply routes from locked handoff `e8c3947f1`. Config `agreed_fee_correction_enabled` hard-coded **false**; without extension columns `available()` stays false (503). No production ALTER migration in this PR. AllTrue_test uses ephemeral schema helper for txn/concurrency regression only. Not a product enablement — fail closed until Founder activates flag + schema separately.

staff_update: silent_ship
silent_ship_reason: Draft default-off API; no staff-visible fee UI or production activation in this change.
