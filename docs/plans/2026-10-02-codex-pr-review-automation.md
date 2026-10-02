# Codex PR review automation

## Goal

Review every AllTrue_System pull request when opened and after each head-branch push. Publish evidence-backed findings in GitHub, including code behavior, CI, risk declaration, rollback, and unresolved review threads. Let implementing agents complete T0–T2 delivery after the existing gates; reserve Founder decisions for concrete T3/protected actions.

## Steps

1. Add concise repository-specific `Code Review Rules` to `AGENTS.md` in a governed task worktree and deliver them through a PR.
2. Connect AllTrue_System to Codex GitHub review, enable automatic code review for all PRs, and select the trigger for PR creation and each push. Verify settings from the signed-in account rather than inferring them from `gh` authentication.
3. Use Codex's PR-activity-triggered code review for each push. Create an hourly scheduled follow-up using the connected GitHub app for CI checks, risk declarations, and unresolved review threads. Keep comments idempotent and verify the task can post the intended review or comment.
4. Backfill currently open PRs once. Use #3437 to verify cross-path notification review and CI reporting.
5. Remove blanket phase-by-phase GO requests from Claude Code's local adapter. Preserve the canonical T3/protected approval boundary and approval continuity for an already approved concrete task.

## Review contract

For each open PR, pin the PR number, base SHA, and head SHA before reviewing. Read the diff, relevant code and repository rules, existing review threads, PR risk/rollback declaration, and GitHub checks. Report only actionable findings with file or job links, a reproduction path, and severity. If CI is pending, say so and recheck later. For a completed head SHA, post at most one consolidated CI/governance comment; update it only when checks or findings materially change. Do not duplicate existing Codex review findings. Do not manufacture a second approval identity or treat a green check as production authorization. A routine PR does not require a Founder GitHub Approve click when the ruleset requires zero approving reviews.

## Verification and stop points

- Local: `git diff --check`, governance declaration, and required PR checks for the rules-only change.
- Account: confirm Codex automatic review is enabled for AllTrue_System and runs on creation and each push. Confirm an actual GitHub review appears on a representative PR.
- Task: confirm the hourly follow-up can inspect GitHub PRs, cites exact head SHAs, and posts or updates a review summary only when actionable evidence changes.
- Stop if Codex review settings or event tasks are unavailable, the GitHub connection lacks write permission, or the only available action would broaden merge/deploy authority. Record the exact missing capability for the account owner. For T3, prepare the exact action and evidence before asking for Founder approval; do not use a blanket standing GO.

## Rollback

Disable automatic review and the hourly follow-up task in their account settings; revert the rules-only PR if its guidance causes noisy reviews. No application data or production executor changes are involved.
