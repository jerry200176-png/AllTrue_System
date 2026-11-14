# Implementer brief (standing part)

Every implementer agent follows this. The coordinator sends only the task-specific delta (issue link, branch name, size budget, no-go areas). Rules live elsewhere; this file points to them. If the delta and this file disagree, the issue plan wins and you say so in your report.

## Start
- `git fetch origin main`, then branch from `origin/main`. Never work on `main`. Branch prefix: `fix/`, `feat/`, `chore/` or `docs/` only (others fail the branch check).
- Read the required list in [`AGENTS.md`](../../AGENTS.md) and the issue plan ([`docs/sop/ISSUE_PLAN.md`](../sop/ISSUE_PLAN.md)). Read nothing else until you need it.

## Token rules
- Grep first, then read line ranges. No raw JSON, check-run or log dumps ([`token-discipline.md`](token-discipline.md)). Use `node scripts/ci/pr-digest.mjs` for PR and CI state.
- Push, open the PR, then hand back. Never poll or wait for CI.
- Model: per rulebook K3 and the lane table in [`DELIVERY_LIFECYCLE.md`](../sop/DELIVERY_LIFECYCLE.md); when unsure use the mid model.
- WIP limit: at most 3 agents at once (coordinator practice, retro #5201 row 30).

## Correctness
- Tests first (RED, then GREEN). Never lower a test, assertion, allowlist or baseline.
- If PHPUnit or Vitest could not run locally, write "not run locally" in the report. Do not imply it passed.
- A renamed test file must rename its class too.
- Test gotchas: `[...] + $overrides` keeps the LEFT keys (put overrides first); after `Carbon::setTestNow()` jumps, extend the test login's `expires_at`.
- A CI guard rejects test methods named like framework `TestCase` methods (`session()`, `count()`; they kill the whole PHPUnit shard): [`scripts/ci/check-testcase-method-collisions.php`](../../scripts/ci/check-testcase-method-collisions.php).
- SQL `CURDATE()`/`NOW()` follow the database clock (UTC in CI), not the app's Asia/Taipei: pass the app's date into the query as a bound value instead.
- A push must depend on the test result: `npx vitest run … && git push`, never a pipeline whose last command is `grep`.
- Arrays read back from a MySQL JSON column have reordered keys: compare them with `assertEquals`, not `assertSame`.
- Before deleting a model, table or helper, grep every reader (controllers, parent portal, timelines). If something else reads it, stop and report.
- No empty commits, no force-push. Merge `main` into the branch; do not rebase.
- CI stuck or cancelled: rerun the failed jobs through the REST API, or report it.

## UI changes
- Load the design skills first (`impeccable`, `make-interfaces-feel-better`, `web-design-guidelines`; `ui-ux-pro-max` for tables and forms) and build the page from [`docs/design/PAGE_TEMPLATE.md`](../design/PAGE_TEMPLATE.md).
- Attach its evidence to the PR: real-browser screenshots at 1536 and 375 at each 字級, before and after.
- Name in the PR body which design skills you loaded and one thing each changed. The final reviewer of a UI PR loads them too and reads the before/after pairs; a lost colour meaning (e.g. a red warning turned ink) is P1.
- If you change a shared component, class set or helper (`components/ui/page/*`, `tones.js`, `cn()`), add before/after screenshots of every page that uses it, not only the page you are migrating.
- Map colour meaning when you restyle: list old class → new tone in the PR body; red stays only for debt and low counts.
- Adding a tab, step or button changes keyboard order: search the e2e specs for arrow-key and Tab walks over that area and update them in the same PR.
- Pick pages that are in the nav registry and in use. A page with no nav entry or zero production rows is a removal candidate: report it instead of migrating it.
- Search the production-run specs for every selector, class name and label helper you change, and update them in the same PR: `frontend/e2e/calendar-course-consistency.spec.js`, `frontend/e2e/*production*.spec.js`, `frontend/e2e/fixtures/**`.
- Production-run specs select by `data-testid`, not by CSS class (a restyle deletes classes; acceptance run 43555468636 broke on `.hint`). When you restyle markup a spec reads, give it a `data-testid` and point the spec at it. `scripts/ci/check-production-spec-selectors.mjs` (PR CI) fails when a class or test id a production-run spec uses is gone from `frontend/src`; library-only selectors go in its commented allowlist.
- Do not put native `required` or `min` on a field that shows inline errors.
- A confirm handler must return early when its dialog is already closed.

## Before you open the PR (run these; CI fails on each)
- `python3 scripts/check-recent-work-revert.py --base origin/main --head HEAD`: add a `Rewrites: #N — reason` body line for every PR it lists.
- `node scripts/check-release-notes-coverage.mjs --base origin/main --head HEAD`; frontend also `node scripts/design-style-ratchet.mjs` (`npm run build` does not run it) and the full `npx vitest run`, not a subset.
- Name scratch files after your branch; re-fetch the PR body after creating it. Commit and push early: a container restart loses unpushed work.

## PR hygiene
- Use the headings in `.github/pull_request_template.md`.
- Paste the declaration block from `python3 scripts/governance/pr_declaration.py --base origin/main --head HEAD`.
- Add `Rewrites: #N` lines where the PR replaces earlier work.
- Check the PR number before editing a PR body.
- After you fix a review thread, reply to it AND resolve it.
- Record choices you made yourself under "Agent 決定的事" ([`ISSUE_PLAN.md`](../sop/ISSUE_PLAN.md); rule: `FOUNDER_STANDING_DECISIONS.md` 2026-11-12 (四)).

## Review cap
- One fix round per PR. The coordinator applies fixes of about ten lines itself and has a small model confirm the delta; later P2s become follow-up issues.
- The merge gate (`.github/scripts/review-gate.mjs`) blocks while Codex reports unresolved findings on the head. A valid Codex finding is therefore fixed in the PR (reply, resolve the thread, new head). A later P2 that is deferred under the rule below still needs its reply with the follow-up issue link and a resolved thread; if the gate keeps blocking after that, record it on issue 5201 and report it. Never work around the gate.
- State that lives in two places (server value + unsaved draft, short list + full list) needs its rule written before coding: which side wins, what an empty value means, what happens when the second load fails. PR 5273 took six rounds because this was decided one finding at a time.
- A blocked permission (classifier, ruleset) is tried once, then handed to the Founder.
- P0/P1 and any regression this PR introduces are always fixed before merge. Other P2 handling: only as written in `FOUNDER_STANDING_DECISIONS.md` 2026-10-12 item 4 and [`MERGE_SOP.md`](../sop/MERGE_SOP.md); do not restate it here.
- A change that LOWERS a risk tier, or touches money or data repair, keeps full review on every head.

## Public repo
- Issues, PRs and comments are public: write IDs only, no names, contact details or secrets.

## Claim before you start
- Check open PRs that reference the issue and the `claimed` label; see [`orchestration.md`](orchestration.md#claim-convention).

## Report format (under 150 words)
- PR number, head SHA, what changed, tests run / not run, deviations from the brief or plan.
