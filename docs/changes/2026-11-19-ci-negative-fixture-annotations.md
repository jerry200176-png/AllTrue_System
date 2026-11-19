## 2026-11-19 — fix(ci): capture expected fixture diagnostics
<!-- release-notes: silent_ship=silent-2026-11-19-ci-negative-fixture-annotations -->
<!-- silent-reason: Test-output handling only; no product interface or runtime rule changes. -->

- Collect the workflow-size and simulated post-deploy gate tests' expected rejection diagnostics instead of publishing synthetic failures as Actions errors. Preserve rejection assertions and include captured output on unexpected results; the real validators, migration protections and workflow remain unchanged.
