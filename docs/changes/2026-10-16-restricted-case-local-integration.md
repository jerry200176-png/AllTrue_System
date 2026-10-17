# Restricted amount-source probe integration preparation

- Local preparation only; not merged, deployed, activated or run against production.
- Integrates PR4209's secret-selected campus-bound read-only case with PR4211's encrypted output on main. Parser logs a fixed status, rejects an unexpected restricted-case response, and withholds restricted-case payload reason/completeness text from public logs. Pricing-amendment truncation marks evidence incomplete.
- Synthetic regression coverage exercises missing encryption key, missing restricted selector, SSH failure, malformed output, campus/read-only response checks, ciphertext round trip and plaintext cleanup. No live data or credentials are required by the tests.
- `silent_ship`: operations-only preparation; no staff-facing product change.
- Local review-remediation candidate: project non-void target invoices with the existing read-only invoice amount resolver before rollback, exporting scalar amounts/source/session count only. The resolver can read target-course monthly sessions internally; this functional revision is separate from the previously reviewed remote head.
