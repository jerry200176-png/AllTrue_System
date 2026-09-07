# Incident Decision System

> **Execution protocol reference** — not a parallel runtime system.  
> **Single entry point:** this file. **Single execution endpoint:** [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml) (contract I1).

> **Runtime spec:** [`docs/CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md) (supreme on conflict)  
> **Policy:** [`docs/INCIDENT_POLICY_ENGINE.md`](INCIDENT_POLICY_ENGINE.md) · **Loop:** [`docs/INCIDENT_RUNTIME_LOOP.md`](INCIDENT_RUNTIME_LOOP.md)  
> **Inference:** [`docs/INCIDENT_INFERENCE_ENGINE.md`](INCIDENT_INFERENCE_ENGINE.md) · **States:** [`docs/INCIDENT_STATE_MACHINE.md`](INCIDENT_STATE_MACHINE.md)  
> **Severity lookup:** [`docs/SEVERITY_MATRIX.md`](SEVERITY_MATRIX.md) (reference only) · **Rollback helper:** [`docs/RUNBOOK_ROLLBACK.md`](RUNBOOK_ROLLBACK.md) (reference only)

---

## Runtime decision mode

| Mode | When | Rule |
|------|------|------|
| **Inferred Mode** (default) | All production incidents | STATE + FINAL_ACTION from inference + policy tables — **mandatory** |
| **Override Mode** | **ESCALATED_FAILURE only** (Policy SH-2 or VERIFY fail) | Explicit documented override + CEO LINE — [`CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md) |

**Hard rule:** FINAL_ACTION from policy resolver or inference fallback — never ad-hoc.  
**Precedence:** POLICY > STATE > SIGNAL · **Contract I1–I5 supreme on conflict**

---

## Run policy loop (every iteration)

1. Observe signals + CONTEXT (Step 1).
2. Infer STATE — [`INCIDENT_INFERENCE_ENGINE.md`](INCIDENT_INFERENCE_ENGINE.md).
3. Apply policy — [`INCIDENT_POLICY_ENGINE.md`](INCIDENT_POLICY_ENGINE.md) → FINAL_ACTION.
4. Execute FINAL_ACTION if deploy-eligible — **only** via [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml) or documented runbook helper steps (contract I1).
5. Verify → RESOLVE, SH-1 short-circuit, re-loop, or ESCALATED_FAILURE (SH-2).

---

## Authority (see contract — do not duplicate)

