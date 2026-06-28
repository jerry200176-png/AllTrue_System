> **HISTORICAL CONTEXT ONLY**
> This document does **not** override [`CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md) (I1–I5).
> **Decision:** INCIDENT stack only (I3). **Execution:** [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml) only (I1).
> Do not use this file for runtime operations.


# AI Agent Policy — Production-Controlled Engineering

> **Scope**: Cursor, Claude Code, Copilot, and any automated agent with repo or shell access.  
> **Enforcement**: Process + branch protection + human CEO gate. Agents comply by instruction, not by unsupervised privilege.

---

## 1. Permission model

```
┌─────────────────────────────────────────────────────────────┐
│ CEO (human) — sole release authority                        │
│  • merge to main (when required checks satisfied or admin)  │
│  • approve DEPLOY / RISKY releases                          │
│  • SSH read-only audit; emergency prod actions              │
└───────────────────────────┬─────────────────────────────────┘
                            │
┌───────────────────────────▼─────────────────────────────────┐
│ AI Agent — propose & implement on branches only             │
│  • feature/fix/chore branches                               │
│  • tests, docs, analysis                                    │
│  • PR creation (draft OK)                                   │
└───────────────────────────┬─────────────────────────────────┘
                            │
┌───────────────────────────▼─────────────────────────────────┐
│ CI — observability only (non-blocking for deploy)           │
│  • signals quality; does not auto-deploy by policy change   │
└─────────────────────────────────────────────────────────────┘
```

---

## 2. Hard prohibitions (P0 for agents)

| # | Agent MUST NOT | Rationale |
|---|----------------|-----------|
| A1 | Merge to `main` without explicit CEO instruction for that PR | Prevents multi-agent race & unreviewed release |
| A2 | `git push --force` to `main` or production branches | Incident A |
| A3 | Trigger or instruct `deploy.yml` / manual Pi deploy | Release is CEO-controlled |
| A4 | SSH to Pi to **edit** application code, `.env`, routes, `.htaccess` | Incident B–F |
| A5 | Run `php artisan test` / phpunit on Pi production path | Incident C, E |
| A6 | Mark in-app bugs `resolved` without L1/L2 production verification | §R51, §R53 |
| A7 | Modify production DB (migrate, UPDATE, DELETE) except via approved deploy pipeline | Data loss |
| A8 | Rerun all workflows to “burn minutes” or “refresh CI” without CEO ask | FinOps + queue starvation |

Existing `.cursorrules` P0 red lines remain in force; this policy adds **release governance**.

---

## 3. Allowed without CEO pre-approval

- Read-only: `release-check.sh`, health curl, `gh pr view`, `gh issue view`, MemPalace search, docs.
- Branch work: commit on `feat/*`, `fix/*`, `chore/*` (not `main`).
- Open **draft** PRs with classification label in description (SAFE / DEPLOY / RISKY).
- Docs-only changes on `chore/*` (T0).
- Local PHPUnit in WSL `~/alltrue/backend` (never on Pi).

---

## 4. Required agent workflow (before proposing code)

1. **Read** `docs/INDEX.md` → task-specific doc → `docs/production-truth-model.md` if release-related.
2. **Run** `./scripts/release-check.sh` (read-only) when change may affect production or deploy claims.
3. **Classify risk** (mandatory in PR/issue comment):

| Tier | Criteria | Agent may implement on branch? | CEO gate |
|------|----------|----------------------------------|----------|
| **T0** | Docs, rules, scripts under `docs/` / `scripts/` governance only | Yes | Merge when convenient |
| **T1** | UI copy, pure display, no auth/DB | Yes | Standard PR review |
| **T2** | API contract, scheduling, attendance UI | Yes + tests | CEO merge approval |
| **T3** | Auth, billing, session deduct, migration, RFID | Yes + tests + SEC notes | CEO merge **and** deploy approval |

4. **Output analysis first** when asked for cleanup, drift, or architecture — implement only if user says “execute” / “批准” / “continue”.

---

## 5. Multi-agent coordination

- One **bounded task** = one branch = one PR. No shared dirty `main` worktree (see `AI_REGRESSION_LESSONS.md` §Y6).
- Handoff via artifacts only: PR link, `docs/SOP_MATURITY.md` 進行中狀態, issue comments — not chat memory.
- Later agent **must not** redo triage, roadmap, or reporter replies marked done in handoff.

---

## 6. Deploy and “done” language

Agent **must not** say “已上線 / deployed / live in production” unless:

1. `./scripts/release-check.sh` shows SYNCED or intentional BACKEND_ONLY_LAG **and**
2. CEO confirmed deploy **or** deploy workflow success is cited with run ID **and**
3. `curl /api/v1/health` ok.

Otherwise use: “已 merge，待 CEO 批准 deploy” or “PR ready, production unchanged.”

---

## 7. Escalation to CEO (stop-the-line)

Agent stops and asks when:

- `release-check.sh` reports PROD_AHEAD or DIVERGED.
- Required migration on production.
- Any auth, PII, payment rule change (`DIRECTOR_PAYMENT_ALERT_RULES.md`).
- CI minutes exhausted and merge would queue hosted runners (propose self-hosted or defer).
- Conflicting instructions between agents in docs vs chat.

---

## 8. Related documents

- [`engineering-system.md`](engineering-system.md)
- [`release-flow.md`](release-flow.md)
- [`production-truth-model.md`](production-truth-model.md)
- `.cursorrules` §P0
