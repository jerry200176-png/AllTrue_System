## 2026-11-02 — ci(deploy): a release train superseded at Founder approval is not a deploy incident
<!-- release-notes: silent_ship=silent-2026-11-02-superseded-train-not-incident -->
<!-- silent-reason: deploy control-plane fix, nothing staff-facing -->
- A newer release train cancels the waiting one. Its `Deploy to Production` job never gets a runner and runs no step, yet it opened a "Production deploy incident" and froze R0–R2 auto-activation. The incident job and `deploy_freeze_reason` now treat an empty step list plus no runner as positive proof that nothing ran (#4775).
