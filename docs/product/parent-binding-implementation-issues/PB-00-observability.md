# PB-00 — Observability & reason codes

| Field | Value |
|-------|-------|
| Phase / Risk | 0 / T1 |
| ADR | https://github.com/jerry200176-png/AllTrue_System/pull/1434 |
| Board | **in progress / PR** · [#1436](https://github.com/jerry200176-png/AllTrue_System/issues/1436) |
| Depends / Blocks | — / PB-01,02,03 |
| Implementation | **Code landed (this PR)** — observability only; identity security **not** complete |

**Scope:** Structured `reason_code` on LINE+Portal bind/login failures; PII-safe structured logs; append-only `parent_binding_attempts` table; KPI report + missing-contact report; feature flag rollback.  
**Non-scope:** OTP; success-path/copy change; pairing/Inbox/GSR schema; PB-01～PB-09 product work.

**AC:** (1) All fail paths record ADR reason codes (2) no full phones in logs/table (3) success behavior unchanged (4) ops can query top reasons + missing contact.  
**Tests:** reason mapping unit; feature fail→internal codes; success-path parity; fail-open; no raw PII; artisan reports.  
**Rollback:** `PARENT_BINDING_OBSERVABILITY=false` (config `parent_binding.observability_enabled`) stops writes; do **not** drop table as first-line rollback.

## Status note

PB-00 only provides observability. It does **not** mean identity security is done:

- External fail copy still pending **PB-01**
- Completeness UI still pending **PB-02**
- Inbox workflow still pending **PB-03**
- Pairing / ParentIdentity / GSR **not started**

## Ops

```bash
php artisan parent-binding:report --days=7 --format=json
php artisan parent-binding:missing-contact --format=json
php artisan parent-binding:missing-contact --campus=15 --format=json
```

Reason-code contract: [`docs/product/PARENT_BINDING_REASON_CODES.md`](../PARENT_BINDING_REASON_CODES.md)  
Rollout / flag: [`docs/operations/PARENT_BINDING_ROLLOUT.md`](../../operations/PARENT_BINDING_ROLLOUT.md)
