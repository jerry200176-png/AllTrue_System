## 2026-10-18 — feat(billing): Preserve default-off agreed-fee correction API
<!-- release-notes: silent_ship=silent-2026-10-18-agreed-fee-api-default-off -->
<!-- silent-reason: The capability remains hard-coded off; no production schema or visible workflow is enabled. -->

- Preserve the reviewed source preview/apply API for explicit fee corrections, with authenticated campus scope, signed state-bound previews, permanent history and idempotent retries.
- Preserve functional and receipt-kind collision regressions using the existing isolated test fixtures.
- Cover rejected 8000 report → explicit 8800 correction → pending-only registration → confirmed 8800 Payment/receipt; a confirmation retry cannot collect twice.

This draft adds new billing semantics and needs a scoped Founder code-merge decision after validation. No production extension migration, activation or data repair is included. Rollback keeps the capability off and retains correction/audit history.
