# PB-00 — Observability & reason codes

| Field | Value |
|-------|-------|
| Phase / Risk | 0 / T1 |
| Board | **in progress / PR** · [#1436](https://github.com/jerry200176-png/AllTrue_System/issues/1436) |
| Blocks | PB-01,02,03 |
| Status | Code landed — observability only; identity security **not** complete |

**Scope:** stable `reason_code` on LINE+Portal failures; PII-safe `parent_binding_attempts`; correlation id; flag; KPI + missing-contact report.  
**Non-scope:** OTP; copy/success-path change; pairing/Inbox/GSR; PB-01～09.

**Rollback:** `PARENT_BINDING_OBSERVABILITY=false` (do not drop table first-line).  
**Contract:** [`../PARENT_BINDING_REASON_CODES.md`](../PARENT_BINDING_REASON_CODES.md) · Rollout [`../../operations/PARENT_BINDING_ROLLOUT.md`](../../operations/PARENT_BINDING_ROLLOUT.md)

Still pending: PB-01 safe copy · PB-02 completeness UI · PB-03 Inbox · pairing/GSR not started.
