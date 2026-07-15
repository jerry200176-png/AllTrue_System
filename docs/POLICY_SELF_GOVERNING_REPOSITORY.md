# POLICY: Self-Governing Repository

> **What this is:** Meta-mechanism for *maintaining* existing governance — ownership, automatic verification, expiry, retirement, conflict handling, and exit criteria.  
> **What this is not:** A new behavioral rulebook for agents. **Do not** derive new “must-read” encyclopedias from this file.  
> **Goal:** 3–5 years, ~10 engineers + ~30 AI agents, **low cognitive load without continuous Founder attention**.  
> **Depends on:** `RULE_AI_GOVERNANCE_PRINCIPLES.md`, `CONTROL_PLANE_CONTRACT.md`, `CONTRADICTION_REGISTRY.md`, `docs-integrity` + `control-plane-lint`, Design Review Round‑2.  
> **Status:** Design Accepted for discussion → becomes Binding when Founder marks Exit Criteria Phase‑0 complete.

---

## 0. Design axioms (only five)

1. **Machines enforce; humans arbitrate.** Routine drift is a CI failure, not a CEO escalations queue.  
2. **Every governance artifact has an owner path + a verifier + a TTL.** No orphan policy text.  
3. **Retirement is a first-class operation.** Soft-delete (archive banner) beats “leave forever just in case.”  
4. **Conflicts resolve to one survivor.** Never average two SoTs.  
5. **Exit = Founder hours on governance → near zero.** If Founder still edits INDEX weekly, the system has not graduated.

---

## 1. Role catalog (who — not named people)

Roles are **functions**, attachable to CODEOWNERS paths / CI jobs. Today one human may wear several hats; the design must still work when hats split.

| Role | Responsibility | May be | Must not |
|---|---|---|---|
| **Verifier (machine)** | Fail PR/main when an invariant breaks | GitHub Actions + scripts | Invent product policy |
| **Steward (rotating human or designated agent)** | Keep SoT fresh within TTL; archive expired handoffs | Any engineer / scheduled agent with `issues:write` where allowed | Create parallel SoT |
| **Author (PR opener)** | Update SoT in same change set; answer Principles §3 four questions | Human or agent | Land knowledge only in PR body |
| **Arbiter (Founder or delegate)** | Only for: new domains, SoT creation, retiring P0/safety rules, unresolved K-conflicts, T3 product rules | Human | Daily branch hygiene, link fixes, runner-fact digests |
| **Consumer (any agent)** | Read Entry → SoT → code; report drift via PR/issue | All agents | Treat chat / plans as authority |

**Founder intervention budget (target after Exit Criteria met):** ≤ **1 hour / month** on governance, mainly Arbiter tickets.

---

## 2. Governance object register

Each **existing** object (no new rule domains). Columns answer the user’s questions.

