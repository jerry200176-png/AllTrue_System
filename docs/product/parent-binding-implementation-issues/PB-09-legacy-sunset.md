# PB-09 — Legacy sunset (KPI gate)

| Field | Value |
|-------|-------|
| Phase / Risk | 3 / T3 |
| Issue | [#1445](https://github.com/jerry200176-png/AllTrue_System/issues/1445) |
| Depends | PB-08 + **Founder re-approval after gate** |
| Board | backlog / blocked — **no hard calendar date** |

**Scope:** `legacy_bind=false` only after gate; legacy path→use code/contact school; optional staff proxy BindingRequest; update help/LineIntegration/`PARENT_UPDATES` if parent-visible; evidence pack.

**Sunset gate (all):** pairing+request ≥80% × 30d; support&lt;10%; no open P0/P1 identity/PII/cross-campus; revoke/session+rollback verified; Founder re-approves; **no auto hard date**.

**Non-scope:** Delete history; force OTP; drop phone fields; auto-sunset without Founder.

**AC:** Gate evidence on PR; anon legacy cannot create GSR; existing GSR OK; emergency flag re-on tested; support playbook published.  
**Tests:** legacy rejected; pairing works; flag re-on.  
**Rollback:** re-enable `parent_binding_legacy_bind` immediately.
