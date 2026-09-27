---
audit_date: 2026-09-17
evidence_cut: "origin/main @ a454475a"
scope: AllTrue Nine-Domain Engineering Audit
mode: READ-ONLY assessment
prior_control_plane_audit: "Cursor agent transcript df5dff04-c10c-4115-ad7b-f2b85cac79be (Control Plane Architect deep dive, 2026-09-17); portfolio-ops worktree tasks/portfolio-ops/control-plane-audit-v1"
status: complete
---

# AllTrue Nine-Domain Engineering Audit

**Scope:** AllTrue product engineering system (Laravel + Vue tutoring OS), GitHub delivery, Pi production, WSL agent platform.  
**Constraint:** Read-only. Prior control-plane report treated as a deep dive, not this scorecard.  
**Evidence cut:** `origin/main` @ `a454475a` (2026-09-17), local task trees, GitHub Actions/Environments, prior audit [df5dff04](df5dff04-c10c-4115-ad7b-f2b85cac79be).

---

## 1. Executive verdict

AllTrue’s **delivery and production fences are stronger than its product architecture health**. Exact-SHA deploy, auto-rollback, campus auth middleware, high-risk test gates, and deep regression memory are real. The operating model is still limited by **(a)** Founder-as-activation-middleware (8+ Deploy runs `waiting` on `production-activation`), **(b)** schedule/billing **dual-truth / god-file** risk that recreates production data incidents, and **(c)** agent-platform sprawl (268 worktrees ≈ 41 GB) plus governance/harness work that outpaces product ROI.

You are **not** under-invested in Governance. You are **over-invested** there relative to Architecture (TD-076 / F7) and Agent Productivity (GC + less context tax). Highest 30-day ROI is **not** Restate or Supervisor tick — it is **clearing the deploy approval queue policy**, **F7 payment-status single source**, and **worktree/session GC**.

---

## 2. Nine-domain scorecard

