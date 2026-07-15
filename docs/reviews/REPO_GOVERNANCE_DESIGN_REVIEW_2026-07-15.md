# Repository Governance — Design Review (Round 2)

> **Date:** 2026-07-15  
> **Purpose:** Challenge Round‑1 conclusions with **external primary sources** + **local measurements**.  
> **Non‑goal:** Make GitHub look tidy.  
> **Priority order:** (1) lower AI cognitive load (2) delivery speed (3) long‑term scale — never process theater.  
> **Supersedes Round‑1 on disagreements** (see §9). Round‑1 report remains historical: `REPO_GOVERNANCE_REVIEW_2026-07-15.md`.

---

## Evidence discipline

Claims below cite either:

- Official docs / canonical sites (GitHub Docs, trunkbaseddevelopment.com, Nygard ADR, Kubernetes content guides, Cloudflare Style Guide, Diátaxis, Microsoft VS Code wiki), or  
- **Measured** local repo metrics (token heuristic: ASCII÷4 + CJK÷1.8; PR JSON from `gh`; branch ages from `git for-each-ref`).

Token numbers are **approximate tokenizer estimates**, not billed API meters — but they are computed from file bytes/line counts, not vibes.

---

## 1. Branch Strategy — pick exactly one

### 1.1 Primary sources