| Object | SoT path | Owner role | Auto-verify | Expires when | Retire how | Conflict handler |
|---|---|---|---|---|---|---|
| Runtime contract | `CONTROL_PLANE_CONTRACT.md` + `deploy.yml` | Arbiter + Verifier | `control-plane-lint` (I1–I5, E*) | Only via explicit `[contract-change]` | Superseding contract revision; old invariants listed in registry | K-row + contract always wins |
| Contradiction map | `CONTRADICTION_REGISTRY.md` | Steward (add row) / Arbiter (demote) | Lint requires K1–K10 present; new K* must resolve or demote | Unresolved K* > 14 days | Mark resolved + demote loser; never delete ID | New K-row required before merge of conflicting prose |
| AI Principles | `RULE_AI_GOVERNANCE_PRINCIPLES.md` | Arbiter | Docs-integrity required-file (once wired) + PR checklist random sample | Principle obsolete only by Arbiter PR | Supersede section → archive note; **do not** grow indefinitely | Principles > ad-hoc AGENT tips |
| P0 / dangerous ops | `p0-gate.mdc` + `DANGEROUS_OPERATIONS.md` | Arbiter | Existing red-line culture + htaccess/secret scanners | Never “expire”; may be **narrowed** after 2y clean | Retire only with ADR + Arbiter; keep historical accidents | Production-safety always wins over convenience |
| AI Entry | INDEX Entry Card → future `AI_ENTRY.md` | Steward | Token budget check (planned job); entry must stay ≤ target | Entry > ~1k tokens or >80 lines | Split out; **delete prose from always-on copies** | Single entry axiom |
| Docs router / portals | `INDEX.md` + future portals | Steward | Link lint (docs-integrity); forbid deploy-behavior prose (I2) | Catalog row > 90d without SoT touch + unused | Remove row or archive target | Dual catalog → delete one |
| AI regression lessons | `AI_REGRESSION_LESSONS.md` | Steward (module owners) | Size/claim consistency; module-index must exist | Individual R* superseded by code+test | Move body to archive; keep index stub | Code+test > stale R text after ADR/fix |
| Tech debt | `TECH_DEBT.md` | Steward | Open TD count advisory in health report | TD Done/Deferred with date | Collapse Done older than 180d to archive summary | — |
| ADRs | `docs/adr/*` | Author → Arbiter accept | Accepted list in `adr/README`; status frontmatter | Superseded when replaced | Status=`superseded by ADR-N`; file stays | New ADR supersedes old; never silent edit |
| Branch/PR hygiene | workflows + Runbook B | Verifier | `branch-hygiene` report; delete_on_merge; file-count gate (planned) | Branch age > TTL (7d warn / 14d retire candidate) | Archive tag + delete remote; close contaminated PR | TBD TTL > agent WIP sentiment |
| Issues lifecycle | GitHub issues | Steward bot + authors | Stale search (needs `issues:read`) | Open >90d no activity | `status:archived` / close `not_planned` | Docs/ADR absorb lasting truth |
| Plans | `.cursor/plans/**` | Author | No runtime authority (policy) | Plan > 30d after merge of last PR | `plans/archive/YYYY-MM/` | Never cite as SoT |
| Always-on rules | `.cursorrules` + always_applied mdc | Steward + Arbiter for P0 | **Startup token budget** CI | Duplicate prose detected | Delete copies; leave pointer | SoT Matrix wins |
| Handoff blobs | `SOP_MATURITY` 進行中區 | Steward | Stale date > 7d → CI warn / >14d fail | Any dated handoff | Empty section (already required by Design Review) | INDEX/Entry > frozen handoff |

---

## 3. Automatic verification fabric

Reuse; do not invent a second control plane.

```
PR / schedule
   ├─ control-plane-enforce.yml  → control-plane-lint.mjs
   ├─ docs-integrity.yml         → docs-integrity-check.mjs
   ├─ branch-hygiene.yml         → dry-run report (apply is Steward)
   ├─ (planned) repo-health-report.mjs → Job Summary KPIs
   └─ (planned) startup-context-budget.mjs → fail if listed bundle > budget
```

| Check | Already exists? | Self-governing job |
|---|---|---|
| Deploy authority / INDEX I2 / MemPalace freeze | Yes | Keep as merge gate |
| Required docs present + links | Yes (partial) | Extend allowlist only when Entry changes — not ad hoc |
| Branch age report | Yes (weekly) | Steward acts from summary; Founder not pinged |
| Startup token budget | **No (planned)** | One metric file list owned by Steward |
| Contaminated PR (>N files / junk paths) | **No (planned)** | Hard fail; recreate PR |
| Handoff staleness | Partial (9 files frontmatter) | Include SOP handoff section / Entry size |
| Issue stale | Blocked without API scope | One-time Arbiter grants `issues:read` to bot |

**Rule:** A governance requirement without a **Verifier row** is illegal and must be retired or automated within 30 days of being noticed.

---

## 4. Expiry & retirement protocol

### 4.1 States for any governance text

`active` → `deprecated` (banner + pointer) → `archived` (under `docs/archive/`) → `deleted` (only if zero inbound links in integrity lint)

### 4.2 Forced expiry triggers (no Founder ticket)

| Trigger | Action owner | Action |
|---|---|---|
| `last_reviewed` > 2× cycle | Verifier fails PR that touches sibling domain until Steward refreshes or demotes | |
| Handoff dated > 14d still non-empty | Verifier fails docs job | Steward empties section |
| Always-on bundle > budget | Verifier fails | Steward deletes duplication |
| Branch > 14d no open PR | Hygiene report → Steward delete after archive tag | |
| Open issue > 90d idle | Bot proposes archive; auto-archive if labeled `auto-archive-ok` | |
| ADR Accepted later contradicted by code | New ADR or fix code; old → superseded | |

### 4.3 Retirement checklist (Steward PR)

1. Demote banner or move to `archive/`  
2. Remove from Entry / portal tables  
3. Integrity lint green (no dangling links)  
4. If it was a SoT: name the **successor** in the same PR  
5. CHANGELOG one-liner only if operators/users notice  

