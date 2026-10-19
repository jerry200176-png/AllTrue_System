## 2026-10-19 — chore(ops): publish allowlisted xinzhuang dump completeness

<!-- release-notes: silent_ship=silent-2026-10-19-xinzhuang-dump-completeness-token -->
<!-- silent-reason: internal production-case-dump log hygiene; no staff-facing product change -->

- `xinzhuang_fee_cases` encrypt step now publishes fixed fee-ledger `completeness` tokens (`correction_schema_missing`, etc.) while still withholding free-form `reason` and unknown tokens
- Lets Track A diagnose `snapshot_complete=false` from the public job log without decrypting the artifact body
- Regression: `scripts/tests/test_case_dump_restricted_integration.py` allowlist + withhold coverage
