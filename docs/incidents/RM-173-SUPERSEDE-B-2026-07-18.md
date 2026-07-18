# Repair Manifest — IMMUTABLE — in-app #173 Option B

**Manifest ID:** `RM-173-SUPERSEDE-B-2026-07-18`  
**Status:** LOCKED for execute (dry-run matched; independent verifier APPROVE_FOR_EXECUTE)  
**Created at (UTC):** 2026-07-18T06:05:00Z  
**Do not expand selection after this file is committed.**

---

## Identifiers

| Field | Value |
|-------|-------|
| Incident / bug | in-app **#173**; GitHub **#932** (closed tracking) |
| Decision | Founder Decision 1 (2026-07-18) — Option **B** (conditional, Data Repair Gate) |
| Prior packet | `docs/incidents/173-decision-packet-2026-07-16.md` (CEO chose B 2026-07-16) |
| PCR | `docs/runbooks/173-supersede-b-pcr.md` |
| Command | `php artisan repair:supersede-renewal-session --case=173` |
| Workflow | `.github/workflows/173-supersede-repair.yml` |
| Code revision (repair landed) | PR **#1241** (`83d3c413`) + workflow **#1243** |
| Production revision at dry-run | `version.json` hash **`8caa4065`** (2026-07-18 13:47 +08) |
| Dry-run Actions run | https://github.com/jerry200176-png/AllTrue_System/actions/runs/29633309003 |
| Independent verifier | Subagent review 2026-07-18 — **APPROVE_FOR_EXECUTE** |
| Executor | GitHub Actions `gha:173-supersede` / actor `cursor:173-gate-execute` |
| Risk class | **R3** (production data repair) |

---

## Precise root cause

Renewal created overlapping ClassSessions for the same physical slot **2026-06-10 19:00–21:00**:

- Old contract **SC#114** (ended 2026-06-02, Stop=1) still holds session **#11292** (`attended`) beyond EndDate.
- Renewal **SC#2076** holds session **#16951** (`completed`) for the same slot.
- Both have approved LearningRecords → duplicate attendance / eval / payroll risk.

Option B: keep renewal session; mark old overlap superseded (cancelled + audit). No Invoice / Used / Remaining / LR void.

---

## Affected tables / record identifiers (FIXED SET)

| Table | IDs | Mutation |
|-------|-----|----------|
| `ClassSession` | **11292** | `Status: attended → cancelled`; Note append tag |
| `ClassSession` | **16951** | **NONE** (keeper) |
| `session_corrections` | new row | insert audit (append-only) |
| `LearningRecord` | **8883**, **9959** | **NONE** |
| `StudentClass` | **114**, **2076** | **NONE** |
| `Invoice` | **137**, **936** | **NONE** |
| `Payment` | (linked) | **NONE** |

**Selection query (fixed — do not rewrite):**

```sql
SELECT id, StudentClassID, SessionDate, StartTime, Status, Note
FROM ClassSession
WHERE id IN (11292, 16951);
-- Abort unless:
-- 11292.StudentClassID=114 AND 16951.StudentClassID=2076
-- AND both SessionDate='2026-06-10' AND StartTime starts with '19:00'
```

**Expected affected write count:** exactly **1** ClassSession update + **1** session_corrections insert (or **0** if already applied / noop).

---

## Before state (production, 2026-07-18 — API + dry-run)

| Record | Before |
|--------|--------|
| CS#11292 | Status=`attended`, SC=114, 2026-06-10 19:00, Note=`auto-extended-after-leave` |
| CS#16951 | Status=`completed`, SC=2076, 2026-06-10 19:00, Note contains `auto-extended-after-leave; 系統調整堂次（原 2026-08-12）` |
| SC#114 | Used=**8** Rem=**0** Stop=1 |
| SC#2076 | Used=**7** Rem=**1** Stop=0 |
| LR#8883 | on CS#11292, VoidedAt=null (dry-run preserved_lr) |
| LR#9959 | on CS#16951, VoidedAt=null (dry-run keeper_lr) |

Dry-run output excerpt (run 29633309003):

```
WOULD supersede ClassSession id=11292 → cancelled; replaced_by_session_id=16951; reason=duplicate_after_renewal; ref=in-app #173
  preserved_lr=8883 keeper_lr=9959
COUNTERS (must stay): SC114 Used=8 Rem=0 | SC2076 Used=7 Rem=1
```

---

## Intended after state

| Record | After |
|--------|--------|
| CS#11292 | Status=`cancelled`; Note contains `superseded-by:16951 #173 duplicate_after_renewal` |
| CS#16951 | Unchanged |
| session_corrections | Open row: session_id=11292, replaced_by=16951, reason=`duplicate_after_renewal`, ref=`in-app #173`, rolled_back_at=NULL |
| LR#8883 / #9959 | Unchanged (VoidedAt null; ClassSessionID unchanged) |
| SC#114 / #2076 Used/Rem | Unchanged 8/0 and 7/1 |
| Invoice #137 / #936 | Unchanged |

