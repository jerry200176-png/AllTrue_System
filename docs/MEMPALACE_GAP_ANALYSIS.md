# MemPalace vs AllTrue — Gap Analysis

> **Date:** 2026-06-27  
> **Reference:** [MemPalace/mempalace](https://github.com/MemPalace/mempalace) v3.5.0 (upstream) vs AllTrue local v3.3.3  
> **Scope:** AI memory / docs governance layer only — not product backend architecture.

> **Consolidation (2026-06-27):** Supersedes hook-based ingest below. **Canonical:** `scripts/mempalace-ingest.sh` only. See `docs/MEMPALACE_ARCHITECTURE_HEALTH.md`.

> **Second-pass review:** `docs/MEMPALACE_ARCHITECTURE_HEALTH.md` — falsifies R1–R8, scores, remaining ROI. **R1 required CLI upgrade (#996/#999) on 3.3.3.**

---

## Executive summary

AllTrue already uses MemPalace as a **local-first semantic index** over agent transcripts (`alltrue-sessions`) and docs (`alltrue-docs`), with post-merge mining and monthly reminders. Upstream MemPalace has matured into a full **retrieval platform** (pluggable backends, hybrid search, knowledge graph, MCP, multi-harness hooks, repair/sweep tooling).

**Highest-value gaps (safe to close incrementally):**

| Priority | Gap | Action |
|----------|-----|--------|
| P1 | Cursor hooks incomplete (no `stop`, broken user-scope paths) | **Adopt** — project-scoped hooks in `.cursor/hooks.json` |
| P1 | No health/repair runbook after ChromaDB corruption incident | **Adopt** — `scripts/mempalace-maintain.sh` |
| P1 | `mempalace.yaml` wing (`alltrue`) misaligned with documented wings | **Adapt** — rename to `alltrue-code` |
| P2 | post-merge mines sessions only, not docs | **Adopt** — extend hook |
| P2 | No `sweep` for message-level recall | **Adapt** — add to monthly SOP |
| P2 | Stale INDEX note ("No memories yet") | **Adopt** — doc fix |
| P2 | MCP not wired for Cursor | **Adapt** — optional issue + manual setup |
| P3 | Knowledge graph / agent diaries | **Reject** for now — markdown docs remain authority |
| P3 | External backends (Qdrant/pgvector) | **Reject** — local ChromaDB fits requirements |
| P3 | AAAK compress dialect | **Reject** — wake-up already ~600–900 tokens |

---

## Subsystem comparison

### 1. Memory model (Wings → Rooms → Drawers)

| Aspect | MemPalace upstream | AllTrue today | Gap |
|--------|-------------------|---------------|-----|
| Hierarchy | Wings (project/person) → rooms (topic) → drawers (verbatim chunks) | Documented: `alltrue-sessions`, `alltrue-docs`, `alltrue-code` | Partial |
| Halls | `hall_facts`, `hall_events`, etc. (metadata taxonomy) | Not used | Low value — our docs use CHANGELOG/AI_REGRESSION roles instead |
| Tunnels | Cross-wing room linking + graph traverse | Not used | **Adapt** later if multi-repo memory needed |
| Closets / L0–L3 | Layered wake-up (identity → essential → search) | wake-up works (~787 tokens) but L0 empty (no identity.txt) | **Adapt** — add identity.txt |

**Local status (2026-06-27):** 2474 drawers; UUID-named wings from transcripts mined without `--wing` flag.

**Recommendation:** Consolidate orphan UUID wings into `alltrue-sessions` via re-mine with explicit `--wing`.  
**Complexity:** Medium | **Risk:** Duplicate drawers until dedupe; run on backup first  
**Verdict:** **Adapt** — track as ops issue, not code change

---

### 2. Indexing & ingestion

| Aspect | MemPalace upstream | AllTrue today | Gap |
|--------|-------------------|---------------|-----|
| Project mine | `mempalace mine <dir>` with room auto-detect from paths | `mempalace.yaml` maps folders → rooms | Works for code wing |
| Convo mine | `--mode convos --wing <name>` | post-merge + manual; wing often omitted → UUID wings | **Fix wing discipline** |
| Sweep | Message-level tandem miner (idempotent) | Not in SOP | **Adapt** — monthly after mine |
| Split | Mega-file transcript splitter | Not needed yet | **Reject** until mega-files appear |

**Recommendation:** Add `sweep` to `scripts/mempalace-maintain.sh` monthly path.  
**Complexity:** Low | **Risk:** Disk growth — monitor with `du -sh ~/.mempalace`  
**Verdict:** **Adopt** in maintain script

---

### 3. Retrieval pipeline

| Aspect | MemPalace upstream | AllTrue today | Gap |
|--------|-------------------|---------------|-----|
| Raw semantic | ChromaDB + local embedder | Same | Parity |
| Hybrid v4 | Keyword + temporal + preference boosting (98.4% R@5 held-out) | Automatic in CLI search | Already benefit if CLI ≥3.4 |
| LLM rerank | Optional top-20 rerank | Not used | **Reject** — adds API cost, conflicts with local-first |
| Scoped search | `--wing`, `--room` metadata filters | Documented in INDEX/CLAUDE | Parity |
| wake-up | L0 identity + L1 essential story (~600–900 tok) | Used; L0 empty; L1 shows raw JSONL snippets | **Adapt** identity + consider `--wing` default |

**Recommendation:** Upgrade CLI 3.3.3 → 3.5.x for hybrid pipeline fixes; set `~/.mempalace/identity.txt` with AllTrue project context.  
**Complexity:** Low | **Risk:** ChromaDB version mismatch → use `mempalace migrate` if needed  
**Verdict:** **Adapt**

---

### 4. Storage abstraction

| Aspect | MemPalace upstream | AllTrue today | Gap |
|--------|-------------------|---------------|-----|
| Default | ChromaDB local | `~/.mempalace/palace` (~120MB) | Parity |
| Pluggable | sqlite_exact, qdrant, pgvector (RFC 001 BaseBackend) | Not configured | **Reject** — no multi-tenant / cloud need |
| Repair | `mempalace repair` rebuilds vector index | Documented in CHANGELOG archive only | **Adopt** in maintain script |
| Health | `status`, embedder identity checks | Manual | **Adopt** |

**Past incident:** ChromaDB `link_lists.bin` corruption → 870GB sparse file (CHANGELOG 2026-05-31). Repair/rebuild SOP is **mandatory**.

**Verdict:** **Adopt** health script — implemented in `scripts/mempalace-maintain.sh`

---

### 5. Synchronization & hooks

| Aspect | MemPalace upstream | AllTrue today | Gap |
|--------|-------------------|---------------|-----|
| Claude Code hooks | session-start, stop (every 15), precompact | `.claude/mempal-*-hook.sh` present | Parity |
| Cursor hooks | sessionStart, stop, preCompact | User `~/.cursor/hooks.json` with broken relative paths; **no stop hook** | **Gap** |
| post-merge git hook | N/A (upstream uses harness hooks) | Sessions only | **Gap** — docs not mined |
| MCP auto-save | 35 tools including diary, traverse | Not configured | **Adapt** optional |

**Cursor-specific upstream note:** Cursor transcript parser is best-effort; `stop` followup is load-bearing for verbatim capture.

**Verdict:** **Adopt** project-scoped `.cursor/hooks.json` + scripts in repo

---

### 6. Knowledge graph & agents

| Aspect | MemPalace upstream | AllTrue today | Gap |
|--------|-------------------|---------------|-----|
| Temporal entity graph | SQLite, validity windows | None | Overlaps with markdown authority |
| Agent diaries | Per-agent wing + diary MCP tools | AGENTS.md + CHANGELOG | Different pattern |
| MCP traverse / tunnels | Cross-wing navigation | Not used | Nice-to-have |

**Verdict:** **Reject** as primary store — AllTrue Engineering Constitution requires **markdown as authority** (CHANGELOG, AI_REGRESSION, TECH_DEBT). Graph could supplement but must not fork truth.

---

### 7. Engineering conventions

| Aspect | MemPalace upstream | AllTrue today | Gap |
|--------|-------------------|---------------|-----|
| Local-first / no API key | Core principle | Aligned | Parity |
| CI integration | N/A (local tool) | Monthly reminder workflow + docs-integrity | Good pattern |
| Version pinning | PyPI releases | 3.3.3 vs 3.5.0 upstream | Upgrade backlog |
| Docker option | Official image | Not used | **Reject** — WSL2 native fine |

---

## Recommendations register

| # | Recommendation | Benefit | Complexity | Risk | Compatibility | Verdict |
|---|----------------|---------|------------|------|---------------|---------|
| R1 | Project Cursor hooks (sessionStart + stop + preCompact) | Auto-capture + session recall; fixes broken user hooks | Low | stop followup uses tokens (suppress with MEMPAL_CURSOR_SILENT=1) | Cursor ≥ hooks v1 | **Adopt** ✅ implemented |
| R2 | `scripts/mempalace-maintain.sh` | status + optional repair + mine + sweep | Low | repair is destructive — confirm flag | WSL2 only | **Adopt** ✅ implemented |
| R3 | post-merge mine docs wing | Docs stay searchable after merge | Low | Background CPU | Existing hook | **Adopt** ✅ implemented |
| R4 | Fix `mempalace.yaml` → `alltrue-code` wing | Consistent scoping with INDEX | Low | None | Existing palace | **Adopt** ✅ implemented |
| R5 | `~/.mempalace/identity.txt` for AllTrue | L0 wake-up context for agents | Low | Local file not in git | MemPalace core | **Adapt** — manual setup |
| R6 | Upgrade mempalace 3.3.3 → 3.5.x | Hybrid retrieval, Cursor parser improvements | Low | migrate if Chroma mismatch | WSL2 | **Adapt** — issue |
| R7 | Wing consolidation (UUID → alltrue-sessions) | Cleaner search scoping | Medium | Duplicate drawers | Re-mine | **Adapt** — issue |
| R8 | MCP server in Cursor | Structured search/add without shell | Medium | MCP config per user | Cursor MCP | **Adapt** — issue |
| R9 | Knowledge graph for decisions | Temporal fact tracking | High | Splits authority from markdown | Constitution conflict | **Reject** |
| R10 | Qdrant/pgvector backend | Scale / multi-machine | High | Cloud egress of verbatim text | Local-first policy | **Reject** |
| R11 | LLM rerank pipeline | +2% recall | Medium | API cost, non-deterministic | CI-OFF cost sensitivity | **Reject** |
| R12 | `alltrue-code` regular mine | Code-aware semantic search | Medium | Index size, stale code chunks | Optional wing | **Adapt** — monthly optional |

---

## Implemented in this pass (low-risk)

- `scripts/mempalace-maintain.sh` — health, mine, sweep, optional repair
- `.cursor/hooks.json` + `.cursor/hooks/*.sh` — project-scoped Cursor integration
- `mempalace.yaml` — aligned to `alltrue-code` wing
- `scripts/install-git-hooks.sh` — post-merge mines sessions **and** docs
- `docs/INDEX.md` — updated MemPalace section (removed stale note, added maintain script)

---

## Repeat comparison trigger

Re-run this analysis when:

1. MemPalace major release (check `mempalace --version` vs upstream)
2. After wing consolidation or MCP adoption
3. After any ChromaDB corruption / disk incident
4. When diminishing returns: no P1/P2 items remain open

**Next high-value item after this pass:** R6 (CLI upgrade) + R7 (wing consolidation) + R8 (MCP optional).
