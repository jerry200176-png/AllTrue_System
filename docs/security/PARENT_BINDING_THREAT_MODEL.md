# Parent Binding Threat Model

| Field | Value |
|-------|-------|
| Status | **ADR Accepted** (Founder 2026-07-26) — no production code |
| Related | Benchmark · ADR |

Safe copy is **necessary but insufficient** — need credential lifecycle, RL, audit, campus isolation, revoke, observability.

**Assets:** Student PII; ParentIdentity; GSR; Pairing raw (critical — hash only at rest); ParentSession hash; BindingAttempt/Inbox (no full phone).

**Actors:** legit parent; enumerator; outsider/brute; compromised LINE; insider director; webhook replay.

**Current surface:** LINE bind (no audit/throttle; name in fail copy); Portal login (`throttle:5,10`; global name; empty-phone 401 leak); login-line via verified SLB; unbind ≠ expire sessions; student delete purges sessions but **not** SLB.

## Threat catalog

Score = Likelihood × Impact (1–5). Detectability 1=easy…5=blind.

| ID | Threat | L | I | Det | Existing | Proposed / residual |
|----|--------|---|---|-----|----------|---------------------|
| T1 | Existence enumeration | 4 | 3 | 4 | campus LINE; login throttle; some 404 | Uniform safe copy; internal reason; webhook RL; never confirm to anon → Low–Med |
| T2 | Phone guessing | 3 | 5 | 4 | normalize; login RL | Legacy degrade+RL; pairing primary; attempt lockout → Med until sunset |
| T3 | Same-name wrong bind | 3 | 5 | 3 | Portal 409; **LINE first-win** | Fail-closed `AMBIGUOUS_MATCH`; code/approval → Low |
| T4 | Cross-campus leak | 3 | 4 | 3 | LINE campus; historical cleanup | Campus-scoped login or kill; credential+GSR campus → Low |
| T5 | Unauthorized guardian | 3 | 5 | — | phone gate; verified_at | Lifecycle; revoke on transfer; re-verify on phone change → Med |
| T6 | Code forward/screenshot | 4 | 4 | 4 | N/A | **Founder:** max_uses=1; TTL default 7d (24h/72h/7d); no permanent; revoke/regen; active cap 4; “勿轉傳” → Med |
| T6b | Cap exhaustion / staff spam | 2 | 2 | — | — | Max 4 active unused; `ACTIVE_CREDENTIAL_CAP` → Low |
| T7 | Token replay | 3 | 4 | — | — | Atomic consume `UPDATE … WHERE use<max AND revoked NULL AND expires>now` → Low |
| T8 | Brute-force codes | 3 | 5 | — | — | ≥128-bit link secret; short-code entropy; RL+backoff+lockout → Low–Med |
| T9 | Full phone in logs | 4 | 4 | — | sparse bind logs | Mask only; CI PII scan → Low |
| T10 | LINE chat retains phone | 5 | 3–4 | — | flow requires phone | Pairing primary; legacy sunset → Med until sunset |
| T11 | Over-privileged director | 2 | 4 | — | role+require_campus | Campus issue/revoke; audit; confirm; SA separate → Low–Med |
| T12 | Revoke/session gaps | 3 | 4 | — | delete one SLB | **Founder:** revoke → **immediate** ParentSession invalidate + tests → Low |
| T12b | Self-serve request enum/spam | 3 | 3 | — | — | Auth LINE; safe generic; RL; dedupe; masked evidence; Inbox cooldown → Low–Med |
| T13 | Alumni still accessible | 3 | 3 | — | status ignored | graduated/inactive → read_only 365d → suspended → policy |
| T14 | Orphan after delete/merge | 3 | 3 | — | delete ≠ clear SLB | CASCADE/purge + orphan job → Low |
| T15 | Race duplicate GSR | 2 | 2 | — | SLB UNIQUE | UNIQUE active (parent,student) + txn → Low |
| T16 | Webhook retry dupes | 3 | 2 | — | already-bound | Event dedupe; idempotent audit → Low |
| T17 | IDOR APIs | 2 | 5 | — | campus checks | Contract tests; inspect no PII → Low |
| T18 | Campus boundary bypass | 3 | 5 | — | middleware | Always carry campus_id; explicit cross-campus switch → Low |

## Abuse → control / gates

```
Anon probe → safe copy + RATE_LIMITED + no inbox spam
Ambiguous names → AMBIGUOUS_MATCH + pairing
Forwarded code → TTL + max_uses=1 + revoke + audit
Missing phone → completeness UI + pairing (phone not sole authz)
Student leaves → read_only/suspend + session expiry
```

| Myth | Reality |
|------|---------|
| Safe copy = done | Still need RL, fail-closed, credentials |
| Hide “not found” only | Same |
| OTP auto-safer | Fails when phone missing/wrong |

**Acceptance gates:** (1) anon fails indistinguishable for NOT_FOUND/MISMATCH/MISSING (2) raw token never DB/log/Inbox (3) atomic consume (4) staff mutate campus+audit (5) tests: enum/IDOR/cross-campus/concurrent/replay (6) CI log PII scan.
