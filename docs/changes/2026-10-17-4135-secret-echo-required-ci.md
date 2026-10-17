## 2026-10-17 — ci(gov): run workflow secret-echo in required Control Plane lint (#4135)
<!-- release-notes: silent_ship=silent-2026-10-17-4135-secret-echo-required-ci -->
<!-- silent-reason: CI-only guard wiring; no staff-facing product change -->
- Control Plane Contract Lint now runs `scripts/ci/workflow-secret-echo.test.mjs` (already in `test:gov`).
- Aligns with open-source fail-closed secret scanning (gitleaks / supply-chain CI): required checks, not optional local-only.
- Scanner currently reports zero findings on main; no baseline needed for this slice.