---

## 5. Conflict handling (no Founder by default)

```
Detect (lint, agent, human)
  → Is it runtime/deploy/auth/P0?
        YES → CONTROL_PLANE_CONTRACT / P0 wins immediately; open K-row if docs disagree
        NO  → Does SoT Matrix name a winner?
                YES → Steward PR demotes loser (REFERENCE ONLY / archive)
                NO  → New domain? → Arbiter ticket (only irreducible Founder path)
```

**Parallel edits:** path CODEOWNERS + “one SoT file per domain” reduces merge wars; generated facts (runners, routes) preferred over prose.

**Agent disagreement:** Consumers must not “vote.” They open a Steward PR that cites verifier output.

---

## 6. Exit Criteria — when governance is “done”

Governance is **complete enough to run without Founder babysitting** when **all** Phase checks pass for **30 consecutive days**:

### Phase A — Machine backbone (must)

| # | Criterion | Evidence |
|---|---|---|
| A1 | Control-plane + docs-integrity remain required on `main` | Branch protection |
| A2 | Startup token budget job exists and is green at ≤15k obedient bundle / ≤4k always-on | CI artifact |
| A3 | Contaminated-PR gate active (e.g. files>300 or junk paths → fail) | Workflow |
| A4 | Branch hygiene produces actionable list weekly; no Founder pings required | Actions Summary |
| A5 | Contradiction registry has **zero Unresolved** K-rows older than 14d | Lint + registry |

### Phase B — Knowledge load (must)

| # | Criterion | Evidence |
|---|---|---|
| B1 | Single AI Entry ≤1k tokens; AGENTS/CLAUDE/INDEX do not restate encyclopedias | Token job + link graph |
| B2 | SOP / maturity handoff section empty **or** dated ≤7d | Lint |
| B3 | No second mega-index / SYSTEM_INDEX | Path absence check |
| B4 | Accepted ADR count stable; only Nygard-class additions | adr/README |

### Phase C — People scale ready (must before 10×30)

| # | Criterion | Evidence |
|---|---|---|
| C1 | Steward role documented + at least one non-Founder (or bot) performed a retirement PR | PR link |
| C2 | Arbiter queue < 2 open tickets | Issues filter |
| C3 | Founder governance time logged ≤1h in the measurement month | Self-declare / calendar |
| C4 | Issue lifecycle + archive automation enabled (`issues:read` granted) | Bot runs |

### Explicit non-criteria (do **not** block “done”)

- Pretty dashboards, Backstage, Docsy site, Merge Queue, staging env, 100% Diátaxis rename, zero open tech debt.

When A+B+C hold 30 days → mark this POLICY status **Binding / Graduated**. Founder moves to Arbiter-only.

---

## 7. Operating cadence (minimal forever)

| Cadence | Who | What | Founder? |
|---|---|---|---|
| Every PR | Author + Verifier | SoT update + lints | No |
| Weekly | Verifier + Steward | Health report, hygiene, archive proposals | No |
| Monthly | Steward | Token budget trend; retire Done TD / archive plans | No |
| Quarterly | Arbiter | At most one Principles/ADR touch; review Exit Criteria | **Yes (≤1h)** |
| On incident | Per control plane | Not this POLICY | As I3 |

---

## 8. Scaling note (10 humans × 30 agents)

What this mechanism does **without** Founder:

- Stops dual SoT accumulation (verifier + retirement)  
- Caps context tax (budget job)  
- Clears branch/issue archaeology (TTL + archive)  
- Routes lasting decisions to ADR/docs, not chat  

What still needs a human Arbiter forever (irreducible):

- Creating a **new** SoT domain  
- Changing P0 / payment business rules  
- Accepting/superseding ADRs that change money, auth, or deploy authority  

That irreducible set is the success condition: Founder is a **constitutional court**, not a janitor.

---

## 9. Implementation gate (intentionally thin)

Do **not** expand agent rule text to implement this. Preferred order:

1. Wire token-budget + contaminated-PR checks into **existing** CI (scripts, not new RULE md).  
2. Grant bot `issues:read` (Arbiter once).  
3. Add Steward runbook **one page** only if weekly Actions Summary is insufficient.  
4. Refuse new always-applied mdc unless it replaces ≥2× its token cost elsewhere.

If a proposed “governance improvement” cannot name its Verifier + TTL + Retire path, **reject it** under Principles §10.
