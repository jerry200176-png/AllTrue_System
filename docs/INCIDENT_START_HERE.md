# Incident Decision System

> **Production incident only.** Runtime loop entry.  
> **Policy:** [`docs/INCIDENT_POLICY_ENGINE.md`](INCIDENT_POLICY_ENGINE.md) · **Loop:** [`docs/INCIDENT_RUNTIME_LOOP.md`](INCIDENT_RUNTIME_LOOP.md)  
> **Inference:** [`docs/INCIDENT_INFERENCE_ENGINE.md`](INCIDENT_INFERENCE_ENGINE.md) · **States:** [`docs/INCIDENT_STATE_MACHINE.md`](INCIDENT_STATE_MACHINE.md)  
> **Severity:** [`docs/SEVERITY_MATRIX.md`](SEVERITY_MATRIX.md) · **Runbook:** [`docs/RUNBOOK_ROLLBACK.md`](RUNBOOK_ROLLBACK.md) · **Constraints:** [`docs/OPERATIONAL_CONSTRAINTS.md`](OPERATIONAL_CONSTRAINTS.md)

---

## Runtime decision mode

| Mode | When | State assignment |
|------|------|------------------|
| **Inferred Mode** (default) | All production incidents | STATE from [`INCIDENT_INFERENCE_ENGINE.md`](INCIDENT_INFERENCE_ENGINE.md) — **mandatory** |
| **Override Mode** | **ESCALATED_FAILURE** only | Human may assign STATE — must document reason + CEO LINE |

**Hard rule:** FINAL_ACTION from policy resolver (or inference fallback) — never ad-hoc.  
**Precedence:** **POLICY > STATE > SIGNAL**

---

## Run policy loop (every iteration)

1. Observe signals + CONTEXT (Step 1).
2. Infer STATE — [`INCIDENT_INFERENCE_ENGINE.md`](INCIDENT_INFERENCE_ENGINE.md).
3. Apply policy — [`INCIDENT_POLICY_ENGINE.md`](INCIDENT_POLICY_ENGINE.md) → FINAL_ACTION.
4. Execute FINAL_ACTION if deploy-eligible (Step 3 runbook paths).
5. Verify → RESOLVE, SH-1 short-circuit, re-loop, or ESCALATED_FAILURE (SH-2).

---

## Operational authority contract

1. **INDEX** = registry only (no authority)
2. **INCIDENT system** = decision authority
3. **`deploy.yml`** = execution authority
4. **INCIDENT system overrides INDEX**
5. **Deploy system executes only policy-resolved FINAL_ACTION** (or inference fallback)

---

## Control plane binding

| Layer | Role | File |
|-------|------|------|
| **Policy layer** | FINAL_ACTION path compression + safety override | [`INCIDENT_POLICY_ENGINE.md`](INCIDENT_POLICY_ENGINE.md) |
| **Incident system** | Inference + state classification | [`INCIDENT_INFERENCE_ENGINE.md`](INCIDENT_INFERENCE_ENGINE.md) + [`INCIDENT_STATE_MACHINE.md`](INCIDENT_STATE_MACHINE.md) |
| **Execution system** | Deploy, rollback, health/smoke | [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml) |

**Hard rules:**

- **Policy MAY override** naive STATE→ACTION mapping (POLICY > STATE > SIGNAL).
- **Policy MUST NOT assign STATE** (except SH-2 → ESCALATED_FAILURE).
- **Deploy system MUST NOT:** decide STATE, select policy, or skip audit log.

- **Deploy system executes only FINAL_ACTION** from policy resolver or inference fallback.

Only committed files on `origin/main` define system truth. [`docs/INDEX.md`](INDEX.md) references them.

---

## Step reference (inference + policy rules)

Apply in **TRIAGE** / policy resolution. No interpretation beyond these rules + policy table.

### Rule 1 — Rollback priority (default action)

If **any** signal = `unknown_error`, `ci_failure` (prod impaired), or `partial_degradation` (core flow affected) → inference retains **TRIAGE** until T+15 → then **CONTAIN** (rollback default).

### Rule 2 — Recovery override

If signal = `rollback_unsafe` → inferred STATE = **RECOVER** (not CONTAIN rollback).

### Rule 3 — Time constraint

**Triage max time = 15 minutes** (`T0` to end of TRIAGE).

If exceeded → inference Rule 4 → **CONTAIN** (rollback) or **RECOVER** (if `rollback_unsafe`).

---

## Authority boundary (policy vs inference vs execution)

| System | Role |
|--------|------|
| **Policy** | Resolve FINAL_ACTION; compress path; P0 safety override |
| **Inference** | Infer STATE from signals |
| **Deploy (execution)** | Run FINAL_ACTION when deploy-eligible |

- **Policy > STATE > SIGNAL** for execution path.
- **Deploy system MUST NOT** decide STATE, select policy, or skip audit log.

---

## STOP-THE-WORLD RULE (non-negotiable)

**If the system cannot be stabilized OR root cause cannot be identified within 15 minutes → mandatory rollback** — unless [Rollback Safety Exception](#rollback-safety-exception) applies.

Rollback = revert bad commit on `main` and redeploy via `deploy.yml`, OR re-run last successful `Deploy to Pi` workflow. Investigate after service is stable.

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
       NO  → RUNBOOK_ROLLBACK §3b OR §3c if DB; at T+15 still unknown → rollback unless safety exception

CI broken?
  └─ Production OK → fix CI (runner, fix/* branch); P1
  └─ Production down + CI blocks hotfix → rollback via §3b if needed; bypass only per OPERATIONS_RUNBOOK §B2-12 + document

DB issue?
  └─ Backup → migrate:rollback if deploy migration (RUNBOOK §3c)
  └─ Restore from sixhour/nightly if data corrupt (OPERATIONS_RUNBOOK §P)
  └─ PITR: NOT AVAILABLE (RPO ≈ 6h, TECH_DEBT TD-015)
```

### Deploy-related → rollback via `deploy.yml` path

**Preferred (main has bad commit):**

```bash
cd ~/alltrue
git fetch origin main && git checkout -b fix/rollback-<slug> origin/main
git revert --no-edit <bad-commit-hash>
git push -u origin HEAD
gh pr create --title "revert: hotfix rollback" --body "Incident rollback"
# CI green → merge → deploy.yml redeploys
```

**Faster (CI unavailable, site down):**

```bash
gh run list --workflow="Deploy to Pi" --limit 10
# Re-run last SUCCESSFUL deploy, OR RUNBOOK_ROLLBACK.md §3b
```

**Auto-rollback:** If latest deploy failed with rollback success in log, wait before manual action unless health still bad at T+5.

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

*Policy loop: INCIDENT_POLICY_ENGINE (FINAL_ACTION). Classification: inference. Execution: deploy.yml when FINAL_ACTION permits. Override: ESCALATED_FAILURE only.*
