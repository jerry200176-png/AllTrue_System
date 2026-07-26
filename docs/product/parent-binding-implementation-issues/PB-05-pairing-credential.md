# PB-05 — Pairing credential

| Field | Value |
|-------|-------|
| Phase / Risk | 2 / T3 |
| Issue | [#1441](https://github.com/jerry200176-png/AllTrue_System/issues/1441) |
| Depends / Blocks | PB-04 / PB-07,08 |
| Board | backlog / blocked |

**Scope:** `pairing_credentials` hash-only; **max_uses=1**; TTL default **7d** options **24h/72h/7d**; no permanent/extend; active unused cap **4**; per-guardian codes; issue/regen/revoke (raw once); atomic consume; LINE/LIFF/QR; RL+lockout.  
**Non-scope:** OTP; shared multi-use default; full Portal rewrite.

**AC:** No raw in DB/logs; concurrent consume ≤1; CODE_* + inspect no PII; no cross-campus issue; 5th→`ACTIVE_CREDENTIAL_CAP`.  
**Tests:** hash/expiry/cap; issue/consume/revoke/replay/IDOR/concurrent; log PII scan.  
**Rollback:** `parent_binding_pairing=off`.
