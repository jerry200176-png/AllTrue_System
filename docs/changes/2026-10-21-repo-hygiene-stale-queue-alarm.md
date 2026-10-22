## 2026-10-21 — ci(hygiene): stale agent-PR sweep and land-queue alarm
<!-- release-notes: silent_ship=silent-2026-10-21-repo-hygiene-stale-queue-alarm -->
<!-- silent-reason: ops workflow change; no product UI change -->

`branch-hygiene.yml` gains a weekly stale sweep (agent PRs idle 14d get `stale`; agent drafts idle 30d are closed; non-draft and non-agent PRs are never closed) with one weekly report issue that includes merged-branch leftovers, and an hourly alarm that opens/updates one issue when a PR has had `queue` for more than 2h or land-queue failed 3 completed runs in a row.
