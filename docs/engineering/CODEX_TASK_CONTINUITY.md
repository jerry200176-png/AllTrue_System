# Codex task continuity — observed 2026-11-09

This augments the accepted in-app delivery task. It adds no merge/deploy/data
authority and does not initialize or replace the existing harness store.

## Inventory and sources

| Capability | Observed scope and status |
|---|---|
| Runtime | Local `/opt/codex/bin/codex`: **0.163.0**, Node **24.19.0**. Hosted chat/app build and root model are not exposed. |
| Models | Current collaboration tool advertises five GPT model choices and supported efforts. Actual per-agent model/effort is not exposed; bundled models are not entitlement evidence. No Opus/Sonnet routing. |
| Subagents | This task used two existing reviewers through native tool messages with exact heads, source paths and read-only boundaries. Initial fork arguments are not recoverable here. |
| Skills/MCP | Native CLI `multi_agent` and `skill_search` enabled; no local configured MCP servers. Host-injected connector tools are a separate capability. Existing Repomix **1.19.0** / ast-grep **0.51.1** are reused. |
| Memory/session | CLI `memories=false`; experimental step switching/effort override disabled. Native `resume`/`fork` commands exist; machine/session recovery of this hosted chat was not tested. |
| Existing harness | SQLite tasks, checkpoints and fenced leases already exist in `scripts/harness`. `HARNESS_DB` is unset and the default store is absent here. Historical WSL dogfood is not current cloud activation. Do not create an empty replacement store. |

Official documentation, checked against the installed binary's generated API
schema rather than assuming all current web features apply:

- [Subagents](https://learn.chatgpt.com/docs/agent-configuration/subagents)
- [Customization](https://learn.chatgpt.com/docs/customization/overview)
- [Config reference](https://learn.chatgpt.com/docs/config-file/config-reference)
- [Codex 0.163.0](https://github.com/openai/codex/releases/tag/rust-v0.163.0),
  source commit `83086188ae0614bd882818ff1aa23f3194962012`.
- Existing tools: [Repomix](https://github.com/yamadashy/repomix),
  [ast-grep](https://github.com/ast-grep/ast-grep), MIT; pinned in
  [`tools/agent-context`](../../tools/agent-context/README.md).
- Harness ownership: [`scripts/harness/README.md`](../../scripts/harness/README.md)
  and [`HARNESS_STATUS`](../harness/HARNESS_STATUS.md); historical state must be revalidated.

## Adopted and exercised

1. **On-demand native skill and source reads.**
   `.agents/skills/alltrue-task-continuity/SKILL.md` points to the existing handoff
   and exact section/hash reader. A real local App Server stdio exchange ran
   `initialize`, `config/read`, and `skills/list(forceReload=true)` and returned
   this skill with `enabled=true`, `scope=repo`. No model turn, global config
   change or forced restart occurred. This proves local discovery, not automatic
   injection into an already running hosted chat. The coordinator read and used
   the skill explicitly. Review relevant policies/exceptions in full.
2. **Bounded read-only reconciliation.**
   `node scripts/ci/task-reconcile.mjs --checkpoint <name> --prs <IDs> [--save]`
   batches Git, GitHub and public production-manifest reads. A private checkpoint
   preserves coordinator-written `context` and saves observations atomically only
   after successful reads, with concurrent-update protection. Linux descriptor-
   anchored I/O rejects directory replacement during observation. Ordinary invocation
   compares without advancing it. It never executes stored instructions, merges,
   deploys, repairs data or replaces harness task/lease authority. All checks are
   observations, not a computed landing verdict. An unknown/missing store still
   requires locating its authorized owner; JSON cannot recover its history.

Private evidence stays in the task workspace, outside Git. Public handoff holds
deidentified pointers only. Re-read exact PR head, reviews, required checks,
production identity and prior execution receipt before any side effect.

## Evidence and limits

- Eight isolated Node tests pass: invalid input, stale heads/state, preserved
  goals/effect receipts without replay, partial failure, concurrent update, held
  lock, symlink rejection and directory replacement during observation. The suite
  also runs in the existing CI contract job. Command: `node --test scripts/tests/task-reconcile.test.mjs`.
- A separate real process resumed the private checkpoint, restored its context
  keys and re-read all external state in **1.013 s** without changing the saved
  bytes or invoking a model. Dirty file contents are not fingerprinted: empty
  `changes` is not proof of an unchanged dirty tree. Always read the actual diff.
- Real reconciliation observed main `2badb606`, production `d1230679`, four merged
  PRs and one open PR on 2026-11-09. These are dated observations, not current guarantees.
- Representative question: installed context tools and Vue compression default.
  Full guide: **3,522** tokens; literal relevant section plus provenance: **227**,
  using existing `gpt-tokenizer 4.1.0`, `o200k_base`. Exact-source/provenance
  assertions passed. Seven local reads: median **0.11 ms** full read versus
  **28.95 ms** section reader including Git verification. Retrieval is smaller
  and locally slower; this does not measure model-answer quality or overall delivery time.
- No inference was invoked for this benchmark. Actual billed tokens/cost are
  unavailable; no general savings claim is made. Earlier Vue compression made
  the pack larger, so compression remains opt-in.

Not adopted: an automatic progress router, auto-memory, another lease platform,
or ProgRouter/REM installation. No verified original-author Codex integration
was established for those research names. Use native tools and change strategy
after repeated evidence of the same failure, without adding paid routing work.

## Rollback

Revert this change and remove its private `.local/agent-checkpoints` directory
only after retaining needed task evidence. Existing harness, product branches,
global Codex config, credentials and production state are unaffected. If a future
client needs a new session for discovery, preserve current work first.
