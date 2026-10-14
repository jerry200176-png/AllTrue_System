# Codex PR review automation

## Current phase — 2026-10-14 quota throttle, dry-run only

The Founder disabled Automatic review. This supersedes the previous plan to
review every push/backfill every PR. Do not re-enable it or add another hourly
review task. Independent review and all existing merge/CI/security gates remain.

The implementation is bounded to `.github/scripts/codex-review-throttle.mjs`,
the read-only snapshot collector, one manual simulation workflow and focused
CI tests. It does not modify application data, branch protections or land queue.

## Contract and architecture boundary

- Repository + PR + exact head + base + policy version is the durable key.
  A missing/corrupt ledger is unknown, never an empty initialized ledger.
- Open, non-draft PR; all required contexts on the exact head must have completed
  with SUCCESS from the pinned app/repository. Missing, pending, skipped, neutral,
  unknown, failed or incomplete evidence holds. Reuses land-queue producer
  filtering/latest-check reduction/thread completeness and the existing Python
  risk classifier; its merge-specific skipped/neutral allowance is not reused.
- Wait 15 minutes after observed activity, or accept an exact author-ready signal.
  The collector uses PR `updated_at` as a conservative last-change bound, so
  unrelated activity can delay a simulation. Ready format is exactly
  `codex-review-ready <40-char head> <40-char base>` in a comment by the PR author
  with OWNER/MEMBER/COLLABORATOR association. PR titles/bodies are never readiness.
- Re-read head, base, open/draft and CI immediately before reserving a request.
  Initial review once, then one consolidated-fix re-review on a different head
  with explicit fixReady. All policy versions/base revisions share the two-slot
  budget. Failed or ambiguous attempts consume a slot; holds never mean passed.
- Re-review carries from/to SHAs and unresolved thread IDs. Scope is full for the
  first review, risk T3, financial/auth/deduction/concurrency/migration/control
  plane reasons, changed base or changed/unknown base dependencies. Otherwise it
  is incremental. Current collector conservatively requests full scope because
  it does not attest dependency equivalence.
- Journal states: requested → running → completed/failed. Persist requested before
  POST; timeout/crash means read back by the unique marker, never blind resend.
  A missing readback remains requested. No automatic retries or third request.
  Completed means transport completion, never a clean review or permission to merge.
- `FileLedger` uses exclusive lock, atomic rename and fsync. Caller supplies a
  durable filesystem shared by every controller run. Stale locks/tempfiles hold
  until an operator reconciles them; do not erase state to recover quota.

## Shipped safety boundary and remaining activation work

The CLI/workflow is **simulation-only**, manual and read-only. Its hypothetical
empty ledger is explicitly for first-review simulation, not a production budget
source. It prints `dryRun=true`, `liveEnabled=false`, `reviewPassed=false`, never
comments, requests review, dispatches other workflows or calls a model API. Any
existing Codex review/request is held as uncorrelated evidence. No canary ran.
Only trusted main code executes; PR file names and patches enter the classifier
as JSON on stdin. No PR code checkout/execution or new PAT/API key is needed.

The tested controller accepts an injected transport, but no real transport or
cross-run live ledger backend is installed. Before enabling live requests:

1. Obtain Founder decision for the concrete workflow/control-plane diff. Rollback:
   revert this PR; keep Automatic review off. No production data rollback needed.
2. Provision/verify durable shared ledger storage, reconcile existing reviews and
   explicit review-failure evidence, and bind readbacks to an authentic bot and
   exact request marker/refs. Running/unknown external review must hold.
3. Report the exact canary PR/head/base and expected use (**one existing Codex
   review slot, zero additional API calls/fees**), then obtain canary authorization.
   Official documentation supports a human `@codex review` comment, but does not
   establish that this installation accepts workflow-bot comments, nor an exact
   token/quota cost. Verify that capability with the single authorized canary.
4. Only after evidence, separately review any event/timer activation. Current
   manual workflow checks out main, so this PR's new code cannot be exercised by
   dispatch before merge. Required CI runs its synthetic tests at the PR head.

Sources: [Codex GitHub review](https://learn.chatgpt.com/docs/third-party/github),
[Codex action](https://github.com/openai/codex-action) (Apache-2.0, requires a
provider API key; not installed), [PR-Agent](https://github.com/The-PR-Agent/pr-agent)
(MIT design reference for incremental/persistent findings; no code/package imported).

## Verification

`node --test scripts/tests/codex-review-throttle.test.mjs` runs in the existing
required Control Plane Contract Lint job. Fixtures cover pushes/reentry, exact
CI states/producers, base changes, running/unknown requests, timeout readback,
restart persistence, locking, two-round limit and malicious text. No real reviews
or paid calls occur in tests. Record exact-head CI and any limits in the PR.