| Model | Source |
|---|---|
| **GitHub Flow** | [GitHub Docs — GitHub flow](https://docs.github.com/en/get-started/using-github/github-flow); Scott Chacon 2011 original [scottchacon.com](https://scottchacon.com/2011/08/31/github-flow/) — “Anything in the default branch is deployable”; branch → PR → merge → delete. |
| **GitLab Flow** | [GitLab branching strategies](https://docs.gitlab.com/user/project/repository/branches/strategies/); environment / release branches downstream of `main`. |
| **Trunk-Based Development (TBD)** | [trunkbaseddevelopment.com](https://trunkbaseddevelopment.com/) + [Short-Lived Feature Branches](https://trunkbaseddevelopment.com/short-lived-feature-branches/) — branches **≤ a couple of days**, ideally one developer (/pair); PR workflow is an allowed TBD style. |
| **Git Flow** | Vincent Driessen 2010 model — `develop` + `release` + `hotfix` long-lived branches (widely documented as release-train oriented). |

### 1.2 Comparison for AllTrue (Founder + AI Agents, merge→`deploy.yml`→Pi)

| Criterion | GitHub Flow | GitLab Flow | TBD (short-lived PR) | Git Flow |
|---|---|---|---|---|
| Fit team size | Strong | Weak (needs env branches; AllTrue has no staging env yet — issue #868) | **Strongest** — TBD text explicitly sizes short-lived PRs for teams up to ~15+ with CI gates | Weak — invents unused long-lived branches |
| AI cognitive load | Medium — culture of “delete after merge” but no hard TTL | High — agents must learn env merge directionality | **Lowest** — one trunk + hard TTL + “delete after merge” | Highest — 5 branch types to misuse |
| Automatable | High (protection + delete_on_merge — **already on**) | Medium (multi-branch deploy matrix) | **Highest** — same as GitHub Flow + stale-age bots | Low (release/hotfix rituals) |
| Long-term maintain | Good for single-prod SaaS | Better when many envs/versions | **Best** for continuous deploy + AI churn | Best for multi-version packaged software — **not** this product |

### 1.3 Overturn Round‑1 wording?

Round‑1 said “keep GitHub Flow.” That was **directionally right**, but incomplete.

**GitHub Flow ⊂ TBD-with-short-lived-PR.** For this repo, agents left **7 branches ≥14d** and XL contaminated PRs. Culture without TTL failed. TBD’s published rule (“couple of days”) is the missing constraint.

**Single retained strategy (do not mix):**

> **Trunk-Based Development using short-lived PR branches off `main`.**  
> No `develop`. No environment branches. No Git Flow release trains.  
> Branch lifetime target **≤ 48–72h**; merge deletes head (`delete_branch_on_merge` already `true`); orphans >7d are hygiene debt.

**Explicitly rejected (over-engineering for now):**

- Git Flow  
- GitLab Flow environment branches (until a real staging env exists *and* pays for itself — currently #868 open)  
- Pure “commit straight to trunk” TBD (unsafe with AI + auto-deploy to production Pi)

---

## 2. Documentation — INDEX cannot be the forever shape

### 2.1 Measurement: INDEX already past “thin router”

| Artifact | Lines | ≈Tokens | Evidence |
|---|---:|---:|---|
| `docs/INDEX.md` | 468 | **~7,137** | Measured 2026-07-15 |
| AI Entry Card section alone | — | ~168 | Already inside INDEX |
| Round‑1 “common first-read bundle” | 3,001 | **~47,195** | always-on + INDEX + AI_REGRESSION + SOP + TECH_DEBT |

### 2.2 When a single INDEX fails (evidence, not opinion)

| Scale signal | What happens | External parallel |
|---|---|---|
| **>~300–400 lines / ~5k tokens** | Agents start “partial-reading” inconsistently; stale facts hide in the middle (we already shipped false runner prose) | Nygard: “Nobody ever reads large documents… Large documents are never kept up to date.” ([Cognitect ADR essay](https://www.cognitect.com/blog/2011/11/15/documenting-architecture-decisions)) |
| Multiple product domains | One file becomes dual-sourced content | Kubernetes: “Wherever possible… link to canonical sources instead of hosting dual-sourced content… double the effort… grows stale more quickly.” ([Content Guide](https://kubernetes.io/docs/contribute/style/content-guide/)) |
| Multi-author / multi-agent | Conflicts on the same file thrash | Cloudflare: metadata + folder product ownership; GitHub is SoT, not a spreadsheet ([Style Guide — Metadata](https://developers.cloudflare.com/style-guide/how-we-docs/metadata/)) |

**Verdict:** AllTrue INDEX at **~7.1k tokens / 468 lines** is **already past** the healthy single-file router size. Round‑1 “keep INDEX, just slim it” under-specified the end state.

### 2.3 How large projects avoid mega-INDEX

| Org | Evidence of pattern | Implication for AllTrue |
|---|---|---|
| **Kubernetes** | Hugo directory tree + per-section `_index.md`; side menu generated; `toc_hide` ([Content organization](https://kubernetes.io/docs/contribute/style/content-organization/)); API refs **generated** from OpenAPI | **Portal = tree + section landings**, not one registry essay |
| **Cloudflare** | Diátaxis types + `pcx_content_type` frontmatter + product folders; description field for AI retrievability ([Metadata](https://developers.cloudflare.com/style-guide/how-we-docs/metadata/); Diátaxis cited in Cloudflare redesign quotes on [diataxis.fr](https://diataxis.fr/)) | **Type + product metadata** > giant TOC |
| **Stripe** | docs.stripe.com browsed by **product / task**, not one INDEX page ([docs.stripe.com](https://docs.stripe.com/)) | Category landings |
| **AllTrue today** | Diátaxis **prefixes** already exist (`RULE_`/`GUIDE_`/…) in INDEX naming section — but INDEX still duplicates catalogs | Lean into prefixes + **generated catalog**, stop hand-maintaining mega tables |

### 2.4 Overturn Round‑1

| Round‑1 | Round‑2 |
|---|---|
| “Do not create SYSTEM_INDEX; keep INDEX” | Still **do not** create a second mega-index. Instead: **thin single AI entry** (`docs/AI_ENTRY.md` or INDEX cut to ≤~80 lines / ≤~1k tokens) + **category portals** (`docs/portal/*.md` or folder `_index`) + optional **generated** file list from prefixes/frontmatter |
| “INDEX is Documentation Index” | INDEX-as-hand-maintained service catalog **does not scale**; evidence: false runner fact survived inside it |

**Do not build a full Hugo/Docsy site now** — that is over-engineering for Founder+AI. Steal the *information architecture*, not the publishing platform.

---

## 3. ADR — few, hard, with lifecycle

### 3.1 Primary definition (Nygard)

Architecturally significant = affects **structure, non-functional characteristics, dependencies, interfaces, or construction techniques**. Keep ADRs **small**; reverse by **superseding**, never silent edit. Status: proposed → accepted → deprecated/superseded. ([Cognitect](https://www.cognitect.com/blog/2011/11/15/documenting-architecture-decisions); [Martin Fowler bliki](https://martinfowler.com/bliki/ArchitectureDecisionRecord.html); MADR status enums).

### 3.2 Must be ADR (AllTrue shortlist)

| Decision class | Example |
|---|---|
| Auth / trust boundary | Sanctum bearer vs headers; parent token model |
| Write authority for money/sessions | e.g. `SessionDeductionService` minutes truth; ClassSession upsert authority (#957) |
| Multi-campus isolation contract | require_campus as mandatory |
| Deploy execution authority | Only `deploy.yml` (already control-plane) |
| Layering / DB access ban | ADR_003 (exists) |
| Dual-table coexistence strategy | `schedules` vs `StudentClass` long-term |

### 3.3 Must NOT be ADR

- Bug fixes, copy changes, one-off migrations without architectural import  
- Sprint plans / PRD acceptance criteria → plans or issues  
- UI spacing / design tokens → `RULE_DESIGN_SYSTEM`  
- “We prefer conventional commits” → CONTRIBUTING  
- Temporary experiment outcomes → plan or issue comment  

### 3.4 Lifecycle / template / index

| Need | Recommendation | Over-engineering? |
|---|---|---|
| Lifecycle | **Yes** — Proposed → Accepted → Superseded/Deprecated (Nygard/MADR) | No — tiny |
| Template | **Yes** — one `docs/adr/TEMPLATE.md` (Context/Decision/Status/Consequences) | No |
| ADR Index | **Yes** — `docs/adr/README.md` listing **Accepted only** + link to superseded | No |
| Mass-convert 293 plans → ADR | **No** | **Over-engineering** — only lift when decision still binds runtime |

**Target cadence:** ≤1 new Accepted ADR / quarter unless a T3 incident forces one. Prefer updating SoT docs over ADR spam.

---

## 4. Issue Governance — lifecycle > labels

### 4.1 Do issues need to be kept forever open? **No.**

| Artifact | Value when open | Value when closed | Evidence mindset |
|---|---|---|---|
| Bug with repro | High | **High** (regression map) — VS Code keeps issue history; PRs link issues ([vscode wiki](https://github.com/Microsoft/vscode/wiki/How-to-Contribute)) | Closed ≠ delete |
| Epic / roadmap | Medium | Medium as archive of intent | Prefer milestone close |
| Discussion / brainstorm | Low as Issue | Better as **Discussion** or Decision → ADR/Doc | GitHub Discussions exist for this shape |
| Audit dump (#957–#995 style) | Decays fast | Keep closed with labels; don’t re-handoff stale text | Round‑1 SOP handoff proved poison |

### 4.2 Routing rules

| Content | Destination |
|---|---|
| Architecturally significant choice | **ADR** (then close issue with link) |
| Recurring operator procedure | **RUNBOOK_** / OPERATIONS § |
| Business rule (payment/alerts) | **RULE_** doc (Founder gate) |
| One-off bug | Issue → PR → **close** |
| Support sentiment / how-to ask | Discussion or in-app macros — not eternal issue |
| Duplicate audit noise | Close as `duplicate` / `not_planned`; **archive** via label `status:archived` |

### 4.3 Issue Lifecycle (state machine)

```
inbox → triage → (needs-decision | ready | blocked)
ready → in-progress → in-review → done → closed
any → archived (terminal; no agent handoff)
needs-decision → (ready | not_planned/closed)
```

**Agent rules:**

1. Never treat `archived` / closed audit epics as “next step”.  
2. `needs-decision` is human-only.  
3. Open >90d without activity → propose archive (automation once `issues:read` exists).  
4. Labels are **dimensions** (`area`, `priority`, `type`), not a substitute for lifecycle.

**Over-engineering:** linear-like custom project automation with 20 statuses — skip until >3 humans.

---

## 5. Knowledge Governance — Source of Truth Matrix

Problem is not “too few docs”; it is **multi-writer truth**. Measured duplicate phrases across entry files: `AI_REGRESSION` / `CHANGELOG` / `TECH_DEBT` appear in **all 4** of `.cursorrules`, `AGENTS.md`, `CLAUDE.md`, `INDEX.md`.

| 類型 | 唯一真相 (SoT) | 備援 | 禁止引用來源 |
|---|---|---|---|
| **AI Entry** | `docs/AI_ENTRY.md` (target; until created: INDEX §Entry Card only) | `AGENTS.md` one-liner pointer | Full `.cursorrules`, entire INDEX, SOP_MATURITY handoff |
| **P0 safety / prod red lines** | `.cursor/rules/p0-gate.mdc` + `docs/DANGEROUS_OPERATIONS.md` | AI_REGRESSION R-series | Chat memory; Pi folklore |
| **Runtime decision/execution** | `docs/CONTROL_PLANE_CONTRACT.md` + `.github/workflows/deploy.yml` | CONTRADICTION_REGISTRY | INDEX prose; RUNBOOK as authority; MemPalace |
| **Business rules (payment/alerts)** | `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` | CHANGELOG user lines | PR comments; plans |
| **Architecture decisions** | `docs/adr/*` Accepted | SYSTEM_TECH_GUIDE (explanation) | Open issues; unmerged PR bodies; superseded ADR text as current |
| **API surface** | `backend/routes/api.php` + generated/maintained `docs/REF_API_ROUTES.md` | Feature tests | Frontend guess; outdated screenshots |
| **Database schema** | `backend/database/migrations/` | Model `$table` / factories | Hand-written schema essays without migration refs |
| **CI behavior** | `.github/workflows/*.yml` | OPERATIONS_RUNBOOK § (pointers only) | INDEX workflow essays claiming runners |
| **Deployment** | `deploy.yml` only | OPERATIONS / RUNBOOK_ROLLBACK (helper) | Manual SSH “SOP” as normal path |
| **Product spec / AC** | Approved PRD in `.cursor/plans/` **or** issue with Founder ack | CHANGELOG | Random agent TODO |
| **Roadmap** | GitHub milestones / epics (when issues readable) | `SOP_MATURITY` M-tables (historical) | Stale “進行中狀態” |
| **SOP / ops procedure** | `docs/OPERATIONS_RUNBOOK.md` + `RUNBOOK_*` | incident stack docs | INDEX |
| **AI regression lessons** | `docs/AI_REGRESSION_LESSONS.md` (module-index first) | archive | Re-stated copies inside `.cursorrules` |
| **Tech debt** | `docs/TECH_DEBT.md` | REVIEW notes | Silent TODO in code without TD-id |
| **Plans** | Non-authoritative scratch | — | **Never** cite as runtime SoT |
| **Commits / PR thread** | History / rationale crumb | — | Not SoT for rules; may spawn ADR/doc update |
| **MemPalace** | Recall index only | — | Production truth, incident authority |

**Binding rule:** If two SoT candidates conflict → CONTRADICTION_REGISTRY / control-plane lint; do not “average” them in chat.

---

## 6. AI Context Optimization — measured

### 6.1 Current startup (measured 2026-07-15)

| Bundle | Files | ≈Tokens | Notes |
|---|---:|---:|---|
| Always-applied rules only (7) | 7 | **~6,616** | `.cursorrules` + 6 mdc |
| Cloud session typical (+AGENTS+CLAUDE) | 9 | **~9,807** | Often all loaded |
| INDEX alone | 1 | **~7,137** | Already > target entry |
| AI_REGRESSION full | 1 | **~18,459** | Should be lazy/module |
| TECH_DEBT full | 1 | **~8,583** | Lazy |
| SOP_MATURITY | 1 | **~3,209** | Lazy / historical |
| **Common “obedient agent” bundle** | 13 | **~47,195** | What Round‑1 called first-read |
| All docs `*.md` | 132 | **~359,659** | Never load |
| All `.cursor/rules/*.mdc` | 19 | **~14,224** | On-demand most |

### 6.2 Duplication / lazy / generate / archive

| Class | Action | Est. save |
|---|---|---|
| P0 / deploy / write-back restated in 4 entry files | **Deduplicate** — entry points link, don’t copy | ~2–4k always-on |
| INDEX catalogs + MemPalace command dump | **Lazy / move to portal** | ~5–6k |
| AI_REGRESSION body | **Lazy** — module index only at start (~0.5–1k) | ~17k |
| TECH_DEBT / SOP_MATURITY | **Lazy** | ~12k |
| CHANGELOG archives / shadow control-plane | **Archive** (already mostly) | avoid accidental open |
| API route tables | **Generated** from `routes/api.php` (aspirational) | drift↓ |
| Workflow runner facts | **Generated** from yml grep in CI | prevent K11-class bugs |

### 6.3 Numeric targets (commit to these)

| Metric | Now (measured) | Target (90d) |
|---|---:|---:|
| Obedient-agent startup bundle | **~47k tok** | **≤15k tok** |
| Always-on injected | **~6.6–9.8k** | **≤4k** (P0 + pointer) |
| Mandatory entry surfaces | 4 (cursorrules/AGENTS/CLAUDE/INDEX) | **1** (`AI_ENTRY`) + pointers |
| Files an agent must open before coding a normal T1 task | ~8–13 | **≤3** (Entry → SoT → code) |
| INDEX (or successor entry) size | 468 lines / ~7.1k | **≤80 lines / ≤1k** |

---

## 7. Repository Health Dashboard (KPIs)

Baseline sample (2026-07-15):

| KPI | Baseline | Collectable? |
|---|---|---|
| Open PR count | **12** (incl. #1231) | `gh pr list` — **auto** |
| Avg PR churn (merged last 40) | **274** lines; median **127** | `gh` JSON — **auto** |
| Avg files / merged PR | **3.1** | **auto** |
| Avg hours create→merge | **1.4h** (small sample; skewed by bots) | **auto** |
| Open PR XL outliers | **2** (#1215/#1201, >1300 files) | **auto** + kill rule |
| Branch count / avg age | **28** / **6.7d** | git for-each-ref — **auto** |
| Branches >14d | **7** | **auto** |
| Stale issues >90d | unknown (token **403**) | needs `issues:read` |
| Docs drift | control-plane + docs-integrity | **already auto** |
| ADR coverage (Accepted ADRs / T3 modules) | **1** Accepted live | script over `docs/adr` — **auto** |
| AI startup context | **~47k** obedient bundle | CI job measuring listed files — **auto** |
| Duplicate documents | prefix + link graph | integrity script extend — **auto** |
| Knowledge drift | CONTRADICTION + runner digests | **auto** |
| Documentation coverage (critical paths have RULE/RUNBOOK) | qualitative | checklist — semi |

**Do not build a pretty Grafana board first** (over-engineering). Ship `scripts/repo-health-report.mjs` → Actions Job Summary weekly.

---

## 8. Scalability stress test (10 humans × 30 AI × 5k issues × 1k PRs × 1k docs)

| Subsystem | Survives? | First break | Fix **now** (cheap) |
|---|---|---|---|
| Single mega INDEX | **No** | Edit conflicts + stale facts | Thin entry + portals |
| Always-on 10k tokens | **No** | Context exhaustion / contradiction | Dedupe rules |
| Issue pile without lifecycle | **No** | Agents reopen archaeology | Lifecycle + archive |
| Contaminated mega-PRs | **No** | Review impossible | File-count CI gate |
| TBD without TTL | **No** | Branch sprawl (already 7×>14d) | Hygiene enforce |
| ADR explosion from plans | Self-inflicted | Noise | Strict Nygard bar |
| MemPalace as authority | Dangerous | Silent wrong recall | Keep frozen non-authority |
| Full Diátaxis static site | Premature | Maint cost | IA only |
| GitLab Flow / Merge Queue / staging | Premature until multi-human env pain | Ops cost | Keep tickets, don’t build yet |

---

## 9. Re-evaluation vs Round‑1 (what we overturn)

| Topic | Round‑1 | Round‑2 (final) |
|---|---|---|
| Branch | “GitHub Flow” | **TBD short-lived PR** (GitHub Flow mechanics + hard TTL); reject Git Flow/GitLab Flow |
| INDEX | Keep & slim | **INDEX-as-mega-registry fails**; move to **thin AI_ENTRY + category portal**; no second mega index |
| ADR | Expand ADR ladder broadly | **Few ADRs**, lifecycle+template+index; don’t convert plan farms |
| Issues | Triage/cleanup emphasis | **Lifecycle + archive**; closed bugs keep history; Discussions for non-work |
| Knowledge | Fix contradictions | **SoT Matrix + Principles** as primary control |
| Automation | Many nice-to-haves | Prefer **health report + contaminated PR gate**; defer Backstage/Merge Queue |
| P0 doc fact fixes (K11 etc.) | Do now | **Still valid** — keep |

---

## 10. Over-engineering blacklist (do **not** do now)

Even if Google/Stripe do variants of these:

1. Full Docsy/Hugo documentation portal site  
2. Backstage service catalog  
3. Git Flow / long-lived release branches  
4. GitLab-style environment branches before staging exists  
5. Mass ADR generation from 293 plans  
6. 20-column project board mimicking Linear  
7. Custom KPI Grafana before weekly text report works  
8. Second mega `SYSTEM_INDEX.md`  
9. Requiring human CODEOWNER reviews for every docs PR when founder is solo  
10. Treating MemPalace as mandatory runtime memory for agents  

---

## 11. Recommended next deltas (only if Founder approves direction)

**P0 (high ROI, low process weight)**

1. Adopt **AI Governance Principles** (companion doc).  
2. Create thin `docs/AI_ENTRY.md` (≤1k tokens) and demote INDEX to portal/registry gradually.  
3. Contaminated PR / file-count gate.  
4. Enforce TBD TTL via hygiene (report→delete policy).  

**P1**

5. `docs/adr/TEMPLATE.md` + README index; lift 2–4 binding decisions only.  
6. Issue lifecycle labels + archive automation (needs issues permission).  
7. Weekly `repo-health-report`.  

**Defer**

8. Staging/GitLab Flow, Merge Queue, handbook trilogy, static docs site.

---

## Appendix — command echoes used for measurements

```bash
# token heuristic run embedded in design-review session 2026-07-15
gh pr list --state merged --limit 40 --json additions,deletions,changedFiles,createdAt,mergedAt
git for-each-ref --format='%(committerdate:iso8601)|%(refname:short)' refs/remotes/origin
rg -n 'runs-on:' .github/workflows/{ci,deploy,presubmit,codeql}.yml
```
