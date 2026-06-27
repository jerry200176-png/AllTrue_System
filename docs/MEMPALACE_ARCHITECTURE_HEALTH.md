# MemPalace Architecture Health Report — Second Pass

> **MemPalace is explicitly excluded from production SLO, alerting, and incident detection.**  
> It is a local best-effort system and must not be used for production inference.

> **Consolidation (2026-06-27):** Single ingest pipeline — `scripts/mempalace-ingest.sh`.  
> All Cursor/Claude MemPalace hooks removed. Config SSOT: `scripts/mempalace-config.sh`.

> **Date:** 2026-06-27  
> **Reviewer:** Principal Engineer (post-adoption consistency review)  
> **Scope:** MemPalace integration layer + its boundary with AllTrue Engineering Constitution  
> **Method:** Falsify first-pass adoptions R1–R8; inspect coupling, SoT, lifecycle, failure modes

---

## Consolidated architecture (current)

```
Triggers:
  post-merge / manual maintain / manual ingest
        │
        ▼
  scripts/mempalace-ingest.sh  →  engine/run.sh  →  pipeline.manifest.json (DAG)
        │                              │
        │                              ├── dag.sh (derive order)
        │                              └── runner.sh (execute handlers)
        └── stages/*.sh (legacy handlers, unchanged)

Manifest: scripts/mempalace/engine/pipeline.manifest.json
State: events.jsonl (append-only, replay via --replay)
```

**Removed paths:** Cursor hooks, Claude hooks, inline mine in post-merge, sweep, harness fallbacks.

---

## Executive verdict

The **intent** of MemPalace adoption is architecturally sound: a **local, non-authoritative recall index** sitting beside git-tracked markdown. The **implementation** had **internal inconsistencies** that would fail a two-year org review—chiefly **config drift across 5+ files**, **R1 deployed against an unsupported CLI**, and **no explicit SoT boundary**.

**Corrective actions in this pass:**

- `scripts/mempalace-config.sh` — single config SSOT for wings/paths
- Cursor hooks — **fallback path** when `--harness cursor` absent (CLI 3.3.3)
- Explicit SoT boundary in INDEX + identity example template
- Orphan-wing health warnings in maintain script

---

## Architecture scores (MemPalace layer)

| Dimension | Score (/10) | Rationale |
|-----------|-------------|-----------|
| **Architecture** | **6.5** | Clear tier (docs > index > session) after fixes; triple ingestion paths remain |
| **Maintainability** | **6.5** | Config centralization helps; still 3 hook surfaces (Cursor, Claude, git post-merge) |
| **Scalability** | **5.5** | Single-dev WSL2; hardcoded legacy path fallback; no mine lock |
| **Operational** | **7.5** | maintain.sh + repair + link_lists guard; monthly workflow simplified |
| **Technical Debt** | **6.0** | UUID wings, CLI lag, user/project hook conflict, sweep wing ambiguity |

**Composite health:** **6.4 / 10** — acceptable for solo/small team; not yet enterprise-durable without #996/#997/#999.

---

## Domain boundaries & source of truth

```
┌─────────────────────────────────────────────────────────────┐
│  AUTHORITY (git, reviewed, CI/deploy path)                  │
│  INDEX → AI_REGRESSION / CHANGELOG / TECH_DEBT / rules      │
└───────────────────────────┬─────────────────────────────────┘
                            │ write-back after tasks
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  RECALL INDEX (local, ~/.mempalace, not in git)              │
│  Wings: alltrue-sessions | alltrue-docs | alltrue-code      │
└───────────────────────────┬─────────────────────────────────┘
                            │ wake-up / search (read-only hint)
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  AGENT SESSION (ephemeral context)                          │
└─────────────────────────────────────────────────────────────┘
```

**Invariant (must hold):** If MemPalace recall contradicts markdown, **markdown wins**. Agents must not treat drawers as policy.

**Previous gap:** INDEX diagram implied MemPalace was peer to docs without conflict resolution. **Fixed** in INDEX §MemPalace.

---

## Adoption review (R1–R8) — challenged

### R1 — Project Cursor hooks

| Question | Answer |
|----------|--------|
| Correct decision? | **Partially.** Concept yes; shipping `--harness cursor` on CLI 3.3.3 was **wrong**. |
| Cleaner implementation? | Detect harness support; fallback to direct `mine` + static `additional_context` JSON. **Done.** |
| Maintenance cost? | Medium — two code paths until #996 upgrade. |
| Constitution violation? | No, if SoT boundary enforced. |
| Scales 2 years? | Org would gate on CLI version pin + integration test for hook JSON schema. |
| Falsification | `mempalace hook run --harness cursor` **rejects** on 3.3.3 → hooks were **no-ops or errors**. |

**Verdict:** **Adapt** — keep hooks; **block full R1 on #996** or maintain fallback permanently.

---

### R2 — `mempalace-maintain.sh`

