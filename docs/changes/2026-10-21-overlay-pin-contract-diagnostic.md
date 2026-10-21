## 2026-10-21 — validate the successor Sunrise overlay pin
<!-- release-notes: silent_ship=silent-2026-10-21-overlay-pin-contract-diagnostic -->
<!-- silent-reason: internal CI failure diagnosis; no product behavior or governance authority change -->

- Sunrise-cafe #470 replaced its legacy Constitution version with a rulebook lock. The checker validates the successor pin against both the vendored bytes and their immutable portfolio-ops source commit, rejecting missing/malformed provenance, drift and network failure.
- Legacy input still requires exact Constitution version equality. AllTrue's own Constitution, authority, approval boundaries and specific holds are unchanged; no fetched rule text is executed or adopted.
- The initial overlay fetch also fails closed; an outage cannot silently select the obsolete cached `0.1.0` pin.
- Eleven offline tests exercise the shell gate and hash verifier. Rollback: revert this compatibility change; application runtime and data are unaffected.
