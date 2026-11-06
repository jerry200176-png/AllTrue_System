# Merge SOP (Risk-Based)

**Owner:** Founder / CTO Agent  
**Tier definitions:** [`docs/governance/RISK_BASED_MERGE_POLICY.md`](../governance/RISK_BASED_MERGE_POLICY.md)  
**Fleet capability:** [portfolio-ops `AUTONOMY_POLICY`](https://github.com/jerry200176-png/portfolio-ops/blob/main/governance/AUTONOMY_POLICY.md) — AllTrue applies its stricter T3/protected product boundary.
**Last verified:** 2026-08-29

## Before opening a PR

1. Local WSL worktree only: run `make agent-preflight` in a **non-forbidden** worktree. Cloud sessions skip this ([`WORKTREE_POLICY.md`](../governance/WORKTREE_POLICY.md)).  
2. Classify **Risk-Class** (R0–R3) and **Autonomy-Tier** (T0–T3); when unsure, pick higher.
3. Ensure tests match risk (R1+ needs regression coverage for the bug/path).

## Before merge

Each class's merge requirements are defined only in [`RISK_BASED_MERGE_POLICY.md`](../governance/RISK_BASED_MERGE_POLICY.md#risk-classes) (not restated here); the lane and gates per class are in [`DELIVERY_LIFECYCLE.md`](DELIVERY_LIFECYCLE.md).

All classes also need the exact final-head review evidence described in [Final-head review evidence](#final-head-review-evidence): a completed clean Codex summary, or an actual independent review recorded by the exact owner for the full head when Codex has not reviewed it. Current-head running/incomplete review or findings cannot be overridden by fallback. Missing evidence is `review-pending`, not merge clearance.

## After required checks (R0–R2 only)

When the applicable risk gates and exact-head review pass without a T3/protected boundary, add `queue`; do not wait for a blanket human approval. A changed head needs fresh CI and review; `review-pending` retains the queue label while waiting. Mechanics: [below](#land-queue-and-auto-delivery-mechanics).

```bash
gh pr edit <PR-number> --add-label queue
```

This SOP does not authorize a ruleset edit or a fake owner review marker. Never `--admin`. If a **code** merge to `main` starts `deploy.yml`, that is the product control plane (I1). Docs-only still skips deploy. Extra mutation uses committed `workflow_dispatch`, never SSH / artisan / phpunit on the Pi.

## Continue an approved task

Apply [approval continuity](../governance/RISK_BASED_MERGE_POLICY.md#approval-continuity-within-the-authorized-task): after explicit Founder GO, execute the approved scope without asking again at every merge/deploy/verification step. Recheck exact-head evidence and the approved boundary; request a new decision only for a material scope change or a genuinely missing authorization. Existing platform gates remain mandatory.

## After merge

Follow [`DELIVERY_LIFECYCLE.md`](DELIVERY_LIFECYCLE.md) stages 7–9 (release → verify → done). For high-risk changes also update the Knowledge Graph / Lessons.

## Do not

- Force-push `main`.  
- Bypass required checks (`--admin`).  
- Wait for a human merge click when checks are already green and no T3/protected boundary is involved.
- Re-enable known CI-storm / autonomous-loop workflows without R3.  
- Use a fake second identity to satisfy R2/R3.

## Land queue and auto-delivery mechanics

For same-repository, non-draft PRs, `.github/workflows/presubmit.yml` evaluates the diff from the base revision using `scripts/governance/autonomy_gate.py`.

**Auto-merge is dormant.** `.github/workflows/auto-merge-safe.yml` would enable GitHub server-side squash auto-merge for an exact, declared, machine-validated T0/T1 result, but its `enable-auto-merge` job holds (exit 0, no merge) until `review/final-head` is a required check in the main ruleset. That is not the case today, so land every PR with the `queue` label.

### Land queue

Add label `queue` to a green-ready PR instead of racing `gh pr update-branch` or `gh pr merge --auto`. `.github/workflows/land-queue.yml` runs its script from the base branch and never executes PR code. It updates a BEHIND head; a conflict, failed required check, or unresolved review thread removes `queue` with one per-head explanation. Unknown thread metadata never merges. Green required checks and zero unresolved threads still need exact-head review evidence: missing or incomplete review waits as `review-pending`, retaining `queue`. Immediately before every serial or batch squash, the queue re-reads both threads and review for the tested head. With strict up-to-date off it first tests 2–4 eligible heads together, then lands only a green batch whose base and member heads remain unchanged. Batching: the queue cuts `chore/land-queue-batch-<run>` from the main tip and merges up to 4 oldest eligible heads into it (a conflicting PR is left out with a comment), dispatches the required-check workflows on it, and squash-merges the members in order only when every required context is green and main has not moved. Red or timed out: members get a `batch-failed` comment and land one at a time. One serial merge per run; a head behind main is always updated first (`behindBy`), so strict off never merges an untested combination. Fork PRs never queue. Never `--admin`. GitHub's native merge queue is unavailable for this user-owned repo (the `merge_queue` ruleset probe returned 422).

#### Final-head review evidence

The shared `.github/scripts/review-gate.mjs` accepts a trusted Codex summary only when it is completed for the full lowercase head SHA and explicitly has no unresolved findings. An actual independent review may instead be recorded by the exact repository-owner account with the full-head `independent-review:v1` pass marker when Codex has not reviewed that head. A current-head Codex state with status `failed` (Codex could not run, for example a usage limit) counts as not reviewed: its carried-over findings flag neither passes nor vetoes, and open review threads still block through the land queue. For a PR declaring R3 or T3 (an unknown or unparsable tier counts as R3) the gate additionally needs, on the exact head, an owner `<!-- gate:security-data-reviewer:v1 sha=<head> verdict=pass -->` marker (written by `.claude/agents/security-data-reviewer.md`) or the `independent-review:v1` marker; any owner `gate:<role>:v1 ... verdict=fail` marker on the head vetoes in every lane. The marker is provenance, not a second identity. Current-head findings, an active or otherwise incomplete current-head Codex review, unreadable evidence, or malformed state fail closed; an owner marker cannot override them.

`review-gate.yml` publishes `review/final-head` from default-branch code. Its event and scheduled publishers share one non-cancelling lock with pending-run retention, and comment deletion re-evaluates withdrawn evidence. Until the main ruleset pins this check to the GitHub Actions app, native auto-merge stays off (a running/incomplete current-head Codex review also cannot be overridden by owner fallback); use the land queue. The Founder may add the required check only after the workflow is on main and has published its first sweep. This change makes no ruleset mutation. Batch heads have no PR review check; each member is checked in-process before squash.

Installing this control-plane gate remains a T3 merge decision. Existing scoped approvals and accepted review evidence remain valid under [approval continuity](#approval-continuity-within-the-authorized-task); this gate does not retroactively reopen completed approved work or grant new merge, deployment, identity, credential or production authority.

After merge, `deploy.yml` remains the only application production executor. Its
deploy and principal-rotation jobs, together with the guarded repair workflows,
share the non-cancelling `alltrue-production-side-effects-v2` concurrency lock;
preflight/classification runs do not occupy that side-effect queue. The deploy
workflow compares the exact production manifest SHA to current `main` and uses
`classify_activation_scope`: tests, docs, Exo metadata, and the read-only
convergence scheduler do not turn an ordinary runtime release into a manual
activation. T0/T1/T2 deploys do not reference the protected
`production-activation` environment; exact-SHA, required CI, preflight,
health/smoke, rollback, and fail-closed behavior remain mandatory.

Control-plane-only changes are reported as `control-plane-verified` once merged
to `main`; they do not require an application runtime deployment and are never
reported as `production-verified`. A mixed control-plane/application change
remains an application release and is classified from its full effect.

Reversible T2 changes with successful exact-target CI, rollback readiness, and
non-protected scope may auto-deploy. Missing or contradictory deterministic
evidence is ambiguous and stays held. T3, unknown classifications, production executor
changes, security/data boundaries, and irreversible operations stay held for
risk-appropriate review or the protected Founder boundary. Only
Founder-required/T3 activation references `production-activation`; all supported events use the same
static policy: Founder required reviewer, self-review allowed, administrator
bypass disabled, and main-only deployment branch policy. Workflow-dispatch typed
confirmation remains only for exceptional manual phases; it is not a second
normal approval path. No fake reviewer or admin bypass is introduced.

Founder 1A (2026-10-07): a deploy run skips the manual approve and uses the reviewer-less,
main-only `production-auto` Environment only when `autonomy_gate.evaluate_founder_go_range`
(see its docstrings for the exact evidence and GO token) accepts every PR between the
production manifest SHA and the target, under both the deployed and the target policy,
again right before the executor. Anything else keeps the unchanged `production-activation`
reviewer gate, and the run summary names the PRs that lack a GO.

This governance change itself is T3: it must pass the governance cool-off and
protected review process before its new capability is used in production.

### Activation classification by effect

For production activation, a sensitive path is a signal for inspection, not an
automatic T3 decision. The deterministic classifier uses three outcomes:

| Activation class | Machine rule | Result |
|---|---|---|
| `routine` | T0/T1/T2 behavior remains unchanged; no protected effect is found | Existing automatic path, with T2 exact-target CI and rollback evidence |
| `guarded-sensitive` | Sensitive runtime path, inspectable read-only diff, at most 3 sensitive files and 240 changed code lines, and no protected effect | Machine minimum T2; existing exact-SHA, rollback, health, critical-smoke, production-verification and automatic abort/rollback path |
| `founder-required` | Control plane, migration/repair, entitlement/deduction, auth trust boundary, billing/ledger semantics, privilege/credential/privacy boundary, uninspectable diff, or boundedness failure | T3/protected; hold for Founder approval |

Generated historical release-note bundles are excluded from current-effect
marker scanning. This removes false T3 classifications caused by old words in a
generated asset while preserving the real business/security operation when the
changed code expresses it. Unknown or missing evidence fails closed.

### Parallel agents

- Before starting: run `node scripts/pr-overlap.mjs` (or read the PR's `<!-- pr-overlap -->` sticky comment). If another open PR touches the same files, coordinate with that session or wait. Prefer small PRs that merge fast (limits: [`FOUNDER_STANDING_DECISIONS.md`](../governance/FOUNDER_STANDING_DECISIONS.md)).
- Landing: add label `queue`. Don't loop `update-branch`, `--auto` or custom merge scripts.
- No stacked PRs: branch from main after the dependency merges.
- Before merging an agent PR, read every `-` line of `git diff origin/main...HEAD`; nothing outside the PR's scope may be removed (2026-10-06 PR-C2 #3631 stale-copy revert).
- Release notes: change fragments only (`docs/changes/…`). Never edit `CHANGELOG.md`, the generated JS or the exemption lists; `phpstan-baseline.neon` may only shrink.
- Deploys: only `deploy.yml`. A range whose every PR is R0-R2, or an R3 range accepted by the R3 auto controls (2026-10-21（二）之二), deploys on its own CI; otherwise it waits for the release train, two windows a day at 12:17 and 18:17 Asia/Taipei (Founder 1A, 2026-10-07; batch approvals, 2026-10-14 — see `FOUNDER_STANDING_DECISIONS.md`).
