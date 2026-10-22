## 2026-10-21 — ci(hygiene): stale agent-PR sweep and land-queue alarm
<!-- release-notes: silent_ship=silent-2026-10-21-repo-hygiene-stale-queue-alarm -->
<!-- silent-reason: ops workflow change; no product UI change -->

`branch-hygiene.yml` gains a weekly stale sweep (agent PRs idle 14d get `stale`; agent drafts idle 30d are closed; non-draft and non-agent PRs are never closed) with one weekly report issue that includes merged-branch leftovers, and an hourly alarm that opens/updates one issue when a PR has had `queue` for more than 2h or land-queue failed 3 completed runs in a row.

Integrity fixes: the report reuses the complete checkout with `--no-fetch` while credentials remain unpersisted; missing `ops-hygiene`/`stale` definitions are created before use. Activity reads the exact head commit (including PRs above 100/250 commits) and paginated reopen events; failed requests, missing metadata or invalid timestamps cannot close a draft. Verification uses local GitHub/git stubs only. This workflow change remains T3: merge activates its scheduled GitHub label/comment/closure writes and requires the existing Founder decision; no live sweep was executed during preparation. Revert this PR to restore the prior report-only workflow.
