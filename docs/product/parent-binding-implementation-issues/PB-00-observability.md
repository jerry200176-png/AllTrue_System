# PB-00 — Observability & reason codes

| Field | Value |
|-------|-------|
| Phase / Risk | 0 / T1 |
| ADR | https://github.com/jerry200176-png/AllTrue_System/pull/1434 |
| Board | **ready / next** · [#1436](https://github.com/jerry200176-png/AllTrue_System/issues/1436) |
| Depends / Blocks | — / PB-01,02,03 |

**Scope:** Structured `reason_code` on LINE+Portal bind failures; masked phone logs; optional `binding_attempts` or structured logs; KPI baseline queries; missing-contact report.  
**Non-scope:** OTP; success-path/copy change; pairing/Inbox/GSR schema.

**AC:** (1) All fail paths record ADR reason codes (2) no full phones in logs (3) success behavior unchanged (4) ops can query top reasons + missing contact.  
**Tests:** reason mapping unit; feature fail→internal codes; no `\d{10}` in log fixtures.  
**Rollback:** `parent_binding_observability=off`.
