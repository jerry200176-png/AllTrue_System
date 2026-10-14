# Codex PR review quota throttle

## Scope and present state — 2026-10-14

User requested all repos throttled. Shared core/configuration is prepared in
PR #4092; **live execution is not activated**. No new API fee, credential, token
scope, required check or permission is introduced. Existing independent review,
CI, branch protections, merge/security and Founder boundaries remain.

`.github/scripts/codex-review-fleet.json` lists the eight visible repos. AllTrue,
sunrise-cafe and portfolio-ops have automatic-review evidence. Samples from
income-statement-app, engineering-intelligence and income-statement-app-releases
had none; exoprotocol/jerry-cai-todo had no PR samples. Neither proves disabled.
At **2026-10-14 12:19:58 UTC** the user reported changing repo settings. Independent
verification is pending; previously queued reviews are not counterevidence.
Personal Automatic off alone is not proof that repo overrides are off.
No repo entry is enabled or marked independently verified. Unknown base branches
hold; no default branch or business classifier is guessed for other repos.

## Shared behavior

Open/non-draft, 15-minute stability or exact author-ready signal, complete thread
and pinned-producer required SUCCESS evidence at the exact head are mandatory.
Skipped/neutral/missing/unknown states hold. Ready comment format is exactly
`codex-review-ready <head> <base>` by the PR author with trusted association.
Before reservation reread refs, state and CI. Budget across heads/base/policy
versions is one initial review plus one consolidated-fix re-review. Failed or
ambiguous requests consume slots; hold/completed transport state never means passed.
Re-review includes delta SHAs and unresolved thread IDs; critical risk, changed
base or unknown base dependencies require full scope. Other repos default full
scope, pending their own existing risk adapter; AllTrue reuses its classifier.

The core now awaits stores. `FileLedger` supports a shared durable filesystem;
`GitHubLedger` prepares Contents API storage at `ledger.json` on a **pre-provisioned
data-only orphan `codex-review-state` branch** in each repo. Blob SHA comparison
rejects concurrent stale updates before review send. History removal/reordering,
missing/corrupt state or lost PUT response stops; restart reads persisted state,
never retries a reservation blindly. Preserve the ledger when disabling/reverting.
No branch/file has been provisioned remotely. Only already-authorized Contents
write may be used; absence of that capability is a blocker, not a request for a
new token or broader permissions. The existing read-only workflow stays read-only.

One shared API client stops the whole sweep on secondary/primary limits,
429/Retry-After/reset or GraphQL RATE_LIMITED; no automatic POST retries. The
executor must persist/reload its cooldown across runs using the provided callback.
The CLI only inspects its explicitly configured repo; it does not sweep eight
repos. It is hypothetical first-review simulation, not the live ledger consumer.

## Concrete protected activation packet

1. **Merge #4092 only at its final reviewed SHA**, with fresh exact-head required
   CI and independent review of the expanded diff. The earlier independent review
   covered simulation-only. AllTrue Founder standing decision 2026-10-14（二）§5
   still requires the concrete control-plane merge decision. No queue/merge here.
2. For each allowlisted repo, read its governance/default branch/required checks;
   independently verify settings after the observation baseline. Prepare its
   adapter/config under that repo's own approval boundary. No new PRs in this task.
3. Provision data-only ledger branches only in already-authorized repos, reconcile
   existing reviews into their budgets and retain evidence. Do not copy main's
   workflows or trigger code/deployment. Unknown/running external review holds.
4. **Proposed single canary, not authorized or started:** AllTrue PR #3890, full
   scope, head `2dc969f4202d2f59a11aa49494777dd0e8a1b258`, base
   `3b8dd79e4e52955dcb01384f17d877321a90b935` (metadata observed 2026-10-14).
   Re-read immediately; any ref/state change aborts this target. Require exact-head
   CI, remaining budget and no running review first. One existing Codex review
   request maximum; no retries/other PRs, zero provider API calls or extra API fees;
   exact quota units unknown. The earlier #4091 observation is stale: it is closed.
   Workflow-bot comment acceptance/authentic marker readback remains unverified;
   no live comment transport is installed. Canary needs a concrete decision and
   approved transport, never an ad-hoc trigger from this unmerged branch.
5. After canary evidence, separately activate existing-authority execution per repo.
   No new scheduler, PAT/key, workflow write permission, merge/deploy or gate bypass.

Rollback: disable configured execution and revert #4092 through reviewed changes;
keep Automatic off and preserve ledgers/cooldowns so rollback cannot reset budgets.
Tests use synthetic transports only; existing required Control Plane Contract Lint
runs them. Sources: [Codex GitHub review](https://learn.chatgpt.com/docs/third-party/github),
[Contents API](https://docs.github.com/en/rest/repos/contents#create-or-update-file-contents),
[rate-limit handling](https://docs.github.com/en/rest/using-the-rest-api/best-practices-for-using-the-rest-api).
