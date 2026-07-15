# Repository Governance Review — 2026-07-15

> **Purpose:** Reduce AI cognitive load, raise engineering throughput, keep knowledge consistent.  
> **Not purpose:** Make GitHub look tidy.  
> **Evidence cut:** local `origin/*` + `gh` PR/repo APIs. **Issues contents are NOT readable** by this agent token (`issues=read` 403); open issue *count* comes from `open_issues_count`.  
> **Companion canvas:** IDE canvas `repository-governance-review.canvas.tsx` (visual scorecard).

---

## Executive summary

AllTrue already has above-peer **automation** and a rare **control-plane contract** (I1–I5 + contradiction registry). The dominant risk is **Knowledge Debt**: agents load ~16k always-on tokens, then hit contradicted facts (especially CI/deploy runners) and oversized “summaries.” Secondary risk: **two contaminated XL PRs** (#1215/#1201) that are not safely reviewable.

**Overall maturity: B-** (automation A-, AI friendliness C+, onboarding C).

---

## 1. Repository health

### 1.1 Largest maintenance costs (ranked)

| Rank | Cost | Evidence |
|---|---|---|
| 1 | Authority / fact drift in AI entry docs | `docs/INDEX.md` still says `ci/presubmit/codeql/deploy` use WSL2 self-hosted; actual workflows all `runs-on: ubuntu-latest` (post #1220). `OPERATIONS_RUNBOOK.md` §13 still says deploy must stay GitHub-hosted (matches yml). |
| 2 | First-read context tax | Always-applied rules ≈47KB (~16k tokens) before task reads. Common bundle INDEX+AI_REGRESSION+SOP_MATURITY+TECH_DEBT+cursorrules ≈2,359 lines / ~30–45k tokens. |
| 3 | Contaminated / diverged agent PRs | #1215/#1201: +~200k / 1372–1373 files; tip `1339 ahead / 1324 behind` main; includes `.vnc/passwd`, `.face`, `.xsession-errors`. |
| 4 | Decision residue outside ADRs | 293 `.cursor/plans/**` files; only 1 live ADR (`ADR_003`); calendar/billing decisions in guides. |
| 5 | Stale handoff surfaces | `SOP_MATURITY.md` “進行中狀態” last updated 2026-06-27; links `docs/reviews/ENGINEERING_AUDIT_2026-06-27.md` which lives only under `docs/archive/control-plane-shadow-v1/reviews/`. |

### 1.2 Where AI agents waste time

1. Re-reconciling `.cursorrules` / `AGENTS.md` / `CLAUDE.md` / `INDEX.md` (same deploy + write-back story repeated).  
2. Full-reading `AI_REGRESSION_LESSONS.md` (918 lines) because entry text still claims “127 行摘要”.  
3. Following stale SOP handoff (#937/#938/#970 steps) that are 18+ days old.  
4. Reviewing contaminated XL PRs until diff API gives HTTP 406 (>300 files).  
5. Searching issues they cannot list (token missing `issues` scope) after docs promise issue-driven workflow.

### 1.3 Knowledge debt

| Debt | Type | Impact |
|---|---|---|
| Runner authority (INDEX vs yml vs RUNBOOK) | Fact drift | Wrong FinOps / deploy secrets advice |
| AI_REGRESSION size claim | False summary | Over/under-reading |
| SOP handoff not cleared | Process drift | Agents reopen finished work |
| Broken audit link | Dead navigation | Failed mandatory handoff step |
| Dual payment truth + many pointers | Domain complexity | Not debt itself, but multi-doc restatement taxes tokens |
| `DOCS_GOVERNANCE_SOP.md` stub-only | Hollow required file | Integrity requires it; content moved to INDEX |

### 1.4 New-agent time-to-competence (estimate)

| Milestone | Optimistic | Typical blocker |
|---|---|---|
| Safe “don’t brick prod” | 10–20 min | Always-on P0 enough |
| Find correct task doc | 20–40 min | INDEX good IF facts fresh |
| Trustworthy high-risk change | 1–2 focused sessions | Must sample AI_REGRESSION module index + gotchas |
| Full mental model | Days | Ops/billing dualities + 131 docs |

**Biggest onboarding blocker:** conflicting authoritative facts, not missing docs.

---

## 2. Issue governance

**Limit:** cannot enumerate issue bodies/labels via API (`Resource not accessible by integration`).  
**Proxy evidence:** `open_issues_count=71`, open PRs=11 → **≈60 open issues**. Search API only returns the 11 PRs for this token.

### Observed structure (from docs, not live issue API)

- Templates: bug / engineering change / ops (`ISSUE_TEMPLATE/*`) — good.  
- Labels: **44** — mix of `priority:p0–p3`, leftover `high-priority`, `bug` vs `area:*`, `status:*`, `type:epic`.  
- Historical mass create: Engineering Audit 2026-06-27 opened **#957–#995** (39) plus M4–M9 roadmap issues (#868–#908) still referenced from `SOP_MATURITY.md`.  
- Epic candidates (docs-backed): ClassSession materialization (#957), M4 staging/flags (#868–#873), GitHub hygiene (#875–#880).

### Recommendations

1. **Founder:** grant automation token `issues:read` (and optionally write for stale bot).  
2. Human or privileged agent: quarterly close/merge sweep of audit-born issues; convert clusters → Epic + child refs.  
3. Label trim: delete or alias `high-priority` → `priority:p1`; keep `area:*` + `priority:*` + `status:*` + `type:*`.  
4. Discussion→Docs rule: any thread that changes deploy/auth/billing/calendar authority must land ADR or CONTRADICTION row within 7 days.

---

## 3. Pull request governance

| Check | Evidence | Verdict |
|---|---|---|
| Oversized PRs | #1215/#1201 XL + junk paths | Fail hard — close & recreate |
| Issue linkage | 4/11 open PRs have no issue ref | Medium gap |
| Docs sync after merge | 27/40 recent merged PR bodies lack CHANGELOG/AI_REGRESSION/TECH_DEBT mention | Process leak (body ≠ truth, but weak signal) |
| Template quality | Strong Threat/Design/Migration; weak size + docs matrix + type list incomplete | Improve |
| Auto-delete merged branches | `delete_branch_on_merge: true` | Good |
| Meta-merge PR | #1227 merges other open PRs | Anti-pattern for reviewability |

### PR template gaps to fix (low risk)

- Add `docs` / `refactor` / `test` / `ci` types.  
- Require PR size class + “why not split?” when >400 lines or >20 files.  
- Docs sync matrix: CHANGELOG / AI_REGRESSION / TECH_DEBT / INDEX / N/A.  
- Contaminated-diff kill criteria pointer (home-dir junk, `public/assets` mass hash churn, >300 files).

---

## 4. Branch strategy

### Inventory (2026-07-15)

- 28 remotes; stale>60d = **0** (problem is abandoned-but-recent, not ancient).  
- Patterns: `cubelv-cli-*`×12, `fix/`×6, plus feat/hotfix/refactor/wip/revert/test.  
- Orphans (no open PR): 15 including Jun-27 classsession epic chain + `wip/` + `test-push-verify`.  
- `branch-hygiene.yml`: dry-run only; cron weekly Sunday — INDEX incorrectly says 週一至五.  
- Policy docs allow 1–3 day lifetime; many orphans ~18 days.

### Fit for AllTrue (vs large-company practice)

Keep **GitHub Flow** (Stripe/GitHub small-team default): short-lived branches → PR → CI → merge → auto-delete.

Do **not** adopt GitFlow or long release branches (overkill for single Pi prod + auto-deploy).

**Adopt:**

| Rule | Why |
|---|---|
| Naming: `feat|fix|hotfix|chore|refactor|docs|ci|test|td-batchN|dependabot|cubelv-cli|cursor` | Already mostly enforced in presubmit |
| Ban `wip/` on remote (or require open Draft PR) | Orphans without review surface |
| Contaminated branch: close PR, delete branch, recreate from `main` | Safety |
| Orphan TTL: 14 days no PR → hygiene report; 21 days → archive tag + delete (Founder ack) | Matches Runbook intent |
| Keep delete-on-merge | Already on |
| No auto-delete of unmerged | Keep dry-run + human `--apply` | Accidental loss |

---

## 5. Documentation

### SSOT map (as-is)

| Topic | Intended SSOT | Contenders |
|---|---|---|
| Runtime decision/execution | `CONTROL_PLANE_CONTRACT.md` | Demoted runbooks (good) |
| Navigation | `docs/INDEX.md` | Still repeats ops facts |
| Payment alerts | `DIRECTOR_PAYMENT_ALERT_RULES.md` | INDEX/cursorrules pointers |
| Calendar week merge | `calendarOccurrenceMerge` + G-007 | GUIDE_SMARTCALENDAR + AI_REGRESSION |
| Deploy execution | `deploy.yml` | INDEX prose (stale), DEPLOYMENT.md (reference) |

### Merge / archive / split

| Action | Targets |
|---|---|
| Keep | INDEX (slim), CONTRADICTION_REGISTRY, CONTROL_PLANE, AI_REGRESSION (but module-first), TECH_DEBT, CHANGELOG |
| Fix in place | INDEX runner + hygiene cadence; SOP_MATURITY handoff; cursorrules size claim |
| Archive-ready | `MEMPALACE_GAP_ANALYSIS.md` if superseded by handbook; stale product gap reviews after next cycle |
| Do **not** create | Second `SYSTEM_INDEX.md` / `PROJECT_INDEX.md` — consolidate INDEX |
| Split later (P1) | AI_REGRESSION active summary ≤200 lines + archive (partially done but claim wrong) |

`docs/INDEX.md` already exists as Documentation Index — problem is weight and freshness, not absence.

---

## 6. Knowledge governance

| Need | Status | Recommendation |
|---|---|---|
| ADR | Only ADR_003 live; ADR-001 archived | Enforce ADR for T3 decisions; lift #957 upsertSlot write authority |
| Decision Log | Scattered in PRD Decision Log + plans | Add `docs/DECISION_LOG.md` slim index linking ADRs + closed RFCs |
| Engineering Handbook | Distributed across cursorrules/AGENTS/INDEX | Keep distributed + INDEX router; optional later handbook if INDEX stays >500 lines |
| Product Handbook | ROLE_PLAYBOOK + payment rules | Sufficient |
| AI Handbook | AGENTS + skills | Slim “AI Entry Card” inside INDEX (30s) instead of new file unless >1 screen |

---

## 7. Automation

| Work | Exists? | Gap |
|---|---|---|
| Docs link + naming + partial staleness | `docs-integrity.yml` | SOP_MATURITY not in STALE_CHECK; link check whitelist small |
| Control-plane invariants | `control-plane-enforce.yml` | Strong |
| Branch hygiene | weekly dry-run | Not daily as INDEX claims; no auto-delete unmerged |
| Stale issues | No | Needs issues permission + policy |
| Stale PR review nudge | No | Nice-to-have via Actions + ISSUE_COMMENT |
| Contaminated PR detector | No | Script: fail if PR touches home junk paths / exceeds file budget |
| AI-owned forever | Integrity lint, control-plane lint, branch report, changelog reminder | Yes |

---

## 8. AI project governance (highest priority)

### Always re-read today

Always-applied: `.cursorrules`, `AGENTS.md` (cloud), `alltrue-system`, `p0-gate`, long-running, frontend-deploy, tech-debt, user-facing — **~16k tokens**.

### Contradictions that fork AI conclusions

1. Deploy/CI runner location (INDEX vs yml/RUNBOOK).  
2. Whether `.cursorrules` must be re-Read (`INDEX` says no; `CLAUDE` says yes).  
3. AI_REGRESSION “short summary” myth.  
4. SOP handoff still listing stop-the-line #970 as next step without status refresh.

### Best start order (mandate)

1. Trust always-on P0 only.  
2. Skim INDEX **task tables** (not MemPalace command dump unless needed).  
3. Open AI_REGRESSION **module index** → relevant §§ only.  
4. Task RULE/RUNBOOK/ADR/code.  
5. Write-back CHANGELOG / AI_REGRESSION / TECH_DEBT as applicable.

### Context reduction levers

| Lever | Est. save |
|---|---|
| Fix false facts (stop re-investigation) | 5–15 min/agent + error avoidance |
| Correct size claims + module-first TOC | 10–25k tokens/session when agents stop full-reading |
| Clear SOP handoff | Prevents wasted epic restarts |
| Contaminated PR kill | Hours of false review |
| Deduplicate entry prose over time | 3–8k always-on tokens (P1) |

**Do not add SYSTEM_INDEX.** Slim the existing INDEX.

---

## 9. Maturity grades

| Dimension | Grade | One-line basis |
|---|---|---|
| Repository Organization | B | Clean layout; unprefixed docs debt |
| Branch Strategy | B- | Good Flow; orphans + naming leaks |
| Issue Management | C | Templates yes; volume + no agent visibility |
| Pull Request Workflow | C+ | Template strong; XL contamination + weak docs mention |
| Documentation | B- | Contract+registry excellent; factual drift |
| Knowledge Governance | C+ | K1–K10 done; ADR ladder thin |
| Automation | A- | Broad workflow mesh |
| AI Friendliness | C+ | Intent excellent; entry tax + contradictions |
| Maintainability | B- | P0 hard rules work; soft doc fail mode |
| Onboarding Experience | C | Multi-entry reconcile + stale handoff |
| Technical Debt | B | Registry real; 11 Open |
| Engineering Governance | B | Risk tiers + CODEOWNERS; founder bottleneck |

---

## 10. Roadmap (executable)

### P0 — do now

| Item | Problem | Proposal | Benefit | Risk | Founder? | AI-autonomous? |
|---|---|---|---|---|---|---|
| K11 runner authority | INDEX false | Align INDEX to `ubuntu-latest`; note RUNBOOK+yml | Stops wrong deploy advice | Low | No | **Yes (this PR)** |
| Handoff clear | SOP stale + broken link | Clear 進行中; point archive audit | Onboarding trust | Low | No | **Yes** |
| Size claim | 127≠918 | Fix cursorrules wording | Correct read strategy | Low | No | **Yes** |
| Contaminated PRs | #1215/#1201 | Close, delete branches, reopen minimal | Safety / reviewability | Medium close politics | **Yes** | No (needs ack) |
| Hygiene cadence truth | INDEX≠cron | Fix INDEX row | Less confusion | Low | No | **Yes** |

### P1

| Item | Founder? | AI? |
|---|---|---|
| Orphan branch archive wave (15) | Soft yes | Partial |
| PR template size+docs matrix | No | Yes (this PR baseline) |
| INDEX 30-second AI card | No | Yes |
| AI_REGRESSION module-first trim claim paths | No | Yes (careful) |
| Grant issues:read to agents | **Yes** | No |
| Contaminated-PR CI guard | Soft yes | Yes |

### P2

Label taxonomy, stale-issue bot, ADR ladder for plans, Decision Log index, widen docs-integrity link set, expand STALE_CHECK (incl. SOP_MATURITY).

### P3

Optional handbooks only if INDEX cannot stay navigable; Merge Queue / Environments already ticketed (#871/#875).

### Impact claims (order-of-magnitude)

| Change | AI context | Tokens | Maintainer cost | Cognitive load |
|---|---|---|---|---|
| P0 fact fixes | − rework loops | − confusion tokens | − false FinOps threads | High |
| Contaminated PR policy | − hours | − huge false diffs | − review queue | High |
| Entry slim (P1) | −3–8k always-on | −10–25k/task | − duplicate edits | High |
| Issue triage (P1/P2) | − duplicate work | medium | − board noise | Medium |

---

## Appendix A — Founder decision checklist

1. Approve closing #1215 & #1201 as contaminated and require recreation from `main`?  
2. Grant CI/cloud tokens `issues:read` (and stale-bot write)?  
3. Authorize orphan branch delete after archive tags for Jun-27 classsession chain?  
4. Keep single INDEX (recommended) vs add SYSTEM_INDEX (not recommended)?  
5. OK to enforce ADR for all T3 decisions going forward (#896)?  
6. Label breaking change: remove `high-priority`?

## Appendix B — Evidence commands

```bash
gh api repos/jerry200176-png/AllTrue_System -q '{open_issues_count,delete_branch_on_merge}'
gh pr list --state open --json number,additions,changedFiles,headRefName
rg -n 'runs-on:' .github/workflows/{ci,deploy,presubmit,codeql}.yml
git rev-list --left-right --count origin/main...origin/cubelv-cli-p2-design-fixes
```
