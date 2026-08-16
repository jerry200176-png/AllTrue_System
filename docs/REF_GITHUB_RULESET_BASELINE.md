# GitHub Repository Ruleset Baseline (#880)

> Reference only — exported from the live GitHub API, 2026-08-08. Verify against live state via `docs/OPERATIONAL_CONSISTENCY_CHECK.md` Rule 8, not by reading this doc alone (this file can go stale; the API is truth).

## `main-protection` (id `19787083`, target: branch)

Applies to: `~DEFAULT_BRANCH` (main). `enforcement: active`. `bypass_actors: []`, `current_user_can_bypass: never` — no bypass, including for the repo owner.

| Rule | Setting |
|---|---|
| `deletion` | main cannot be deleted |
| `non_fast_forward` | no force-push to main |
| `pull_request` | `required_approving_review_count: 0` (deliberate — see `docs/governance/RISK_BASED_MERGE_POLICY.md` §Solo vs Multi-maintainer gate), `require_code_owner_review: false`, `required_review_thread_resolution: true`, `allowed_merge_methods: [merge, squash, rebase]` |
| `required_status_checks` | `strict_required_status_checks_policy: true` (branch must be up to date before merge); required contexts: `Presubmit Checks`, `PHPStan Advisory (php)`, `PHPUnit Feature & Unit Tests`, `Vite Frontend Build`, `Docs Integrity Check`, `gitleaks scan`, `Golden scenarios report`, `Control Plane Contract Lint`, `Agent Session Provenance` |

## `release-tag-protection` (id `20577363`, target: tag)

Applies to: `refs/tags/v*` (the CalVer release tag pattern used by `release.yml`). `enforcement: active`. `bypass_actors: []`.

| Rule | Setting |
|---|---|
| `deletion` | release tags cannot be deleted |
| `update` | release tags cannot be moved to a different commit once created |
| `non_fast_forward` | no force-push equivalent on tag refs |

## Change history

| Date | Change | Why |
|---|---|---|
| 2026-07-27 | `main-protection` created | Initial branch protection baseline |
| 2026-08-01 | `main-protection` updated | (see ruleset `updated_at`; not independently re-derived here — if this matters, diff via `gh api` history is not available, only current state) |
| 2026-08-08 | `release-tag-protection` created | #880 — release tags had no protection; a deleted/moved release tag would break `RUNBOOK_ROLLBACK.md`'s "redeploy prior SHA" path if the tag pointing at it vanished |

## Known gaps (not this doc's job to fix, tracked elsewhere)

- No `merge_queue` rule on `main-protection` — see #871 (separately tracked, higher-risk change to merge mechanics, not bundled into this baseline pass).
- `required_approving_review_count: 0` is intentional for solo-maintainer mode, not a gap — see the Solo/Multi switch procedure in `RISK_BASED_MERGE_POLICY.md`.
