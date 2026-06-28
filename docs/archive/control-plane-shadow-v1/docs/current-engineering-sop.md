> **HISTORICAL CONTEXT ONLY**
> This document does **not** override [`CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md) (I1–I5).
> **Decision:** INCIDENT stack only (I3). **Execution:** [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml) only (I1).
> Do not use this file for runtime operations.


# Current Engineering SOP — Consolidated Extract

> **Status**: Extracted from repository as of 2026-06-27  
> **Purpose**: Single structured view of **existing** engineering SOP already embedded across docs, scripts, hooks, and CI.  
> **Principle**: *Observe, do not invent. Extract, do not design.*

This document **does not add policy**. Where the repo is silent, sections say **NOT DEFINED IN CURRENT SYSTEM**.

**Authoritative sources** (for detail beyond this extract):

| Topic | Primary source |
|-------|----------------|
| Governance overview | `docs/engineering-system.md` |
| Release classification & deploy | `docs/release-flow.md` |
| Production truth & drift | `docs/production-truth-model.md` |
| Execution enforcement | `docs/execution-layer.md` |
| Decision intelligence | `docs/decision-intelligence-layer.md` |
| AI permissions | `docs/ai-agent-policy.md` |
| Rollback | `docs/RUNBOOK_ROLLBACK.md` |
| Release freeze | `docs/SRE_POLICY.md` |
| P0 red lines | `.cursorrules`, `.cursor/rules/p0-gate.mdc` |
| Agent workflow | `AGENTS.md` |

---

## 1. Daily Development SOP

### 1.1 Environment & where work happens

| Environment | Location | Who writes |
|-------------|----------|------------|
| Dev | WSL2 `~/alltrue` | Engineers + AI agents |
| Staging | **NOT DEFINED IN CURRENT SYSTEM** (planned #868) | — |
| Production | Pi `/home/admin` | **CEO-controlled deploy only** |

All code changes happen in WSL2. **Agents must not SSH to Pi to edit application code** (`.cursorrules` P0, `ai-agent-policy.md` A4).

---

### 1.2 Branching rules

| Branch | Lifetime | Who merges |
|--------|----------|------------|
| `main` | Permanent | **CEO only** |
| `feat/*`, `fix/*`, `chore/*`, `hotfix/*` | Short | Via PR → CEO |
| `dependabot/*` | PR-scoped | CEO |

**Rules (from `engineering-system.md`, `release-flow.md`, hooks):**

- ⛔ **No direct push to `main`** (including agents). Enforced by `scripts/install-git-hooks.sh` pre-push hook and `pre-commit-exec-guard.sh`.
- Branch from latest `origin/main`.
- **One PR per bounded context**; prefer ≤400 lines (`engineering-system.md`, `module-industry-standards.mdc`).
- Docs-only batches: `chore/docs-*` with **Release-Class: SAFE** (`engineering-system.md`).
- Multi-agent: one bounded task = one branch = one PR; use `git worktree` for parallel agents (`AI_REGRESSION_LESSONS.md` §Y6).

**Branch naming** (`.cursor/rules/p0-gate.mdc`):

```
feat/<slug>  fix/<slug>  chore/<slug>  hotfix/<slug>  td-batch<N>-<slug>
```

---

### 1.3 How engineers / agents create PRs

**Implement (Step 1 — `release-flow.md`):**

1. Create feature branch from `origin/main`.
2. For **DEPLOY/RISKY**: add tests as applicable (PHPUnit in WSL `~/alltrue/backend` only — never on Pi).

**Open PR (Step 2 — `release-flow.md`, `ai-agent-policy.md`):**

1. **Title**: Conventional Commits style (`install-git-hooks.sh` commit-msg hook enforces locally).
2. **Body — mandatory first line**:
   ```
   Release-Class: SAFE | DEPLOY | RISKY
   ```
3. Include: summary, test plan, rollback note, production impact checklist (`release-flow.md` §5 template).
4. Agent **opens PR** (draft OK); agent **does not merge**.

**PR classification (`release-flow.md`):**

| Class | Definition | Merge | Deploy |
|-------|------------|-------|--------|
| **SAFE** | No production runtime change (docs, rules, non-deploy CI, tests only) | CEO or batch | **Never** |
| **DEPLOY** | Intended prod change after merge (bugfix, frontend, backend API) | CEO after review | **CEO explicit approve** |
| **RISKY** | T3: auth, billing, migration, RFID, session deduct | CEO only | CEO + backup + smoke + rollback plan |

**Deployable diff heuristics** (same as `deploy.yml` / `release-flow.md`):

- Deployable: `backend/app/**`, `backend/routes/**`, `backend/database/migrations/**`, `frontend/src/**`, `frontend/package*.json`
- Not deployable: `docs/**`, `.cursor/**`, `*.md`, most `backend/tests/**`

---

### 1.4 Required validation steps (before merge discussion)

**Agent / engineer first-read** (`AGENTS.md`):

1. `docs/INDEX.md` → task-specific docs
2. `docs/AI_REGRESSION_LESSONS.md` (high-risk modules)
3. `.cursor/.local/test-credentials.md` (browser tests)
4. `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` if billing-related
5. `docs/CHAT_BUG_SYSTEM.md` §3.6–§3.7 for in-app bugs

**Risk tier before implementation** (`ai-agent-policy.md`, `AGENTS.md`):

| Tier | Scope | CEO gate |
|------|-------|----------|
| T0 | Docs, rules, governance scripts | Merge when convenient |
| T1 | UI copy, display only | Standard PR review |
| T2 | API, scheduling, attendance UI | CEO merge approval |
| T3 | Auth, billing, session deduct, migration, RFID | CEO merge **and** deploy approval |

**Local hooks** (`scripts/install-git-hooks.sh`):

- pre-push: block push from `main`
- pre-commit: execution guard + git index audit (§R58) + PHP syntax + debug warnings
- commit-msg: Conventional Commits
- post-merge: MemPalace mine (optional)

**Execution guard at commit** (`pre-commit-exec-guard.sh`):

- Blocks direct commits on `main` unless `ALLTRUE_MAIN_OVERRIDE=yes` (CEO emergency)
- Blocks critical path changes (`backend/routes/*`, `backend/database/*`, `.cursor/*`, `AGENTS.md`, `.cursorrules`) without `RELEASE_CLASS=SAFE|DEPLOY|RISKY` when inferred class is DEPLOY/RISKY
- Blocks local merge bypass without executor

**Optional / advisory tooling** (not blocking merge in GitHub):

- `./scripts/decision-engine.sh analyze --pr N`
- `./scripts/decision-simulate.sh --pr N`
- `./scripts/release-orchestrator.sh --pr N`
- `./scripts/ai-write-gate.sh --file …` (agent output policy)
- `.github/workflows/execution-gate.yml` (advisory PR comment only)

**CI (observability — `release-flow.md`, `engineering-system.md`):**

- Green CI: informational; merge still CEO decision
- Red on **required** checks: fix or defer; CEO may admin-merge RISKY with written waiver
- Red on **advisory** checks: may merge if CEO accepts risk (minutes exhaustion policy)
- Skipped CI on docs-only: expected for SAFE

**Required CI check names** (`scripts/lib/release-exec-lib.sh`):

Presubmit Checks, PHPUnit Feature & Unit Tests, Vite Frontend Build, gitleaks scan, Golden scenarios report, Docs Integrity Check, PHPStan Advisory (php)

---

### 1.5 Agent orchestration task types (`AGENTS.md`)

| Type | Handling |
|------|----------|
| Fire-and-forget | Docs batch; avoid single tiny PR |
| Context-dependent | Artifact handoff (API contract, test results) |
| Decision-requiring | PLAN/ARCH or BUG B1; wait for user approval before DEV |

**Stop-the-line** (`AGENTS.md`): production DB writes, auth bypass, push main, force push, Pi tests, unclear CI/deploy state when claiming “done”.

---

## 2. Decision & Risk SOP

### 2.1 Purpose

Decision Intelligence Layer (DIL) **analyzes only** — it does not merge, deploy, or modify code (`decision-intelligence-layer.md`).

**Principle**: *Execution enforces. Intelligence decides.*

---

### 2.2 How `decision-engine.sh` is used

```bash
./scripts/decision-engine.sh analyze --pr <id>
```

**Inputs** (`decision-intelligence-layer.md`, `scripts/lib/decision_analyze.py`):

- PR metadata and file paths (GitHub API via `gh`)
- Production state via `release-check.sh` (JSON)
- Last 20 entries from `.cursor/.local/release-exec-audit.jsonl`
- CI status from PR checks

**Output**: Strict JSON with `risk_score`, `risk_level`, `change_class`, `blast_radius`, `recommended_strategy`, `rollback_complexity`, `confidence`, `reasoning`, `next_actions`.

**Related commands**:

```bash
./scripts/decision-simulate.sh --pr <id>      # SAFE | WARNING | DANGEROUS
./scripts/release-orchestrator.sh --pr <id>   # DIL + release-exec validate + simulation
```

**Mandatory before every PR?** **NOT DEFINED IN CURRENT SYSTEM** — DIL is documented as advisory; CEO/agents use it to inform decisions.

---

### 2.3 PR classification (two parallel systems)

| System | Classes | Where declared |
|--------|---------|----------------|
| **Release-Class** (governance) | SAFE / DEPLOY / RISKY | PR body first line (`release-flow.md`) |
| **Risk tier** (agent workflow) | T0 / T1 / T2 / T3 | Agent analysis / PR comment (`ai-agent-policy.md`) |
| **DIL change_class** | SAFE / DEPLOY / RISKY | Inferred from paths + PR body (`decision_analyze.py`) |
| **DIL risk_level** | low / medium / high / critical | Computed from `scripts/risk-model.json` |

If PR body `Release-Class` missing, `release-exec.sh validate` and DIL **infer** from file paths (`release-exec-lib.sh`, `decision_analyze.py`).

---

### 2.4 Risk scoring (`scripts/risk-model.json`)

**Baseline weights** (0.0–1.0):

| Category | Weight |
|----------|--------|
| payment_change | 0.95 |
| auth_change | 0.90 |
| db_migration | 0.85 |
| session_logic | 0.80 |
| attendance_logic | 0.75 |
| ai_prompt_change | 0.60 |
| frontend_ui | 0.20 |
| docs_only | 0.00 |

**Modifiers** (additive): drift (DIVERGED, PROD_AHEAD), CI failure, audit prior blocks, migration irreversibility, multi-module coupling, workflow/CI changes.

**Recommended strategies** (`decision-intelligence-layer.md`): `direct_merge` | `staged_release` | `canary` | `block`

**How scoring affects workflow** (existing behavior):

- DIL output informs CEO whether to proceed; **does not auto-block GitHub merge**
- `release-exec.sh` blocks merge/deploy based on class, CI, drift, approval env vars
- `release-orchestrator.sh` combines DIL + execution + simulation into `DO_NOT_EXECUTE` | `PROCEED_WITH_CAUTION` | `READY_FOR_CEO_MERGE` (advisory)

**Staging for validation**: **NOT DEFINED IN CURRENT SYSTEM** (staging #868 not provisioned; DIL may recommend “Consider staging deployment (#868)”).

---

## 3. Execution & Release SOP

### 3.1 Core principle

*Nothing reaches production without passing through a deterministic executor* (`execution-layer.md`).

**Single entry point**: `./scripts/release-exec.sh`

| Command | Purpose |
|---------|---------|
| `validate [--pr N]` | Check gates; JSON allowed/blocked |
| `status` | Drift + main snapshot |
| `merge --pr N` | CEO-only PR merge via `gh` |
| `deploy [--pr N]` | CEO-only deploy path (health check + workflow trigger) |
| `rollback [--pr N\|--commit SHA]` | Rollback plan output (no automatic Pi reset) |

---

### 3.2 Merge rules

**Who may merge**: **CEO only** (`engineering-system.md`, `ai-agent-policy.md` A1).

**Process**:

1. `./scripts/release-exec.sh validate --pr N`
2. CEO sets approval env and runs merge:
   ```bash
   ALLTRUE_MERGE_APPROVED=yes ./scripts/release-exec.sh merge --pr N
   ```
3. Dry run: `ALLTRUE_EXEC_DRY_RUN=1 ALLTRUE_MERGE_APPROVED=yes ./scripts/release-exec.sh merge --pr N`

**Blocks merge** (`release-exec.sh`, `execution-layer.md`):

- Missing `ALLTRUE_MERGE_APPROVED=yes` (or `ALLTRUE_EXEC_APPROVED=yes`)
- RISKY without `ALLTRUE_RISKY_APPROVED=yes`
- PR not OPEN
- Production drift PROD_AHEAD / DIVERGED without `ALLTRUE_DRIFT_ACK=yes`
- Critical CI failures on DEPLOY/RISKY without `ALLTRUE_RISKY_APPROVED=yes`
- ⛔ No force push to `main`

**SAFE class**: merge allowed; deploy path blocked by executor for SAFE.

---

### 3.3 Deploy rules

**Separate from merge** (`engineering-system.md`, `release-flow.md`):

- Merge to `main` ≠ production until deploy completes and L1/L2 verify.

**Deploy allowed when** (`release-flow.md` §5):

1. PR class was **DEPLOY** or **RISKY** (or hotfix linked)
2. CEO comment: `Deploy-Approved: yes` on PR or release issue
3. **RISKY**: backup verified (`OPERATIONS_RUNBOOK.md` emergency backup)
4. `./scripts/release-check.sh` run **before and after**

**Executor deploy**:

```bash
ALLTRUE_DEPLOY_APPROVED=yes ./scripts/release-exec.sh deploy --pr N
```

**Blocks deploy**:

- Missing `ALLTRUE_DEPLOY_APPROVED=yes`
- SAFE class (must not deploy)
- RISKY without `ALLTRUE_RISKY_APPROVED=yes`
- Production health check failure
- Drift without acknowledgement

**Deploy mechanisms** (CEO chooses — `release-flow.md`):

| Method | When |
|--------|------|
| A — Automatic | `deploy.yml` after merge (preferred) |
| B — Manual SOP | Minutes exhausted; Pi steps per handoff docs |
| C — Frontend-only emergency | Documented exception; merge to main within 24h |

Agent **never** selects or executes deploy (`release-flow.md`, `ai-agent-policy.md` A3).

**During freeze / minutes scarcity** (`engineering-system.md`): CEO may treat `deploy.yml` as optional; manual deploy allowed with 24h git reconciliation.

---

### 3.4 Approval environment variables (CEO / human only)

| Variable | Grants |
|----------|--------|
| `ALLTRUE_MERGE_APPROVED=yes` | merge |
| `ALLTRUE_DEPLOY_APPROVED=yes` | deploy |
| `ALLTRUE_RISKY_APPROVED=yes` | RISKY merge/deploy; CI waiver |
| `ALLTRUE_DRIFT_ACK=yes` | Proceed despite PROD_AHEAD / DIVERGED |
| `ALLTRUE_EXEC_APPROVED=yes` | Master override (incident) |
| `ALLTRUE_MAIN_OVERRIDE=yes` | Commit directly on `main` (emergency) |

**AI agents must never set these** (`execution-layer.md`).

---

### 3.5 Post-merge / post-deploy verification

```bash
./scripts/release-check.sh
curl -sk https://daan.lifenet.com.tw/api/v1/health
bash scripts/smoke-api.sh   # optional
```

- Update `docs/CHANGELOG.md`
- In-app bugs: mark resolved only after L1/L2 confirm (`CHAT_BUG_SYSTEM.md` §3.7)

**Ops checklist** (`.cursor/rules/p0-gate.mdc` Phase 7):

- Health 200 + `version.json` timestamp (if frontend deployed)
- `migrate:status` if migration involved

---

### 3.6 FinOps batching (`release-flow.md` §3)

When Actions minutes low:

1. Batch SAFE docs PRs — one merge, zero deploy
2. One DEPLOY backend PR per week if possible
3. Do not rerun all open PR workflows
4. Prefer self-hosted runner (#867)

---

## 4. Observability & Drift SOP

### 4.1 Production truth model (`production-truth-model.md`)

| Layer | Source | Authoritative for |
|-------|--------|-------------------|
| **L0** | `GET /api/v1/health` | Site up? |
| **L1** | `version.json` (`hash`, `t`) | Frontend build users see |
| **L2** | Pi git HEAD at `/home/admin/backend` | Backend commit on disk |
| **L3** | `origin/main` | Integration intent |
| **L4** | GitHub Actions | Quality observability (non-blocking for deploy) |

**Rule**: When layers disagree, **L0–L2 win over L3**. Git is the plan; production is the fact.

---

### 4.2 How `release-check.sh` is used

```bash
./scripts/release-check.sh
JSON_OUT=1 ./scripts/release-check.sh   # machine JSON
```

**Read-only** — does not deploy, merge, SSH write, or trigger CI.

**Exit codes**:

- `0` — SYNCED, MAIN_AHEAD, or BACKEND_ONLY_LAG
- `1` — PROD_AHEAD, DIVERGED, or UNKNOWN (CEO action)
- `2` — usage / dependency error

**When to run** (`engineering-system.md`, `production-truth-model.md`):

- Before claiming production status
- Before and after deploy
- When agent discusses release or drift
- Weekly audit checklist

---

### 4.3 Drift taxonomy

| Status | Meaning |
|--------|---------|
| **SYNCED** | L1 matches L3; normal post-frontend deploy |
| **MAIN_AHEAD** | Merged to main; deploy not yet done |
| **BACKEND_ONLY_LAG** | L2 matches L3; L1 older (backend-only release — normal) |
| **PROD_AHEAD** | Prod commit not in main — emergency/manual deploy |
| **DIVERGED** | L1 and L2 different lineages |
| **UNKNOWN** | Cannot resolve prod hash in git |

---

### 4.4 Resolving drift

**MAIN_AHEAD** (expected):

1. CEO classifies pending stack
2. Approved DEPLOY → controlled deploy
3. Re-run `release-check.sh` until SYNCED or BACKEND_ONLY_LAG

**PROD_AHEAD / DIVERGED** (incident):

1. **Stop** new merges for affected surface
2. Record in `AI_REGRESSION_LESSONS.md` + GitHub issue
3. CEO chooses: forward-fix (merge prod into main) or revert prod (Pi reset per runbook)
4. Never leave PROD_AHEAD unrecorded > 24h

**BACKEND_ONLY_LAG**: no action if intent was backend-only; do not use `version.json` alone to claim “prod is old”

**Executor acknowledgement**: `ALLTRUE_DRIFT_ACK=yes` to proceed despite PROD_AHEAD/DIVERGED (`execution-layer.md`)

---

## 5. AI Agent SOP

### 5.1 What AI can do (`ai-agent-policy.md`)

- Read-only: `release-check.sh`, health curl, `gh pr view`, MemPalace, docs
- Code on `feat/*`, `fix/*`, `chore/*` (not `main`)
- Open draft PRs with Release-Class in body
- Docs-only on `chore/*` (T0)
- Local PHPUnit in WSL `~/alltrue/backend`
- Drift analysis, decision-engine analyze (read-only)

---

### 5.2 What AI cannot do (P0 / hard prohibitions)

| ID | Prohibition |
|----|-------------|
| A1 | Merge to `main` without explicit CEO instruction for that PR |
| A2 | `git push --force` to `main` |
| A3 | Trigger or instruct `deploy.yml` / manual Pi deploy |
| A4 | SSH to Pi to **edit** code, `.env`, routes, `.htaccess` |
| A5 | Run `php artisan test` / phpunit on Pi production path |
| A6 | Mark in-app bugs `resolved` without L1/L2 verification |
| A7 | Modify production DB outside approved deploy pipeline |
| A8 | Bulk rerun workflows without CEO ask |

**Additional P0 red lines** (`.cursorrules`, `p0-gate.mdc`):

- R1: No production file edits before CI green (exceptions: new migration/test/Export)
- R2: No Pi test/phpunit/config:clear
- R3: No direct push main / force push
- R4: Full file restore only (`git checkout HEAD -- <file>`)
- R5: `migrate --force` only post-merge deploy
- R6: No SSH to Pi to edit code

---

### 5.3 Required output format before proposing changes

**PR body** (`release-flow.md`):

```markdown
Release-Class: DEPLOY

## Summary
…

## Production impact
- [ ] Frontend / Backend / DB migration / None

## release-check (before merge)
(paste ./scripts/release-check.sh output)

## Rollback
git revert <merge-commit> or Pi reset to PREV_COMMIT

## Deploy
- [ ] Requires CEO Deploy-Approved after merge
```

**Agent handoff / chat output** (`ai-write-gate.sh` required markers):

```
Release-Class: DEPLOY
Risk-Tier: T2 (medium)
Affected modules: …
Diff summary: …
Next step: PR ready for CEO — NOT ready to deploy.
```

Run validation: `./scripts/ai-write-gate.sh --file path/to/handoff.md`

---

### 5.4 Forbidden language & claims (`ai-agent-policy.md` §6, `ai-write-gate.sh`)

Agent **must not** say “已上線 / deployed / live in production” unless:

1. `release-check.sh` shows SYNCED or intentional BACKEND_ONLY_LAG **and**
2. CEO confirmed deploy or deploy workflow success with run ID **and**
3. `curl /api/v1/health` ok

Otherwise: “已 merge，待 CEO 批准 deploy” or “PR ready, production unchanged.”

**Blocked by ai-write-gate** (unless CEO override env): deploy-ready language, claiming merge execution.

---

### 5.5 Escalation to CEO (stop-the-line)

Agent stops and asks when (`ai-agent-policy.md` §7):

- PROD_AHEAD or DIVERGED
- Required migration on production
- Auth, PII, payment rule changes
- CI minutes exhausted with merge implications
- Conflicting instructions between agents/docs vs chat

---

### 5.6 Multi-agent handoff

- One task = one branch = one PR
- Handoff via artifacts: PR link, `docs/SOP_MATURITY.md` 進行中狀態, issue comments
- Later agent must not redo triage/reporter replies marked done

---

## 6. Incident / Rollback SOP

### 6.1 When production drift is detected

See **§4.4** above. PROD_AHEAD/DIVERGED triggers incident path: stop merges, record, CEO resolution within 24h.

Agent escalation: immediate stop-the-line (`ai-agent-policy.md`).

---

### 6.2 Rollback procedure (`RUNBOOK_ROLLBACK.md`)

**⛔ Red line**: No direct SSH to Pi to edit code. Rollback via git + `deploy.yml`.

| Situation | Action |
|-----------|--------|
| Deploy后 health/smoke fails | `deploy.yml` auto-rollback (usually no manual action) |
| Auto rollback insufficient | Revert PR (`git revert` → PR → merge → deploy) §3a |
| Site down, cannot wait for CI | Re-run last successful deploy run §3b |
| Data / migration damage | DB backup first, `migrate:rollback`, restore from backup §3c |

**Immediate rollback criteria**:

- Health not ok / site 5xx
- Core broken: RFID swipe, director login, today’s schedule, payment alerts
- Wrong data writes (session deduct, billing)

**Manual revert path** (WSL2):

```bash
git fetch origin main && git checkout -b fix/rollback-<slug> origin/main
git revert --no-edit <bad-merge-commit>
git push -u origin HEAD && gh pr create …
```

**MTTR targets** (`RUNBOOK_ROLLBACK.md`): auto rollback < 5 min; manual revert path < 30 min.

**Executor rollback command** (`release-exec.sh rollback`):

- Requires `ALLTRUE_RISKY_APPROVED=yes` or `ALLTRUE_EXEC_APPROVED=yes`
- Outputs plan only — **no automatic Pi reset**

---

### 6.3 Release freeze rules (`SRE_POLICY.md`)

**Trigger**: Any SLO miss 14 consecutive days **or** monthly error budget > 50% consumed.

**During freeze**:

1. **Allow merge**: `fix/*`, `hotfix/*`, `chore/security-*`, `docs/*` (not feat)
2. **Block merge**: `feat/*`, feature-labeled PRs
3. **Epic work**: paused; focus on reducing SLI-04 5xx
4. **Lift freeze**: 7 consecutive days all SLOs met + CEO written confirmation

**Exceptions**: `freeze-exception` label + Threat Note + CEO approve (max 2 per freeze cycle).

**Relationship to deploy freeze in `engineering-system.md`**: CEO may treat automatic deploy as optional during minutes freeze — **NOT IDENTICAL** to SRE Release Freeze; both may apply concurrently.

---

### 6.4 Logging & audit requirements

**Every production change must leave** (`production-truth-model.md` §6):

1. Git merge commit on `main` (or hotfix merged within 24h)
2. `docs/CHANGELOG.md` entry
3. Deploy log (`gh run list --workflow=deploy.yml`) or manual deploy record
4. Post-deploy `release-check.sh` output in PR/issue comment

**Execution audit**: append-only `.cursor/.local/release-exec-audit.jsonl` (`execution-layer.md`)

**Incident / rollback**: line in `CHANGELOG` (`ops:`) + `AI_REGRESSION_LESSONS.md` if manual prod edit (`RUNBOOK_ROLLBACK.md`, `release-flow.md` hotfix path)

**Weekly CEO checklist** (`engineering-system.md` §8):

- [ ] `release-check.sh` — no PROD_AHEAD / DIVERGED
- [ ] Open DEPLOY PRs have Deploy-Approved or defer
- [ ] Manual deploy exceptions recorded
- [ ] `docs/SOP_MATURITY.md` 進行中狀態 current or empty
- [ ] No agent merged to main without CEO record

---

## 7. Golden Rules (extracted from repo — not invented)

### 7.1 Production & release

1. **Production layers (L0–L2) beat git intent (L3)** when they disagree (`production-truth-model.md`).
2. **Merge ≠ deploy** — separate CEO gates (`engineering-system.md`, `release-flow.md`).
3. **CEO is sole merge and deploy authority** for production (`ai-agent-policy.md`).
4. **No direct push or commit to `main`** without documented emergency override (`hooks`, `execution-layer.md`).
5. **Every PR declares Release-Class: SAFE | DEPLOY | RISKY** (`release-flow.md`).
6. **SAFE never deploys to production** (`release-flow.md`, `release-exec.sh`).
7. **Drift resolution mandatory before claiming “fixed in production”** (`engineering-system.md`).
8. **PROD_AHEAD must not remain unrecorded > 24h** (`production-truth-model.md`).

### 7.2 Execution & enforcement

9. **Single executor for merge/deploy**: `release-exec.sh` (`execution-layer.md`).
10. **All executor actions logged** to `release-exec-audit.jsonl` (`execution-layer.md`).
11. **AI cannot set approval env vars** (`ALLTRUE_*`) (`execution-layer.md`).
12. **DIL never merges or deploys** (`decision-intelligence-layer.md`).
13. **CI is observability-first** — does not alone grant deploy permission (`engineering-system.md`).

### 7.3 AI & agents

14. **Agents implement on branches only; CEO merges** (`ai-agent-policy.md`).
15. **No Pi phpunit / RefreshDatabase on production** (Incidents C, E — `.cursorrules` P0).
16. **No SSH edit of production application files** (`.cursorrules`, A4).
17. **No force push to main** (Incident A — `.cursorrules` P0).
18. **Agent must not claim deploy/merge without verification** (`ai-agent-policy.md`, `ai-write-gate.sh`).
19. **In-app bug resolved only after production verification** (§R51, §R53 — `CHAT_BUG_SYSTEM.md`).

### 7.4 Development safety (P0 / yellow lines)

20. **No production code change before CI green** (R1 — exceptions: migration, test, Export class).
21. **Tests only in WSL or GitHub Actions — never Pi production path** (R2).
22. **Full restore only — no partial revert of production files** (R4, Incident D).
23. **`migrate --force` only after approved deploy** (R5).
24. **High-risk modules: read INDEX + AI_REGRESSION_LESSONS before editing** (Y4).

### 7.5 Rollback & incidents

25. **Rollback via git + deploy.yml — not ad-hoc Pi code edits** (`RUNBOOK_ROLLBACK.md`).
26. **DB changes: backup before migrate/rollback** (`RUNBOOK_ROLLBACK.md`, `DANGEROUS_OPERATIONS.md`).
27. **deploy.yml auto-rollback on failed health/smoke** (`RUNBOOK_ROLLBACK.md` §2).

### 7.6 FinOps & batching

28. **Docs-only merge skips production deploy** (`OPERATIONS_RUNBOOK.md`, `deploy.yml` behavior).
29. **Do not batch unrelated deployable diffs into docs PRs** (`OPERATIONS_RUNBOOK.md` §B2 rule 11).
30. **Do not bulk rerun CI workflows** (A8, `release-flow.md` §3).

---

## Appendix A — Script quick reference

| Script | Layer | Executes? |
|--------|-------|-----------|
| `release-check.sh` | Observability | No |
| `decision-engine.sh` | Decision | No |
| `decision-simulate.sh` | Decision | No |
| `release-orchestrator.sh` | Control plane | No |
| `release-exec.sh` | Execution | Yes (CEO + env) |
| `ai-write-gate.sh` | Execution (policy) | No |
| `pre-commit-exec-guard.sh` | Execution (local) | Blocks commit |
| `install-git-hooks.sh` | Execution (local) | Installs hooks |

---

## Appendix B — Engineering OS layer map

| Layer | Question | Artifact |
|-------|----------|----------|
| Governance | What are the rules? | `engineering-system.md`, `release-flow.md` |
| Observability | What is production truth? | `release-check.sh`, L0–L4 model |
| Decision Intelligence | Should we? How? | `decision-engine.sh`, `risk-model.json` |
| Execution | Allowed to act now? | `release-exec.sh`, hooks |
| Audit | What happened? | `release-exec-audit.jsonl`, `CHANGELOG`, `version.json` |

---

*This document is a consolidation extract. For disputes, the linked authoritative source files win.*
