## 2026-10-19 — ci(acceptance): fire calendar/course acceptance after Release train
<!-- release-notes: silent_ship=silent-2026-10-19-calendar-acceptance-release-train-trigger -->
<!-- silent-reason: CI-only trigger coupling; no staff-visible product change -->

- `#4207`: `calendar-course-acceptance.yml` `workflow_run` now lists both `Deploy to Pi` and `Release train` so post-deploy acceptance runs after release-train activations (not only merge-named Deploy runs).
- Static contract test + CI unit invoke keep those identities coupled to `deploy.yml` `name:` / `run-name` literals. Acceptance assertions and gates unchanged.