| Question | Answer |
|----------|--------|
| Correct decision? | **Yes** — operational necessity post Chroma corruption incident. |
| Cleaner implementation? | Source `mempalace-config.sh`; warn on orphan wings. **Done.** |
| Maintenance cost? | Low. |
| Constitution violation? | No. |
| Scales 2 years? | Yes, as standard runbook entry. |
| Falsification | `sweep` has **no `--wing`** — may amplify UUID wings. Mitigate with post-sweep warning (#999). |

**Verdict:** **Keep** with sweep caution documented.

---

### R3 — post-merge mine docs

| Question | Answer |
|----------|--------|
| Correct decision? | **Yes** — docs change on merge; index should follow. |
| Cleaner implementation? | Call shared config; avoid duplicating wing strings. **Done.** |
| Maintenance cost? | Low. |
| Risk | Concurrent background mines (post-merge + Cursor stop) — idempotent but CPU-heavy; acceptable. |
| Scales 2 years? | Would add flock or queue; overkill for now. |

**Verdict:** **Keep.**

---

### R4 — `mempalace.yaml` → `alltrue-code`

| Question | Answer |
|----------|--------|
| Correct decision? | **Naming yes; wiring incomplete.** |
| Cleaner implementation? | Either add optional `--mine-code` to maintain.sh or **document as on-demand only**. |
| Maintenance cost? | Low if left optional. |
| Falsification | **No automated code wing mine** — `alltrue-code` is documentation fiction until explicitly run. |

**Verdict:** **Keep name**; treat code wing as **opt-in** (not in default maintain path — avoids stale code index bloat).

---

### R5 — `identity.txt`

| Question | Answer |
|----------|--------|
| Correct decision? | **Yes** for L0 wake-up quality. |
| Cleaner implementation? | Committed template `docs/mempalace-identity.example.txt` → copy to `~/.mempalace/`. **Done.** |
| Constitution violation? | No — local only, no secrets. |

**Verdict:** **Keep** — manual copy remains correct (user-local).

---

### R6 — CLI upgrade (#996)

| Question | Answer |
|----------|--------|
| Correct decision? | **Yes — now P0 for R1 full fidelity.** |
| Blocker? | R1 fallback unblocks until upgrade. |

**Verdict:** **Required** for production-grade hook integration.

---

### R7 — Wing consolidation (#997)

| Question | Answer |
|----------|--------|
| Correct decision? | **Yes** — UUID wings break scoped retrieval invariant. |
| Cleaner than re-mine? | Long-term: upstream wing rename/delete if available; else re-mine. |

**Verdict:** **Keep issue open.**

---

### R8 — MCP (#998)

| Question | Answer |
|----------|--------|
| Correct decision? | **Optional layer** — not required for consistency. |
| Constitution risk | Medium if agents write decisions to palace instead of markdown. |
| 2-year org | Would standardize on MCP **read** tools only; writes go to git. |

**Verdict:** **Defer** — P3; adopt read-only MCP if at all.

---

## Hidden debt discovered

| ID | Debt | Severity | Mitigation |
|----|------|----------|------------|
| D1 | User `~/.cursor/hooks.json` conflicts with project hooks | Medium | Document removal (#1000) |
| D2 | Triple ingestion (Cursor / post-merge / maintain) without lock | Low | Idempotent mine; monitor CPU |
| D3 | `sweep` wing assignment undefined | Medium | Warn after sweep; track #999 |
| D4 | Claude hooks (`.claude/`) vs Cursor hooks — parallel wrappers | Low | Share config only; harness differs |
| D5 | `sessions` legacy wing alongside `alltrue-sessions` | Medium | #997 consolidation |
| D6 | wake-up L1 shows raw JSONL — may stale vs docs | Medium | SoT rule + prefer `--wing` scoped wake-up |
| D7 | MemPalace vendor coupling (Chroma + embedder) | Low | Accept; repair path exists; no cloud |

---

## Duplicated concepts (should not multiply)

| Concept | Canonical location | Deprecated / secondary |
|---------|-------------------|------------------------|
| Wing names | `scripts/mempalace-config.sh` | Inline in INDEX examples (OK as samples) |
| Transcript path | `mempalace-config.sh` auto-detect | Legacy `home-jerry-alltrue` fallback only |
| MemPalace SOP | `docs/INDEX.md` §MemPalace | GAP_ANALYSIS (historical) |
| SoT policy | INDEX + this report | — |

---

## Top 10 highest-ROI improvements remaining

| # | Improvement | ROI | Effort | Issue |
|---|-------------|-----|--------|-------|
| 1 | Upgrade mempalace CLI (#996) — unlocks R1, hybrid search | **Critical** | 30m | #996 |
| 2 | Wing consolidation (#997) — fixes scoped retrieval | **High** | 2h | #997 |
| 3 | Resolve user vs project Cursor hooks (#1000) | **High** | 15m | #1000 |
| 4 | Audit sweep wing behavior (#999) | **High** | 1h | #999 |
| 5 | Copy identity template to ~/.mempalace/ (R5) | Medium | 5m | — |
| 6 | Pin mempalace min version in maintain.sh check | Medium | 30m | #996 |
| 7 | MCP read-only evaluation (#998) | Medium | 1h | #998 |
| 8 | Optional `--mine-code` flag (R4 completion) | Low | 1h | backlog |
| 9 | Concurrent mine flock (post-merge + hooks) | Low | 2h | backlog |
| 10 | Integration smoke: hook emits valid JSON | Medium | 1h | with #996 |

**Stopping condition:** Items 1–4 are the last **high-value architectural** fixes for the MemPalace layer. Items 5–10 are incremental; diminishing returns after #996+#997+#999+#1000.

---

## Would a large org keep this after 2 years?

**Yes, with conditions:**

- MemPalace remains **index-only**, never policy store
- Single config module (`mempalace-config.sh`) — **now present**
- CLI version pinned in onboarding checklist
- Hook contract tested on upgrade
- UUID wing drift monitored (`status` in monthly maintain)

**No** to: knowledge graph as SoT, cloud vector backend, copying upstream plugin wholesale.

---

## Related documents

- First-pass gap analysis: `docs/MEMPALACE_GAP_ANALYSIS.md`
- Config SSOT: `scripts/mempalace-config.sh`
- Identity template: `docs/mempalace-identity.example.txt`
- Product architecture audit: `docs/reviews/ENGINEERING_AUDIT_2026-06-27.md` (separate scope)
