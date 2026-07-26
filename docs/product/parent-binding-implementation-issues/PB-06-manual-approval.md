# PB-06 — BindingRequest self-serve

| Field | Value |
|-------|-------|
| Phase / Risk | 2 / T2 |
| Issue | [#1442](https://github.com/jerry200176-png/AllTrue_System/issues/1442) |
| Depends / Blocks | PB-04, PB-03 / PB-08 |
| Board | backlog / blocked |

**Scope:** `binding_requests` + states; **parent self-serve** requires auth LINE ParentIdentity; campus+claimed name; safe generic (no existence leak); RL+dedupe; masked staff evidence; staff proxy OK; approve atomic GSR; Inbox+SLA.  
**Non-scope:** Approval-as-primary for all; auto-approve; OTP; anon submit.

**AC:** Unauth rejected; no existence leak; double-approve idempotent; reject/expire clear pending; SLA elevates Inbox; campus isolation.  
**Tests:** auth/dedupe/approve/reject/IDOR/enum; Inbox once; no full phone.  
**Rollback:** `parent_binding_requests=off`.
