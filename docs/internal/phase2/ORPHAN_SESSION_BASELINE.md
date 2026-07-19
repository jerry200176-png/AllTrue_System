# Orphan / Stranded Session Baseline (read-only classification)

**Task:** #1319 · **Epic family:** #1062 · **Date:** 2026-07-19  
**Scope:** Classification only — **no production writes**, no bulk repair.

## Terminology (critical)

The CEO/Pi Health metric **`orphan_session_count: 1681`** (Phase 1 production evidence, Pi Health run `29667563517`, 2026-07-19) is **not** TD-016 “Stop=1 scheduled orphans.” It is mapped from `sessions:audit-stranded` → **`stranded_sessions`**: unused prepaid sessions on active count-mode courses with **no upcoming** `ClassSession`.

| Label in ops JSON | Actual meaning | Command |
|---|---|---|
| `orphan_session_count` | Stranded **prepaid session units** (#1062) | `php artisan sessions:audit-stranded --json` |
| `orphan_course_count` | Stranded **courses** (same audit) | same |
| TD-016 “orphan” | Stop=1 courses with future `scheduled` rows | `php artisan fix:orphan-scheduled-sessions --dry-run` |
| Sign-in “orphan” | Open `StudentSignIn` / `TeacherSignIn` without sign-out | `student-signin:close-orphans`, `teacher-signin:close-orphans` |

This report classifies the **#1062 / 1681 family** and related cohorts. Local `php artisan` dry-runs were **not executed** (no PHP/DB in task worktree); counts below cite production snapshots and runbooks unless noted.

---

## Production anchor (high confidence)

```json
{
  "reconciliation_baseline": {
    "status": "amber",
    "orphan_session_count": 1681,
    "unresolved_billing_divergence_count": 24
  }
}
```

Source: Phase 1 Pi Health / `scheduler:evidence-summary` → `SchedulerEvidenceSummary::reconciliationBaseline()` maps `orphan_session_count` from nightly `sessions:audit-stranded --json`.

Related system estimate: CLAUDE.md G-010 cites **~2,000** prepaid sessions systemically stranded before guards; campus 3 (大直) alone had **372** sessions / **29** courses at #1062 filing.

---

## Cohort table

| Cohort | Count (sessions / contracts / groups) | Financial exposure | Affected users (est.) | Confidence | Proposed handling | Founder approval? |
|---|---|---|---|---|---|---|
| **Active operational risk** (stranded prepaid, recent activity, forward-gen eligible) | **~400–550 sessions** / **101 contracts** (2026-07-10 PCR scope) | ~NT$400k–800k (rate × remaining; campus-mixed) | ~80–101 students | **Medium** (PCR dated; total pool 1681 is fresher) | CEO GO → `sessions:generate-forward --dry-run` then branch-scoped execute per [`1062-track-a-pcr.md`](../../runbooks/1062-track-a-pcr.md) | **Yes** — revenue-affecting calendar writes |
| **Inactive / dormant** (prepaid, no session in ≥3 weeks) | **~275 contracts** (PCR out-of-scope) · **127 students / ~NT$1,006,150** recoverable (Decision Center, #1152) | NT$1.0M+ prepaid service value (not new revenue) | 127 students | **High** for $/headcount (#1152); **Medium** for session split vs 1681 | Director outreach pilot (東湖+石牌 114 students); **no auto forward-gen** | **Yes** for pilot policy (#1152); per-family for refunds |
| **Cadence-derived** (active stranded but no confirmed weekly slot) | **~50–150 contracts** (est.: 101 eligible − unknown cadence failures) | Unknown until dry-run skip reasons | Unknown | **Low** | Manual director: confirm weekday/time, then forward-gen or mark dormant | No for classification; **Yes** if bulk generate after cadence fix |
| **Duplicate-derived** (cross-SC same slot) | **72 groups** (#1130); **~7** P1 ghost; **63** P2 review; `cross_sc_duplicate_attended_slot` nightly | Indirect billing/attendance risk | Tens of students (multi-contract families) | **Medium** | P1: `repair:duplicate-sessions --case=p1-ghost` (CEO GO); P2: director review (#1134); scheduled-cross-sc repair package | **Yes** for execute paths |
| **Cross-campus** (duplicate / projection / fetch dedupe) | Subset of duplicate cohort + API N+1 (#1047) | Low direct $; high ops trust | Teachers on multi-campus home | **Medium** | Merge #1047 fixes; dedupe in attendance (`student_id\|date\|start`) — largely shipped 2026-07-18 | No for read-only; **Yes** for production repair PRs |
| **Billing-linked** (reconcile mismatch, not stranded calendar) | **24** unresolved divergences (Pi Health) | Case-by-case; #1096 cites NT$0/2998 vs 3000 patterns | Small (known cases e.g. 洪子勛) | **High** for count; **Medium** for $ | `reconcile:nightly` triage; manual invoice/SC fixes per #1096/#959 | **Yes** for historical billing repair |
| **Valid historical records** (completed/cancelled; no forward obligation) | Not part of 1681 audit (excluded by query) | $0 incremental | — | **High** | No action | No |
| **TD-016 orphan scheduled** (Stop=1 + future scheduled) | **Unknown** (not in 1681 metric) | Low | Low | **Low** (no local dry-run) | `fix:orphan-scheduled-sessions --dry-run` on Pi; cancel only after review | **Yes** if bulk cancel |
| **Unknown / residual** | **~700–900 sessions** (1681 minus active+dormant splits; overlap likely) | Unknown | Unknown | **Low** | Re-run `sessions:audit-stranded --json` + `ops:business-digest` on Pi; export skip reasons from `sessions:generate-forward` dry-run | No until classified |

---

## Audit commands & digests (read-only)

| Command | Purpose | Writes? |
|---|---|---|
| `sessions:audit-stranded [--json] [--branch_id=]` | #1062 stranded prepaid sessions/courses | No |
| `sessions:generate-forward [--dry-run]` | Plan forward materialization (#1062 Track A) | Dry-run: no |
| `ops:business-digest` | Revenue + data-quality aggregates (`stranded_sessions`, `orphan_stop_scheduled`, `scheduled_cross_sc`) | No |
| `bugs:verify-reproductions [--json]` | Nightly divergence counts (stranded, duplicates) | No |
| `scheduler:evidence-summary` | Pi Health JSON incl. `orphan_session_count` | No |
| `fix:orphan-scheduled-sessions --dry-run` | TD-016 Stop=1 scheduled orphans | Dry-run: no |
| `repair:duplicate-sessions --case=…` | #1130 duplicate classification/repair | Dry-run: no |
| `learning-records:drift-check` | LR orphan ClassSession IDs | No |

Docs: [`1062-track-a-pcr.md`](../../runbooks/1062-track-a-pcr.md), [`1130-p1-ghost-pcr.md`](../../runbooks/1130-p1-ghost-pcr.md), [`2026-07-18-scheduled-cross-sc-execution-package.md`](../../incidents/2026-07-18-scheduled-cross-sc-execution-package.md), CLAUDE.md G-010, `BusinessDigestService` decision-center copy.

---

## Recommended Phase 2 sequence (classification → gated repair)

1. **Refresh counts on Pi** (read-only): `sessions:audit-stranded --json`, `ops:business-digest`, `bugs:verify-reproductions --json`.
2. **Split 1681** using forward-gen dry-run skip reasons → active / cadence / dormant buckets.
3. **Do not bulk-repair** all 1681 in one command (Phase 2 Part 10 prohibition).
4. **Parallel tracks:** Track A forward-gen (101 contracts, CEO GO) vs dormant outreach (#1152) vs duplicate PCR (#1130).

---

## Summary counts (for CEO contract)

| Metric | Value | Confidence |
|---|---|---|
| Stranded prepaid **sessions** (Pi Health `orphan_session_count`) | **1681** | High |
| Stranded **courses** (companion field when present) | **~350–400** (est. from 1681 ÷ ~4–5 remaining) | Low |
| Active forward-gen **contracts** (PCR 2026-07-10) | **101** | Medium |
| Dormant prepaid **students** (#1152) | **127** (~NT$1,006,150) | High |
| Billing reconcile **divergences** | **24** | High |
| Cross-SC duplicate **groups** (#1130) | **72** | Medium |
| Local dry-run executed this task | **0** | — |

**Overall classification confidence:** **Medium** — production total is firm; sub-cohort splits need Pi dry-run exports.
