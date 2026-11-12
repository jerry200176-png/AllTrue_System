# Implementer brief (standing part)

Every implementer agent follows this. The coordinator sends only the task-specific delta (issue link, branch name, size budget, no-go areas). Rules live elsewhere; this file points to them. If the delta and this file disagree, the issue plan wins and you say so in your report.

## Start
- `git fetch origin main`, then branch from `origin/main`. Never work on `main`.
- Read the required list in [`AGENTS.md`](../../AGENTS.md) and the issue plan ([`docs/sop/ISSUE_PLAN.md`](../sop/ISSUE_PLAN.md)). Read nothing else until you need it.

## Token rules
- Grep first, then read line ranges. No raw JSON, check-run or log dumps ([`token-discipline.md`](token-discipline.md)). Use `node scripts/ci/pr-digest.mjs` for PR and CI state.
- Push, open the PR, then hand back. Never poll or wait for CI.
- Model routing: mid model by default. Top model only for R3 review and for money or data-repair logic.
- WIP limit: at most 3 agents at once.

## Correctness
- Tests first (RED, then GREEN). Never lower a test, assertion, allowlist or baseline.
- If PHPUnit or Vitest could not run locally, write "not run locally" in the report. Do not imply it passed.
- A renamed test file must rename its class too.
- No empty commits, no force-push. Merge `main` into the branch; do not rebase.
- CI stuck or cancelled: rerun the failed jobs through the REST API, or report it.

## UI changes
- Search the production-run specs for every selector, class name and label helper you change, and update them in the same PR: `frontend/e2e/calendar-course-consistency.spec.js`, `frontend/e2e/*production*.spec.js`, `frontend/e2e/fixtures/**`.
- Do not put native `required` or `min` on a field that shows inline errors.
- A confirm handler must return early when its dialog is already closed.

## PR hygiene
- Use the headings in `.github/pull_request_template.md`.
- Paste the declaration block from `python3 scripts/governance/pr_declaration.py --base origin/main --head HEAD`.
- Add `Rewrites: #N` lines where the PR replaces earlier work.
- Check the PR number before editing a PR body.
- After you fix a review thread, reply to it AND resolve it.
- Record choices you made yourself under "Agent 決定的事" ([`ISSUE_PLAN.md`](../sop/ISSUE_PLAN.md); rule: `FOUNDER_STANDING_DECISIONS.md` 2026-11-12 (四)).

## Review cap
- After two review rounds with no P0/P1 finding, the remaining P2s become follow-ups on the tracker issue, not more rounds.
- A change that LOWERS a risk tier, or touches money or data repair, keeps full review.

## Public repo
- Issues, PRs and comments are public: write IDs only, no names, contact details or secrets.

## Claim before you start
- Check open PRs that reference the issue and the `claimed` label; see [`orchestration.md`](orchestration.md#claim-convention).

## Report format (under 150 words)
- PR number, head SHA, what changed, tests run / not run, deviations from the brief or plan.