All runtime decisions and invariants: [`CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md) I1–I5.  
Conflicts: [`CONTRADICTION_REGISTRY.md`](CONTRADICTION_REGISTRY.md).


## Step reference (inference + policy rules)

Apply in **TRIAGE** / policy resolution. Explicit rule → deterministic outcome only.

### Rule 1 — Rollback priority (default action)

If **any** signal = `unknown_error`, `ci_failure` (prod impaired), or `partial_degradation` (core flow affected) → inference retains **TRIAGE** until T+15 → then **CONTAIN** (rollback default).

### Rule 2 — Recovery override

If signal = `rollback_unsafe` → inferred STATE = **RECOVER** (not CONTAIN rollback).

### Rule 3 — Time constraint

**Triage max time = 15 minutes** (`T0` to end of TRIAGE).

If exceeded → inference Rule 4 → **CONTAIN** (rollback) or **RECOVER** (if `rollback_unsafe`).

---

## Execution binding (contract I1 + I4)

| Layer | Role |
|-------|------|
| **Policy** | Resolve FINAL_ACTION |
| **Inference** | Infer STATE from signals |
| **deploy.yml** | **Only** executor of production changes |

Policy MUST NOT replace `deploy.yml`. Runbooks describe steps; they do not execute.

---

## STOP-THE-WORLD RULE (non-negotiable)

**If the system cannot be stabilized OR root cause cannot be identified within 15 minutes → mandatory rollback** — unless [Rollback Safety Exception](#rollback-safety-exception) applies.

Rollback = revert bad commit on `main` → main CI → Founder-approved exact-main deployment via `deploy.yml`. (Note: `deploy.yml` strictly enforces `target_sha == current main`; historical-SHA dispatch or re-running prior deploy runs fails closed). Investigate after service is stable.

Log `T0` = first alert or user report. At `T0 + 15 min`, if still impaired → **rollback now** (or recovery mode if safety exception applies).

---

## Rollback Safety Exception

**Do NOT rollback** if rollback would cause **more severe irreversible damage** than staying on the current state:

| Risk | Examples |
|------|----------|
| **Database corruption risk** | Suspected partial migration, schema half-applied, rollback would leave DB inconsistent |
| **Migration irreversible state** | `down()` missing or unsafe; revert deploy would re-run bad migration path |
| **Data loss amplification** | Rollback would drop/recreate rows that good backup cannot restore |

**If exception applies:**

1. **DO NOT rollback** via deploy path.
2. **Escalate to recovery mode** — backup first, then [`RUNBOOK_ROLLBACK.md`](RUNBOOK_ROLLBACK.md) §3c + [`OPERATIONS_RUNBOOK.md`](OPERATIONS_RUNBOOK.md) §P.
3. **Notify CEO via LINE** before destructive restore.
4. Document why rollback was withheld (MTTR note).

This is the **only** exception to the 15-minute mandatory rollback rule.

---

## Step 1 — Observe symptoms (0–5 min) · state: DETECT → TRIAGE

Collect **CONTEXT** at observe: `user_facing_impact`, `migration_irreversible`, `incident_repeat_count`, `ci_blocks_hotfix`.

```bash
curl -sk https://daan.lifenet.com.tw/api/v1/health | python3 -m json.tool
gh run list --workflow="Deploy to Pi" --limit 3
gh run list --workflow="CI — PHPUnit Tests" --limit 3
```

| Signal ID | Condition |
|-----------|-----------|
| `system_down` | Health not `{"status":"ok",...}` OR core flows broken (login, RFID, today schedule) |
| `ci_failure` | Required checks failing; cannot merge fixes |
| `db_anomaly` | Wrong/corrupt data; bad deductions/billing; migration suspicion |
| `deploy_failure` | Latest `Deploy to Pi` failed (within 24h) |
| `partial_degradation` | Health OK; non-core feature broken |
| `unknown_error` | No signal above matches |
| `rollback_unsafe` | Rollback would worsen DB/migration/data loss — see [Rollback Safety Exception](#rollback-safety-exception) |

Derive severity from STATE + signal. Record `T0`. Infer STATE — then apply [`INCIDENT_POLICY_ENGINE.md`](INCIDENT_POLICY_ENGINE.md) for FINAL_ACTION.

**MemPalace stale index is NOT an incident here.** Not in SLO. Not in alerting. → [`MEMPALACE_OPERATIONS_HANDBOOK.md`](MEMPALACE_OPERATIONS_HANDBOOK.md) only.

---

## Step 2 — Inference window (5–15 min) · state: TRIAGE

Inferred STATE = **TRIAGE** unless Rules 1–3 map directly to CONTAIN/RECOVER.

### Hard rule (inference Rule 4)

At **T0 + 15 min**: if not stable or root cause unknown → re-infer → **CONTAIN** or **RECOVER** (`rollback_unsafe`).

### While in TRIAGE (before timeout)

| Signal | Inferred follow-up |
|--------|-------------------|
| `system_down` | Re-check deploy log; if auto-rollback succeeded, wait 2 min → re-infer |
| `ci_failure` | Check runner — [`OPERATIONS_RUNBOOK.md`](OPERATIONS_RUNBOOK.md) §B4 |
| `db_anomaly` | Backup before any write |
| `unknown_error` | Treat as `system_down` risk until T+15 |

**Never on Pi:** `php artisan test`, `config:clear`, SSH edit app code. → [`DANGEROUS_OPERATIONS.md`](DANGEROUS_OPERATIONS.md)

---

## Step 3 — Execute FINAL_ACTION · policy-resolved path

Follow paths below for FINAL_ACTION (`rollback_deploy`, `recover_db`, `verify_only`, or fallback).

```
Rollback Safety Exception (DB corruption / irreversible migration / data loss amplification)?
  └─ YES → DO NOT rollback; recovery mode (RUNBOOK §3c, OPERATIONS_RUNBOOK §P); CEO LINE

Site down or Unknown (and not stable by T+15)?
  └─ Deploy-related (recent merge/deploy)?
       YES → Rollback via deploy.yml path (below) — incident decision triggers deploy execution
       NO  → RUNBOOK_ROLLBACK §3a (code) OR §3c if DB; at T+15 still unknown → rollback unless safety exception

CI broken?
  └─ Production OK → fix CI (runner, fix/* branch); P1
  └─ Production down + CI blocks hotfix → emergency ops per OPERATIONS_RUNBOOK §B2-12 + document

DB issue?
  └─ Backup → migrate:rollback if deploy migration (RUNBOOK §3c)
  └─ Restore from sixhour/nightly if data corrupt (OPERATIONS_RUNBOOK §P)
  └─ PITR: NOT AVAILABLE (RPO ≈ 6h, TECH_DEBT TD-015)
```

### Deploy-related → rollback via `deploy.yml` path

**Supported path (bad commit merged/deployed):**

Because `deploy.yml` strictly enforces `target_sha == current main`, historical-SHA dispatch or re-running past deploy runs is rejected by GitHub Actions gates. The only supported post-success rollback path is:

1. Create a revert branch and commit:
```bash
cd <safe-task-worktree>  # never /home/jerry/alltrue — WORKTREE_POLICY.md
git fetch origin main && git checkout -b fix/rollback-<slug> origin/main
git revert --no-edit <bad-commit-hash>
git push -u origin HEAD
gh pr create --title "revert: hotfix rollback" --body "Incident rollback"
```
2. Merge revert PR to `main` (fast-track merge via standard gates).
3. Ensure `CI — PHPUnit Tests` succeeds on `main` for the new revert SHA (`$REVERT_SHA`).
4. Trigger production deployment targeting current `main`:
   - Auto-deploy (if eligible): `deploy.yml` deploys `$REVERT_SHA`.
   - Manual/protected activation:
```bash
gh workflow run deploy.yml \
  --ref main \
  -f phase=application-deploy \
  -f target_sha="$REVERT_SHA" \
  -f confirm="ACTIVATE_PRODUCTION:$REVERT_SHA"
```

**Auto-rollback (in-flight only):** If a deployment run itself fails health or smoke checks, `deploy.yml` automatically resets Pi to `PREV_COMMIT` before concluding failed. Check Actions log for rollback status.

Full detail: [`RUNBOOK_ROLLBACK.md`](RUNBOOK_ROLLBACK.md)

### DB-related → safe mode

1. Backup (mandatory):

```bash
TS=$(date '+%Y-%m-%d_%H%M%S')
mysqldump -h 127.0.0.1 -u admin -p"$(grep DB_PASSWORD /home/admin/backend/.env | cut -d= -f2)" \
  --single-transaction AllTrue | gzip > /home/admin/backups/emergency/db_pre_incident_${TS}.sql.gz
```

2. Schema: `php artisan migrate:rollback --step=N --force` only if `down()` exists.  
3. Data: restore from backup — do not guess. CEO approval before restore to production.

### CI broken → pipeline path

1. `gh api repos/jerry200176-png/AllTrue_System/actions/runners` — runner online?  
2. Fix on `fix/*` branch; never push directly to `main`.  
3. Emergency bypass (frontend only, documented exception): `OPERATIONS_RUNBOOK.md` §B2 rule 12 + `DEPLOYMENT.md`.

### Unknown → CONTAIN at T+15

Inference Rule 4: signal `unknown_error` at T+15 → **CONTAIN** (rollback) unless `rollback_unsafe` → **RECOVER**.

Partial feature bug with health OK → signal `partial_degradation` → **TRIAGE** → fix-forward `fix/*` PR; not CONTAIN unless core flows break.

---

## Step 4 — Escalation · ESCALATED_FAILURE or destructive recovery

**Single operator system. One route only:**

| Trigger | Action |
|---------|--------|
| VERIFY fail after CONTAIN/RECOVER | **ESCALATED_FAILURE** → Override Mode + CEO LINE |
| Rollback Safety Exception applies | **Notify CEO via LINE** before recovery/restore |
| DB restore needed | CEO decision before restore |
| Rollback succeeded but data wrong | CEO + backup review |

No secondary on-call. No further routing logic.

---

## Verify recovery · state: VERIFY → RESOLVE

```bash
curl -sk https://daan.lifenet.com.tw/api/v1/health | python3 -m json.tool
bash scripts/post-merge-smoke.sh   # WSL2, after sync
```

- [ ] Health OK  
- [ ] `CHANGELOG.md` one line (`ops: ...`)  
- [ ] Pattern → `AI_REGRESSION_LESSONS.md`  
- [ ] Rollback used → MTTR note per `RUNBOOK_ROLLBACK.md` §4  

---

*Contract: CONTROL_PLANE_CONTRACT. Decision: INCIDENT stack. Execution: deploy.yml only. Demoted refs: SEVERITY, ROLLBACK, SMOKE.*
