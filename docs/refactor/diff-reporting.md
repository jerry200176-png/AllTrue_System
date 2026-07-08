> **HISTORICAL CONTEXT ONLY**
> This document does **not** override [`CONTROL_PLANE_CONTRACT.md`](../CONTROL_PLANE_CONTRACT.md) (I1–I5).

# Diff Reporting — Refactor Divergence Detection

> Phase 4 artifact. Log format, grep examples, and threshold guidance.

---

## Overview

When `REFACTOR_CONSISTENCY_CHECK=true`, append-only controller hooks call `DomainConsistencyChecker` after legacy responses are built. The checker compares legacy output vs shadow read models and **logs only** — never blocks requests.

Default: **disabled** (`REFACTOR_CONSISTENCY_CHECK=false`).

---

## Log Keys

| Key | Level | Meaning |
|---|---|---|
| `REFACTOR_DOMAIN_DIFF` | debug | Single entity mismatch |
| `REFACTOR_MISMATCH_METRICS` | info | Request-level summary counters |
| `REFACTOR_CONSISTENCY_CHECK_ERROR` | warning | Checker internal error (swallowed) |

---

## REFACTOR_DOMAIN_DIFF Structure

```json
{
  "domain": "payment|session|schedule|payment_alert",
  "entity_id": 12345,
  "legacy_hash": "sha256...",
  "shadow_hash": "sha256...",
  "legacy": { "payment_status": "paid" },
  "shadow": { "payment_status": "unpaid" },
  "diff": null
}
```

---

## Metrics (per request summary)

| Metric | Counter |
|---|---|
| `refactor.session_mismatch_count` | Session used/remaining diffs |
| `refactor.payment_mismatch_count` | Payment status diffs |
| `refactor.schedule_mismatch_count` | Schedule date subset diffs |

---

## Grep Examples (Pi / staging)

```bash
# All domain diffs
grep REFACTOR_DOMAIN_DIFF /home/admin/backend/storage/logs/laravel*.log | tail -20

# Mismatch summaries
grep REFACTOR_MISMATCH_METRICS /home/admin/backend/storage/logs/laravel*.log | tail -10

# Payment-only
grep '"domain":"payment' /home/admin/backend/storage/logs/laravel*.log
```

---

## Hook Points (append-only, flag-gated)

| Controller | Method | Compared fields |
|---|---|---|
| `StudentClassController` | `index` | `payment_status`, `UsedSessions`, `RemainingSessions` |
| `StudentClassController` | `sessionDates` | Materialized date subset vs `ScheduleCalendarView` |
| `AlertController` | `tuition` | `payment_status` per alert row (expect diffs vs index OR-logic) |

---

## Threshold Guidance

| Rate | Action |
|---|---|
| 0% | Shadow mirrors aligned for compared fields |
| < 1% | Investigate — may be self-heal or alert vs index dual-truth |
| > 5% sustained | Disable `REFACTOR_CONSISTENCY_CHECK`; file issue; do not promote shadow |
| Any write via command layer | Immediate rollback — see [`rollback-strategy.md`](rollback-strategy.md) |

---

## Expected Diffs (Not Bugs)

- **Tuition alerts vs index payment_status:** Alert uses `computePaymentStatus()` (Paid-only base); index uses OR-logic with invoice payments.
- **Session index vs DB columns:** Index self-heal overrides response without persisting.
- **Session dates full list vs materialized subset:** Shadow compares ClassSession rows only, not full merge.

---

## Related Documents

- [`rollback-strategy.md`](rollback-strategy.md)
- [`phase-report-4.md`](phase-report-4.md)
