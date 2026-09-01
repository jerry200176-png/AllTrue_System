# #878 Release / deploy / in-app trace

Use the read-only trace report when a merged change may or may not be live:

```bash
scripts/trace-fix-deploy-status.sh <github-issue-number>
```

The report correlates, using exact issue references in PR title/body:

1. GitHub issue state and linked PRs.
2. The commit that actually landed on `origin/main` (first-parent log, not a
   possibly pre-squash `mergeCommit` field).
3. Release tags whose history contains that landed commit.
4. `deploy.yml` run IDs and conclusions for that commit.
5. Public `/deployment.json` backend and frontend build SHAs, checked with git
   ancestry.

Missing evidence is printed as `NONE` or `NOT_VERIFIED`; it is never inferred
from a `status:*` label, a merged PR, or a GitHub issue being closed. The tool
cannot infer the in-app Phase-C state. An in-app bug may be marked `resolved`
only after target-correct Phase-C evidence, in addition to successful deploy
and runtime identity evidence. The command performs no production mutation and
does not change GitHub issue state.
