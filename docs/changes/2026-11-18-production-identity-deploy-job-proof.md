## 2026-11-18 — fix(ops): verify the actual production deploy job
<!-- release-notes: silent_ship=silent-2026-11-18-production-identity-deploy-job-proof -->
<!-- silent-reason: Only read-only engineering proof changes; no staff interface, deployment, account or production data operation is enabled. -->

- Existing production identity helper verifies deploy.yml/main run metadata, the unique successful actual deployment job and the native resolver's target against the runtime manifest. Workflow revision and activation target may differ. Skipped wrappers and missing, ambiguous or unreadable evidence fail closed.
- Record the job ID and completion time; preserve health/scheduler drift checks, private temporary evidence cleanup and nonzero error exits. HTTPS keeps certificate verification enabled.
- Rollback target remains unknown until independently validated. This helper never deploys, rolls back or repairs data.
