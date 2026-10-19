## 2026-10-19 — ci(billing): Founder-gated agreed-fee allowlist config surface
<!-- release-notes: silent_ship=silent-2026-10-19-agreed-fee-allowlist-activation -->
<!-- silent-reason: Operator control-plane only; feature flag stays literal false; no teacher/parent UX. -->

- Add `agreed-fee-allowlist-activation.yml` to upsert Pi `.env` allowlist keys from repo secrets (counts/fingerprint only in logs; never enables the API flag).
- Inventory + contract test + release-package pointer so tip activation can proceed without inventing an ad-hoc `.env` path.
