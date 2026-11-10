# EPIC #957 — Unified ClassSession Materialization

> **Tier:** T3 (scheduling / attendance / billing)  
> **Owner:** `[ARCH]` → `[DEV]` backend  
> **Canonical issue:** GitHub #957  
> **Status:** **In progress** — Phases A–C landed on `main`; Phase D + unique index remain

**Last revised:** 2026-06-28 (offline audit)

---

## Problem

Duplicate/wrong `ClassSession` rows corrupt attendance, LR binding, calendar, and billing alerts. Root cause was fragmented `ClassSession::create` call sites without a unique slot index.

**Absorbed issues (closed):** #932, #933, #958, #960, #961, #962, #963, #969, #965

---

## Target architecture

```
StudentClass / schedules (intent)
        ↓
ClassSessionMaterializationService::upsertSlot()
        ↓ lockForUpdate + idempotent upsert
ClassSession (materialized truth)
        ↓
Attendance / LR / Deduction / Calendar (read by session ID)
```

**Slot key:** `(StudentClassID, SessionDate, StartTime)` — DB unique index still pending.

---

## Progress on `main` (DONE)

| Item | Status | Evidence |
|------|--------|----------|
| Single write authority | **DONE** | `ClassSessionMaterializationService::upsertSlot` — only `ClassSession::create` in `app/` |
| Controller migration | **DONE** | All 10 production write paths route through service |
| Duplicate audit command | **DONE** | `AuditClassSessionDuplicates` (`classsession:audit-duplicates`) |
| Materialized/projected API split | **DONE** | `ClassSessionController::index` returns both + legacy aliases |
| Count-mode same-day gap | **IN PR** | Rebuilt branch `fix/count-mode-on-materialization-service` (supersedes stale #937) |

---

## Remaining work (Phase D + hardening)

### D1 — Unique slot index (P1, DBA gate)

- [ ] Run `classsession:audit-duplicates` on production backup; merge/cleanup dupes
- [ ] Migration: unique index on `(StudentClassID, SessionDate, StartTime)` with rollback plan
- [ ] Concurrency feature test: parallel `upsertSlot` → one row

**Tracks:** #957 sub-task (open issue or checklist on epic)

### D2 — ApprovalSessionSync resolver (P1)

- [ ] Fix `ApprovalSessionSyncService::resolveClassSession` — no `orderBy id desc` fallback across ambiguous duplicates
- [ ] Deterministic slot match on `(StudentClassID, SessionDate, StartTime)`

### D3 — Payment truth alignment (P1)

- [ ] Align `AlertController::tuition` with G-009 OR-invoice logic
- [ ] **Canonical issue:** #959

### D4 — Calendar dedupe (P2)

- [ ] `calendarOccurrenceMerge.js` dedupe key includes `student_course_id` (#961 behavior)

### D5 — Schedule destroy orphans (P2)

- [ ] `ScheduleController.destroy` cascade or soft-delete materialized sessions (#963)

---

## Acceptance criteria (revised)

1. ~~Zero `ClassSession::create` outside service~~ **MET on main**
2. Unique index enforced; duplicate audit returns 0 rows post-cleanup — **OPEN**
3. Count-mode same-day materialize (#937) via `extendSessionsIfNeeded` — **PR pending**
4. LR approval binds to correct session when duplicates existed — **OPEN (D2)**
5. Director tuition alerts match course list paid status — **OPEN (#959)**

---

## Branch naming (remaining)

```
feat/957-unique-slot-index
fix/957-approval-session-sync-resolver
fix/959-alert-tuition-payment-truth
fix/count-mode-on-materialization-service  ← active (replaces stale #937)
```

---

## Risks

| Risk | Mitigation |
|------|------------|
| Unique index fails on existing dupes | Audit + cleanup before migration (D1) |
| Package/shared-pool courses (#162) | Count auto-materialize skips `PackageID>0` |
| Stale PR #937 merge | **Do not merge** — use rebuilt branch only |