| Domain | Current | 3–6m Target | Confidence | Strongest capability | Biggest material gap | Recommendation |
|--------|---------|-------------|------------|----------------------|----------------------|----------------|
| 1. Product & Execution Engineering | 2 | 3 | High | Recurring-defect memory + high-risk test gate + in-app close-loop | God-file changes + F1/F7 point-fixes still recur | IMPROVE |
| 2. Architecture Engineering | 2 | 3 | High | Explicit north-star campaign (TD-076) + growing Services layer (~70) | 8k-LOC controllers/pages; schedule chain + payment dual truth | IMPROVE |
| 3. Platform & Infrastructure Engineering | 2 | 3 | High | `agent-start` worktree model; Daan staging V1 just landed | 41 GB worktree sprawl; Laravel 8 EOL; staging smoke incomplete | IMPROVE |
| 4. Delivery & Release Engineering | 3 | 3 | High | Exact-SHA deploy + health/smoke auto-rollback | `production-activation` Founder reviewer backlog | IMPROVE |
| 5. Production Reliability Engineering | 3 | 3 | Medium | Sixhour/nightly + GDrive backup; rollback runbook; reconcile jobs | PITR deferred; data-loss class incidents (e.g. LR/calendar restore) | KEEP |
| 6. Observability Engineering | 2 | 3 | Medium | Sentry FE+BE; `/api/v1/health`; deploy wait tooling | Traces often unset; agent still can’t auto-answer “what happened” | IMPROVE |
| 7. Security Engineering | 3 | 3 | High | Campus middleware; prod X-User-Id disabled; CodeQL/gitleaks/dependency review | Laravel 8 EOL + open CVE/upgrade track (#977 / PR #2833) | IMPROVE |
| 8. Governance Engineering | 4 | 3 | High | `autonomy_gate` + Control Plane I1–I5 + Phase A/C | Overbuilt relative to product risk; dual CP / JSON flood | SIMPLIFY |
| 9. Developer / Agent Productivity Engineering | 2 | 3 | High | Bounded task worktrees + provenance manifests | Sprawl, re-read tax, CI→worker paste, Founder interrupts | IMPROVE |

---

## 3. Domain findings

### 1. Product & Execution Engineering — Current 2 → Target 3 — IMPROVE

**Sufficient for scale?** Partially. Throughput of product PRs is high (grade promotion #3023/#3026/#3033, assessment label fix #3027, billing/receipt ops). Quality process memory is excellent; change safety in the largest modules is not.

| Claim | Class |
|-------|--------|
| `AI_REGRESSION_LESSONS.md` ~1448 lines, F1–F7 recurring families, 128 R-sections | OBSERVED |
| `high-risk-test-gate.yml` blocks merge when billing/RFID/migration touch lacks tests | OBSERVED |
| Recent product PR #3023: Service + migration + Feature tests (~696 add / 9 files) — healthy shape | OBSERVED |
| `StudentClassController` ~8616 LOC; `CourseManagement.vue` ~8723; `LearningRecordsPage.vue` ~8843 on `origin/main` | OBSERVED |
| Open in-app billing bugs still `status:needs-decision` (#2915/#2916, #1911 F7 family) | OBSERVED |
| TD-070: director UI smoke secrets missing → path never really executes | OBSERVED |

**Highest-ROI improvement:** Stop point-fixing F7/F1 in god files without a shared invariant + “revert fails” test; pick **one** payment-status authority and enforce it in CI.

---

### 2. Architecture Engineering — Current 2 → Target 3 — IMPROVE

**Monolith verdict:** **Large with unhealthy hotspots**, not “needs microservices.” Services extraction exists (~70 service files); domain truth still lives in mega-controllers and dual writers (schedules chain, ClassSession materialization, Paid vs Invoice).

| Claim | Class |
|-------|--------|
| North star: TD-076 occurrence identity; Phase 1+2 shipped flag-off; production execute gated | OBSERVED |
| TD-076 still Open after production ghost-box incidents | OBSERVED |
| Epic #957 ClassSession materialization still open / needs-decision | OBSERVED |
| F7: `Paid` vs invoice/payment dual truth documented with production reports | OBSERVED |
| 194 migrations; 74 controllers; 90 models (shared checkout; origin similar) | OBSERVED |
| Touch-and-extract policy in north star (extract only when touching god files for real bugs) | OBSERVED |

**Do not** microservice. **Do** finish one identity/truth boundary at a time (schedule occurrence **or** payment status), with unique constraints / single writer where proven.

**Highest-ROI improvement:** Continue TD-076 **or** F7 single payment-status — whichever currently burns more director trust (billing issues in open queue favor F7 for 30 days; TD-076 remains the architectural root for calendar ghosts).

---

### 3. Platform & Infrastructure Engineering — Current 2 → Target 3 — IMPROVE

**Split:** Developer platform is **ahead** of production infra maturity.

| Area | Evidence | Class |
|------|----------|--------|
| Dev platform | `agent-start`, manifests, `local-heavy-gate`, bare repo + task worktrees | OBSERVED |
| Sprawl | 268 alltrue task dirs; `du` ≈ **41 GB**; many `MERGED_PR` still present | OBSERVED |
| Prod runtime | Single Pi `/home/admin`; nginx document root = production | OBSERVED |
| Staging | Daan/`alltrue-stage` PRs #3030–#3036 merged same day; GUIDE still notes no authenticated smoke / prod not gated on staging | OBSERVED |
| Framework | `laravel/framework ^8.75` | OBSERVED |

**Highest-ROI improvement:** Automated worktree GC for merged tasks + finish staging smoke (not more bootstrap scripts).

---

### 4. Delivery & Release Engineering — Current 3 → Target 3 — IMPROVE

**Path:** code → tests → PR → CI → squash-merge → `deploy.yml` (deployable path detect) → migrate → health/smoke → auto-rollback → `version.json` / exact-SHA readback.

| Claim | Class |
|-------|--------|
| Deploy triggers on successful CI on `main`; deployable prefixes `backend/`, `frontend/`, `scripts/` | OBSERVED |
| Health fail → `git reset` + frontend rebuild + `migrate:rollback --step=1` | OBSERVED |
| Environment `production-activation` has **required_reviewers: Founder** | OBSERVED |
| Sample: **8 consecutive Deploy runs `status: waiting`** (2026-09-17) | OBSERVED |
| ~70 workflow files; CI sample mostly green on product merges | OBSERVED |

**Bottleneck:** Not CI green — **human Environment approval** and occasional non-deployable/docs noise. Failure mode: merged code sits unreleased while Founder is busy elsewhere.

**Highest-ROI improvement:** Risk-tiered activation (auto-approve R0/docs-only and already-smoked tips; keep Founder only for migrate/auth/billing/data repair). Do **not** weaken exact-SHA or rollback.

---

### 5. Production Reliability Engineering — Current 3 → Target 3 — KEEP

| Claim | Class |
|-------|--------|
| Sixhour + nightly + GDrive sync + monthly `backup-restore-test.yml` | OBSERVED |
| PITR/binlog explicitly deferred (TD-015 / #881) | OBSERVED |
| Nightly reconcile / void-stale-leave / verify-reproductions pattern | OBSERVED |
| INAPP-289 spawned many restore/verifier worktrees (calendar/LR data integrity class) | OBSERVED |
| Historical: Pi phpunit DROP DB accidents → hard ban in R2 | OBSERVED |

**SLOs:** Documented in `SRE_POLICY.md` but Phase-2 wiring incomplete — treat as **aspirational**, not enforced (no inventing SLOs).

**Highest-ROI improvement:** Keep backup/rollback; invest reliability effort in **preventing architecturally caused data corruption** (F1/TD-076/LR void paths), not binlog yet.

---

### 6. Observability Engineering — Current 2 → Target 3 — IMPROVE

**When something goes wrong, how fast can system/agent know?**  
**Minutes for crash visibility (Sentry + health); hours-to-Founder for causal chain** (which SHA, which migration, which writer path, which in-app bug). Agent still relies on chat paste / `EI_*` JSON / manual `gh` (prior audit: CI→wake missing).

| Claim | Class |
|-------|--------|
| Sentry Vue + Laravel config present; FE init in `main.js` | OBSERVED |
| `traces_sample_rate` defaults to `null` unless env set | OBSERVED |
| Issue #884 observability still open / needs-decision | OBSERVED |
| Deploy visibility: Actions + `wait-github-deploy`; many runs stuck waiting | OBSERVED |
| No OpenTelemetry; runbook says Sentry enough for single service | OBSERVED |

**Highest-ROI improvement:** Always-on **release/SHA tagging in Sentry** + one “incident packet” script (deploy run, SHA, health, last Sentry issues, related BugReport). Defer full metrics platform.

---

### 7. Security Engineering — Current 3 → Target 3 — IMPROVE

| Claim | Class |
|-------|--------|
| `AttachAuthUser`: X-User-Id only in `local`/`testing` | OBSERVED |
| `RequireCampus`, `RequireRole`, API key campus binding | OBSERVED |
| CodeQL, dependency-review, credential-fingerprint, gitleaks workflows | OBSERVED |
| Laravel 8 + #977 / draft PR #2833 Laravel 12 candidate | OBSERVED |
| ASVS / IAM docs exist; TD-061 dependency vulns open | OBSERVED |
| Multi-role staff capability PR #3016 open (flagged) | OBSERVED |

**Maturity:** Systematic controls for a solo Founder + agents; largest material risk is **EOL framework + dependency debt**, not missing middleware.

**Highest-ROI improvement:** Bounded Laravel upgrade track (already opened) over new policy docs.

---

### 8. Governance Engineering — Current 4 → Target 3 — SIMPLIFY

Reuse prior deep dive: autonomy_gate, exact-SHA, Phase A/C are **KEEP**. Harness H0–H4b merged but cutover unsealed; Supervisor still chat+JSON; Restate wake PoC only.

| Evaluation | Verdict |
|------------|---------|
| Correctly targeted? | **Yes** for production mutation / deploy / risk tiers |
| Redundant? | **Yes** — harness vs agent_graph; JSON projections vs sqlite; docs vs meta acceptance flags |
| Blocking delivery? | **Sometimes** — Environment approval + Founder OA queues |
| Easy to bypass? | Production fences hard; local sprawl / chat-as-SoT soft-bypass |
| Overengineered? | **Yes** relative to one-Founder + tutoring-OS scale |
| Perverse incentives? | Building harness slices feels like progress while F7/TD-076 still hurt users |

**Highest-ROI improvement:** Freeze new governance frameworks; seal or rollback cutover honesty; delete duplicate schedulers/research.

---

### 9. Developer / Agent Productivity Engineering — Current 2 → Target 3 — IMPROVE

| Claim | Class |
|-------|--------|
| First-read stack: AGENTS + INDEX + regression lessons (~2.2k lines combined minimum) | OBSERVED |
| 268 worktrees / ~41 GB; audit lists many MERGED_PR candidates | OBSERVED |
| `state/alltrue` flooded with DISPATCH_/EI_/FOUNDER_ JSON | OBSERVED |
| Prior audit: CI completion → Founder/Supervisor paste to wake worker | OBSERVED (prior) |
| Product delivery still works via agent-start + gh (grade promotion same day) | OBSERVED |

**Founder time waste estimate (INFERRED):** Environment approve clicks + `needs-decision` billing/architecture issues + supervising harness/meta contradictions ≫ time spent on pure product code review.

**Highest-ROI improvement:** GC merged worktrees + shrink prompt to GoalContract/ID refs + risk-tiered deploy approve.

---

## 4. Cross-domain bottlenecks

Re-ranked **without** inheriting the harness roadmap:

1. **Founder activation middleware** (Delivery) blocks autonomous release of already-merged work.  
2. **Domain dual-truth / god files** (Architecture → Product → Reliability) recreates user-visible and data incidents.  
3. **Agent sprawl + context tax** (Productivity) burns tokens and disk; slows every task.  
4. **Observability causal gap** slows incident closure; does not invent need for Restate.  
5. **Governance dual SoT / unsealed cutover** creates trust debt but is secondary to (1)–(3) for company ROI.  
6. Laravel 8 EOL (Security/Platform) is real but multi-week; don’t leapfrog (1)–(3).

Harness cutover / Restate wake / supervisor tick **do not dominate** company engineering this quarter unless (1) is fixed and product dual-truth is actively owned.

---

## 5. Governance overengineering check

### KEEP
- `autonomy_gate` / risk tiers / production SSH–artisan–phpunit bans  
- Exact-SHA deploy + Environment for **true** prod activation of migrate/auth/billing/data repair  
- Phase A/C in-app close-loop + reply idempotency patterns  
- High-risk test gate + Control Plane I1–I5 as production authority  
- `AI_REGRESSION_LESSONS` F-families (product governance, not harness)

### SIMPLIFY
- Concurrent harness + portfolio-ops `agent_graph` narratives (one live SoT story)  
- JSON `FOUNDER_INBOX` / `CURRENT_STATE` / `DISPATCH_*` as human memory (projection-only, prune)  
- Number of one-off `ops-*-diagnose` workflows; prefer reusable probe pattern  
- Docs that restate the same production rules in 4 places (point INDEX, don’t duplicate)

### REMOVE / STOP
- Enabling `agent_graph` scheduler beside harness  
- New Agent Meeting / multi-agent voting OS  
- Re-research Restate vs Temporal without new failure evidence  
- Treating CubeLV as UI/control plane (bot only)  
- Expanding governance classifiers instead of deleting stale worktrees

---

## 6. Highest-ROI company engineering roadmap

### P0 (max 3)
1. **Risk-tiered `production-activation`** — auto-approve non-migrate/docs-only/redeploy-same-SHA; Founder only for R2–R3 production risk. Drain current waiting queue.  
2. **F7 payment-status single source** — one function used by Course Management + Accounting + alerts; regression test that fails if dual truth returns.  
3. **Worktree/session GC** — delete/archive MERGED_PR task trees; enforce retention; reclaim ~tens of GB.

### P1 (max 5)
1. **TD-076 occurrence identity** next safe phase (dry-run → Founder GO) — calendar ghost root.  
2. **Daan staging authenticated smoke** (close TD-070 class gap on staging first).  
3. **Sentry release = git SHA** + one agent-readable incident packet.  
4. **Laravel upgrade track** continue (#977 / #2833) as bounded security work.  
5. **`HARNESS_CUTOVER_SEAL`** as honesty/ops hygiene (not company north star).

### P2 (max 5)
1. `RESTATE_ADOPTION_GATE_1` (non-prod CI→wake) after seal  
2. Thin BugReport/Issue → Task intake adapter  
3. ExternalAction journal for GH/BugReport effects  
4. Evidence criticality on merge/deploy/closure transitions  
5. PITR tabletop (still defer enablement)

### DEFER
- `SUPERVISOR_TICK_V1`, `STRUCTURED_REVIEW_V1`, `FOUNDER_PROJECTION_API_V1`, full Founder Console, EI automation enable, OpenTelemetry platform, binlog enable

### REJECT
- Microservices split  
- Second live scheduler  
- Generic Agent Meeting framework  
- Custom durable workflow engine replacing Restate wake path  
- Big-bang Vue/Laravel rewrite

---

## 7. Previous Control Plane roadmap reconciliation

| Prior slice | Disposition | Why |
|-------------|-------------|-----|
| `HARNESS_CUTOVER_SEAL` | **KEEP BUT DOWNGRADE** | Still needed for honesty (live v4 unsealed); not company P0 vs deploy queue / F7 |
| `RESTATE_ADOPTION_GATE_1` | **KEEP BUT DOWNGRADE** → after seal; company **P2** | Real autonomy win; subordinate to Founder activation + product truth |
| `SUPERVISOR_TICK_V1` | **DEFER** | Premature while chat Supervisor + JSON flood dominate; GC first |
| `EXTERNAL_ACTION_JOURNAL_V1` | **DEFER** | Phase-C skips already cover hottest path; journal after wake |
| `EVIDENCE_CRITICALITY_V1` | **DEFER** | Valuable; doesn’t beat dual-truth bugs for users |
| `INTAKE_WORKITEM_V1` | **KEEP BUT DOWNGRADE** | Useful bridge; don’t block on full WorkItem rename |
| `STRUCTURED_REVIEW_V1` | **DEFER** | Meeting PoC lessons only; no framework |
| `FOUNDER_PROJECTION_API_V1` | **DEFER** | After seal + wake; UI not the bottleneck |

---

## 8. Top 3 engineering investments

1. **Risk-tiered production activation** (Delivery) — returns Founder hours immediately; unblocks autonomous merge→runtime.  
2. **F7 single payment-status + tests** (Architecture/Product) — highest director-trust / recurring-bug ROI.  
3. **Worktree GC + prompt-by-reference** (Agent Productivity) — cuts disk, tokens, and session confusion.

---

## 9. Things we should stop doing

- Treating harness/control-plane roadmap as the company engineering roadmap  
- Opening new platform slices while 8+ deploys wait on Founder click  
- Leaving MERGED_PR worktrees forever  
- Re-auditing Restate/Temporal/CubeLV/Agent Meeting  
- Point-fixing billing/calendar in 8k-LOC files without family invariant + revert-fail test  
- Writing more governance docs that duplicate `CONTROL_PLANE_CONTRACT` / autonomy_gate  
- Enabling a second scheduler or building a Founder Console before wake+seal  

---

## 10. Evidence appendix

### Special analysis answers

| # | Question | Answer |
|---|----------|--------|
| 1 | Over-investing in Governance vs others? | **Yes.** Governance ~4 vs Architecture/Product/Productivity ~2. Keep prod fences; freeze net-new CP frameworks. |
| 2 | Domain limiting autonomous software delivery most? | **Developer / Agent Productivity** (no durable CI→worker wake; sprawl/context), amplified by **Delivery** Environment queue after merge. |
| 3 | Domain creating most Founder manual work? | **Delivery & Release** (required Environment reviewer + waiting runs) and **product `needs-decision`** issues (Governance/Product boundary). |
| 4 | Largest production risk? | **Architecture Engineering** — schedule/billing/LR dual writers and god files (INAPP-289-class restore work; F1/F7; TD-076). Pi single-host is secondary. |
| 5 | Highest 30-day ROI investment? | **Risk-tiered production-activation + drain waiting deploys**, then **F7 payment-status**, then **worktree GC**. |

### Key OBSERVED anchors
- Repo: `jerry200176-png/AllTrue_System`, `origin/main` `a454475a`  
- Controllers/pages LOC on origin; Services ~70; migrations ~194; workflows ~70  
- Deploy Environment `production-activation` required_reviewers; 8 runs `waiting`  
- Sentry present; traces env-nullable  
- Laravel `^8.75`; security workflows present; X-User-Id local-only  
- 268 worktrees / ~41 GB; many MERGED_PR  
- Prior CP audit slices and capability matrix: transcript `df5dff04-…` (not re-researched)

### UNOBSERVED (not scored as failure)
- Live Sentry issue volume / MTTR distribution  
- Exact Founder hours/week  
- Machine-reboot durability of harness (prior: UNPROVEN)  
- Production traffic for SLO calibration  

### NOT_APPLICABLE
- Multi-team review culture, microservice org topology, 24×7 SRE staffing  

---

**Bottom line:** Keep production control fences. Stop expanding the control plane as the main engineering story. For the next 30–90 days, spend scarce Founder and agent capacity on **release activation friction**, **billing/schedule truth**, and **agent workspace hygiene**.
