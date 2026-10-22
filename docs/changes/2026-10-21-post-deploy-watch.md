## 2026-10-21 — ci(deploy): 20-minute post-deploy watch with automatic rollback (workflow gap #3)
<!-- release-notes: silent_ship=silent-2026-10-21-post-deploy-watch -->
<!-- silent-reason: deploy pipeline change; no product UI change -->

After health, identity and smoke pass, the executor's `post-deploy-verify.py gate`
keeps watching the release for 20 minutes: health + `deployment.json` identity every
30 s (4 consecutive failures breach) and, when the `SENTRY_WATCH` secret is set, the
Sentry error count against the pre-deploy hour. A breach takes the existing
rollback-or-hold decision and `rollback_deploy`; no new rollback path. A failed
or cancelled executor opens or appends one incident issue. Missing exit codes and
interrupted verification are explicit UNKNOWN; no exit code or rollback success
is invented. A strict single terminal JSON marker carries finite watch/Sentry
outcomes to the summary; malformed/duplicate/missing markers cannot pass. Sentry
outages remain non-rollback outcomes, and secrets never enter these markers.
Success state markers are written only after watch completion is confirmed. A
missing or failing summarizer uses fixed UNKNOWN text without echoing its errors;
the incident step still attempts to record the issue.

**Draft / hold:** main `60507093` has no `deploy_freeze_reason`. This partial repair
does not implement an activation freeze or stack unmerged #4359. That P1 and the
exact-scope T3 approval remain blockers; this preparation is not merge-ready.
