> **HISTORICAL CONTEXT ONLY**
> This document does **not** override [`CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md) (I1–I5).
> **Decision:** INCIDENT stack only (I3). **Execution:** [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml) only (I1).
> Do not use this file for runtime operations.


# Production Truth Model

> **Authority**: This document is the single source of truth for *what counts as production* and how drift is resolved.  
> **Supersedes**: informal assumptions that `main` HEAD equals production, or that green CI equals deployed.

---

## 1. Layered truth (what is authoritative for what)

| Layer | Source | Authoritative for | Not authoritative for |
|-------|--------|-----------------|------------------------|
| **L0 — Runtime health** | `GET /api/v1/health` → `{"status":"ok"}` | “Is the site up?” | Which commit is running |
| **L1 — Deployed frontend identity** | `https://daan.lifenet.com.tw/version.json` (`hash`, `t`) | Which **frontend build** users see; regression smoke anchor | Backend PHP code, DB schema, `.env` |
| **L2 — Deployed backend identity** | Pi `git rev-parse HEAD` at `/home/admin/backend` (read-only audit) | Which **backend commit** is on disk | Frontend bundle if build was skipped |
| **L3 — Integration intent** | `origin/main` on GitHub | What **should** be released next; audit trail of merged PRs | What is live until L1/L2 confirm deploy |
| **L4 — CI signals** | GitHub Actions conclusions | Quality observability; advisory gates | Deploy permission (non-blocking by policy) |

**Rule**: When layers disagree, **production layers (L0–L2) win over git (L3)**. Git is the plan; production is the fact.

---

## 2. Why `version.json` is primary (with one caveat)

`version.json` is written by Vite at frontend build time (`frontend/vite.config.js`):

```json
{ "t": "2026-06-27 06:21", "hash": "acf1251" }
```

- `hash` = short git commit at build time (8 chars).
- `t` = build timestamp (Asia/Taipei).

**Caveat (documented, not a bug)**: `deploy.yml` may **skip** frontend rebuild on backend-only merges. Then L1 (`version.json`) stays stale while L2 (Pi git HEAD) advances. This is **backend-only lag**, not necessarily drift.

**Interpretation**:

| L1 hash vs L2 HEAD | Meaning |
|--------------------|---------|
| Equal | Full-stack deploy (or frontend rebuilt on this commit) |
| L2 ahead of L1 hash | Backend-only deploy — normal |
| L1 hash **not an ancestor of** L2 | Possible partial/manual deploy — investigate |
| L1 hash **not in** `main` history | Manual hotfix on prod — **merge or revert required** |

---

## 3. Drift taxonomy

| Status | Definition | Typical cause |
|--------|------------|---------------|
| **SYNCED** | L1 hash matches L3 HEAD (short), L2 matches L3 | Normal post-frontend deploy |
| **MAIN_AHEAD** | L3 ahead of L1/L2; prod still on older commit | Merge done, deploy not yet approved |
| **BACKEND_ONLY_LAG** | L2 matches L3; L1 hash older but ancestor of L3 | Backend-only release |
| **PROD_AHEAD** | L1 or L2 commit **not contained in** L3 | Emergency manual deploy from feature branch |
| **DIVERGED** | L1 and L2 point to different lineages | Partial deploy, failed rollback, or manual mix |
| **UNKNOWN** | Cannot resolve prod `hash` in git | Typo, corrupted artifact, wrong URL |

Run `./scripts/release-check.sh` for machine-readable classification.

---

## 4. Resolving drift (deterministic)

### 4.1 MAIN_AHEAD (expected)

1. CEO classifies pending merge stack (`docs/release-flow.md`).
2. Approved **DEPLOY** release → run controlled deploy (see `docs/engineering-system.md` §Release).
3. Re-run `release-check.sh` until SYNCED or BACKEND_ONLY_LAG (if backend-only).

### 4.2 PROD_AHEAD / DIVERGED (incident)

1. **Stop** new merges to `main` for the affected surface (frontend/backend).
2. Record incident in `docs/AI_REGRESSION_LESSONS.md` + GitHub issue.
3. Choose one path (CEO decision):
   - **Forward fix**: merge prod branch into `main` (preferred — preserves work).
   - **Revert prod**: Pi `git reset --hard` to known-good L3 commit + opcache reset (see `OPERATIONS_RUNBOOK.md` §D).
4. Never leave PROD_AHEAD unrecorded > 24h.

### 4.3 BACKEND_ONLY_LAG (normal)

- No action if release intent was backend-only.
- Update release log (CHANGELOG / deploy note).
- Do **not** use `version.json` alone to claim “prod is old.”

---

## 5. Rollback model

Rollback always targets a **known L2 commit** (Pi git HEAD), not a CI run ID.

| Scope | Action | Verify |
|-------|--------|--------|
| **Code** | `git revert` on `main` → merge → deploy **or** Pi reset to `PREV_COMMIT` per `deploy.yml` | L2 HEAD, health 200 |
| **Frontend** | Force frontend rebuild on target commit (deploy with `ASSETS_MISSING` or explicit rebuild flag) | L1 hash matches target |
| **DB** | `migrate:rollback --step=N` **only** if migration was part of failed release; backup first | `migrate:status` |
| **Config** | Never rollback `.env` via git; restore from backup snapshot | auth + health |

**Rollback SLA**: decision ≤ 10 min; execution ≤ 30 min (see `.cursor/rules/module-industry-standards.mdc`).

---

## 6. Audit trail (required artifacts)

Every production change must leave:

1. Git merge commit on `main` (or documented hotfix branch merged within 24h).
2. Entry in `docs/CHANGELOG.md` (user-visible or `開發備註：`).
3. Deploy log reference (`gh run list --workflow=deploy.yml`) **or** manual deploy record in issue/PR.
4. Post-deploy: `release-check.sh` output archived in PR comment or ops issue.

---

## 7. Related documents

| Doc | Role |
|-----|------|
| [`engineering-system.md`](engineering-system.md) | Full governance |
| [`release-flow.md`](release-flow.md) | PR classification & deploy gates |
| [`ai-agent-policy.md`](ai-agent-policy.md) | Who may propose vs execute |
| [`OPERATIONS_RUNBOOK.md`](OPERATIONS_RUNBOOK.md) §I, §X3 | Legacy deploy details |
| `scripts/release-check.sh` | Drift detector |
