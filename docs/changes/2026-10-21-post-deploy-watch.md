## 2026-10-21 — ci(deploy): 20-minute post-deploy watch with automatic rollback (workflow gap #3)
<!-- release-notes: silent_ship=silent-2026-10-21-post-deploy-watch -->
<!-- silent-reason: deploy pipeline change; no product UI change -->

After health, identity and smoke pass, the executor's `post-deploy-verify.py gate`
keeps watching the release for 20 minutes: health + `deployment.json` identity every
30 s (4 consecutive failures breach) and, when the `SENTRY_WATCH` secret is set, the
Sentry error count against the pre-deploy hour. A breach takes the existing
rollback-or-hold decision and `rollback_deploy`; no new rollback path. A failed
executor opens or appends one incident issue (SHAs and run IDs only), and the failed
`Deploy to Production` job is the deploy-freeze signal.
