# PB-04 — Relationship model

| Field | Value |
|-------|-------|
| Phase / Risk | 2 / T3 |
| Issue | [#1440](https://github.com/jerry200176-png/AllTrue_System/issues/1440) |
| Depends / Blocks | PB-00 / PB-05,06,07 |
| Board | backlog / blocked |

**Scope:** Migrations ParentIdentity + GSR; create/revoke/list; session auth via GSR when flag on; states pending/active/read_only/suspended/revoked; paused=keep access; graduated/inactive→read_only **365d**→suspended; **revoke→immediate ParentSession invalidate**; campus-scoped; UI student+campus; SLB remains projection.  
**Non-scope:** Pairing UI; OTP; OpenFGA; drop SLB; phone auto-merge.

**AC:** Access needs active/read_only when flag on; unique under concurrency; revoke kills sessions; status transitions; campus authZ; no billing/leave files.  
**Tests:** create/revoke/concurrent/cross-campus/multi/session/read_only; CI migrations.  
**Rollback:** flag off → verified SLB auth; keep tables.