---

## Business invariants

1. Same calendar slot 2026-06-10 19:00 has **exactly one** live `attended|completed` ClassSession for this student/subject line (keeper #16951).
2. No hard DELETE of ClassSession / LearningRecord / Invoice.
3. No change to PaidAmount / Invoice Status / reconciled amounts.
4. No UsedSessions / RemainingSessions / Charge / Paid mutation on SC#114 or SC#2076.
5. No LearningRecord void or ClassSessionID rebind.
6. Audit row exists and is rollback-capable via `rolled_back_at` + Status restore.

---

## Records explicitly excluded

- SC#996, SC#2396 (other courses; decision packet §1)
- Any ClassSession other than 11292/16951
- Any LearningRecord other than 8883/9959 (read-only)
- All Invoice / Payment rows
- Trust / Day0 / E-OPS experiments

---

## Dry-run output

- **Run:** Actions `29633309003` (2026-07-18T06:02:46Z)
- **Result:** SUCCESS; WOULD supersede 11292; counters match expected
- **Abort if re-dry-run differs:** different IDs, LR≠8883/9959, counters≠8/0|7/1, slot mismatch, StudentClassID mismatch, ALREADY APPLIED when first execute expected

---

## Backup / restore point

- Workflow execute path: `mysqldump` tables  
  `ClassSession StudentClass LearningRecord Invoice Payment session_corrections`  
  → `/home/admin/backups/emergency/db_pre_173_supersede_<TS>.sql.gz`
- Command snapshot JSON: `storage/app/repair-snapshots/173-supersede-<TS>.json`
- Restore: import dump **or** `repair:supersede-renewal-session --case=173 --rollback --execute --force` (+ `ALLOW_PROD_REPAIR=1`)

---

## Rollback query / mechanism

```bash
export ALLOW_PROD_REPAIR=1
php artisan repair:supersede-renewal-session --case=173 --rollback --execute --force
# Restores ClassSession#11292.Status from session_corrections.previous_status
# Sets session_corrections.rolled_back_at
```

Note tag may remain after rollback (known low gap; full Note restore via dump if required).

---

## Executor / independent verifier

| Role | Who |
|------|-----|
| Executor | GitHub Actions workflow_dispatch `mode=execute` confirm `I_APPROVE_173_SUPERSEDE_B` |
| Independent verifier | Separate Agent context — verdict **APPROVE_FOR_EXECUTE** (2026-07-18) |
| Founder | Decision 1 approved Option B with Data Repair Gate |

---

## Execution timestamp window

- **Allowed window:** 2026-07-18 14:00–18:00 Asia/Taipei (same calendar day as dry-run)
- **Abort outside window** unless Founder re-approves
- Record actual execute Actions run URL in post-exec section below after run

---

## Production revision

- Dry-run / execute must run against Pi backend that already has `RepairSupersedeRenewalSession` + `session_corrections` (proven by successful dry-run on prod host).
- Frontend `version.json` may lag; do **not** use version.json alone as gate for this R3 repair.

---

## Idempotency strategy

- Re-run detects open correction + cancelled 11292 + replaced_by=16951 → **noop** (no second write).
- Abort if noop unexpectedly before first authorized execute.

---

## Abort thresholds

1. Dry-run exit ≠ 0 or preflight error (missing table/sessions/SC/slot).
2. Affected write count ≠ 1 session update (unless noop after prior authorized apply).
3. preserved_lr ≠ 8883 or keeper_lr ≠ 9959.
4. Counters ≠ SC114 8/0 or SC2076 7/1.
5. Evidence diverges from Option B (e.g. keeper should be 11292) → **stop; new Founder Decision** — do not switch to A.
6. Concurrent repair (workflow concurrency group `repair-173-supersede`).

---

## Transaction

- Apply wrapped in `DB::transaction` with `lockForUpdate` on session 11292.
- Workflow backup precedes execute outside app transaction (acceptable: dump then apply; rollback command + dump for recovery).

---

## Audit trail

- `session_corrections` row (structured)
- ClassSession.Note tag containing `#173`
- Snapshot JSON on execute
- Actions log + mysqldump path

---

## Post-execution (fill after execute)

| Field | Value |
|-------|-------|
| Execute Actions run | _pending_ |
| Backup path | _pending_ |
| Snapshot path | _pending_ |
| Post-invariant JSON | _pending_ |
| In-app #173 lifecycle | _pending_ |

---

**LOCK:** Selection set is `C173` constants only. Any expansion requires a new Manifest ID and Founder Decision.
