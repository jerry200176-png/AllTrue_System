# PB-04 — Relationship model

| Field | Value |
|-------|-------|
| Phase / Risk | 2 / T3 |
| Issue | [#1440](https://github.com/jerry200176-png/AllTrue_System/issues/1440) |
| Depends / Blocks | PB-00 (hard-block **lifted** Founder GO 2026-09-03) / PB-05,06,07 |
| Board | **PARTIAL / IN PROGRESS** — identity tables + staff CRUD + portal dual-read authZ (no new ParentIdentity schema) |

**Canonical mapping (do not duplicate):** `guardians` ≈ ParentIdentity; `student_guardians` ≈ GSR. SLB remains projection / dual-read fallback. Legacy `parent_phone` retained (no cutover in this slice).

**Scope (this slice):** create/revoke/list (staff, flag-gated); portal session auth via guardian links when `PERF_MULTI_GUARDIAN` on; states include pending/active/read_only/suspended/revoked; **active/read_only grant access**; **revoke→immediate ParentSession invalidate** (LINE subject); campus on link + preferred campus order; multi-guardian / multi-child.  
**Deferred:** paused/graduated→read_only **365d**→suspended automation; pairing; OTP; OpenFGA; drop SLB; phone auto-merge; `parent_phone` removal.

**AC:** Access needs active/read_only when flag on; revoke kills sessions; campus-aware; no billing/leave files; flag off → verified SLB auth.  
**Tests:** multi-child login path; read_only vs suspended; revoke→session; revoked wins over stale SLB; flag-off SLB fallback.  
**Rollback:** `PERF_MULTI_GUARDIAN=false` → SLB-only portal auth; keep tables.
