# Graphify AllTrue context pilot v1

**Decision: ADOPT_OPTIONAL** — optional Skill navigation only; no always-on, no production dependency, no new framework.

## Scope

Single-module, local, **code-only**, reversible trial of [Graphify-Labs/graphify](https://github.com/Graphify-Labs/graphify) for BugReport / in-app feedback planning context. Not an Engineering OS, auth source, or company knowledge authority. Must not block In-App → GitHub sync or already-authorized product work.

## Pinned versions

| Item | Value |
|------|--------|
| AllTrue revision | `629a1477941578dd06f183c4db61f51c07e6efb0` |
| Graphify upstream | `26b02b5e3430e4ab85dd7e72c7b98836d8e65c48` |
| Package / CLI | `graphifyy==0.9.63` / `graphify` |
| License | Apache-2.0 (+ NOTICE; prior MIT in LICENSE-MIT) |
| Install | Isolated venv under local evidence path — **not** AllTrue or system Python |
| Index | 15 BugReport-related files (controller, service, models, routes slice, Feature test, FE helpers/page) |
| Mode | AST only: no LLM dedup, no doc/image semantic extract, no MCP, no HTTP serve, no hooks |

Full graph/HTML/cache retained only under local evidence  
`/home/jerry/workspace/state/alltrue/delivery/evidence/graphify-context-pilot-v1/`  
(not committed; derivative index, not runtime truth).

## Build / query facts

- Build: `graphify update <snapshot> --no-cluster` → **355 nodes, 619 links**, ~0.9s, ~58 MB RSS; `failed_sources=[]`; tokens `0/0`.
- Queries used: `query`, `path`, `explain`, `affected`, `god-nodes` (parameters taken from live `--help`).
- Traditional sealed baseline `rg` on same snapshot: **37 ms** before Graphify queries.

## Query outcomes vs source truth

| Question area | Graphify | Notes |
|---------------|----------|--------|
| Status update entry → service | **Hit** | `.updateStatus()` → `BugReportService` → `.changeStatus()` EXTRACTED |
| disposition / resolution evidence / product_loop | **Partial** | Methods/tests/FE options found; **no** const nodes for `DISPOSITION_MARKER`; **no** path `changeStatus`↔`productLoop` |
| note_display / raw note FE+BE | **Partial** | `statusLogDisplayNote` wiring hit; API field `note_display` **absent** as node |

Documented misses/misleading edges: shortest Controller→Service path via `.addComment()`; `affected(.changeStatus())` empty; broad query floods tests and truncates on token budget.

## Comparison (this sample only)

Graphify helps **symbol neighborhood** and FE helper call/import graphs after a scoped index.  
`rg` remains necessary for **string markers, JSON DTO keys, Laravel route→action**.  
Do not generalize from this 15-file slice.

## Lead → worker handoff

Planning Lead used Graphify as candidate finder, then re-read snapshot sources/tests. Plan kept EXTRACTED / INFERRED / AMBIGUOUS; worker received **path list only** (no full graph).  
Isolated historical review task worker checks: **13/13 PASS** on plan paths (`handoff/WORKER_RESULT.txt` in local evidence). No product code mutation; no fake user replies.

## Recommendation

| Option | Choice |
|--------|--------|
| Always-on / hooks / fleet scheduler | **REJECT** |
| Rewrite parsers / large integration so the tool “works” | **Stop** — not worth bending the product line |
| Optional one-section Skill nav pointing at this research | **ADOPT_OPTIONAL** |

Fallback when graph stale/missing/query fails: existing search + primary evidence; never freeze unrelated product work; empty graph ≠ no blast radius.

## Reproduce (local)

1. `agent-start alltrue <task>` worktree at pinned AllTrue SHA.  
2. Clone Graphify at pinned rev into evidence; `python -m venv` + `pip install .` → `graphifyy==0.9.63`.  
3. Copy only the 15 relative paths into an isolated snapshot.  
4. Seal traditional `rg` baseline, then `graphify update snapshot --no-cluster`.  
5. Run query/path/explain/affected; compare without fabricating edges.  
6. Lead plan → light worker path checks.

## Artifacts

- Local evidence dir (graph, queries, comparison, handoff, install notes).  
- This research doc + optional Skill §9.  
- PR on branch `chore/task-graphify-context-pilot-v1`.
