## 2026-10-17 — feat(billing): agreed-fee S1 API Draft (flag off, no ALTER)
<!-- release-notes: silent_ship=silent-2026-10-17-agreed-fee-s1-api-draft -->
<!-- silent-reason: Default-off Draft API; no staff-visible fee UI or production activation in this change. -->

Ship default-off `AgreedFeeCorrection` service + director preview/apply routes from locked handoff `e8c3947f1`. Config `agreed_fee_correction_enabled` hard-coded **false**; without extension columns `available()` stays false (503). No production ALTER migration in this PR. AllTrue_test uses ephemeral schema helper for regression only. Not a product enablement — fail closed until Founder activates flag + schema separately.

HOLD packaging note: Presubmit 700 is packaging-only. Core regression (CorrectionTest + Concurrency + schema fixture + audit/includeVoided/stale-token/transaction) stays in-slice with the Draft API; no after-merge test deferral. Legal split = independent security deps (+ focused tests) first, then fee service + full fee tests on new main; if still over gate, vertical shards with impl+evidence together. Oversized HOLD reports exception need — no delete/restore cycles.
