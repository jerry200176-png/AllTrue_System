# ADR: Parent–Student Binding Redesign

| Field | Value |
|-------|-------|
| Status | **Accepted** |
| Date / Founder approval | 2026-07-26 |
| Related | Benchmark · Architecture · Threat · UX · Rollout · PR #1434 |
| Implementation | **PB-00 completed** (#1446); **PB-01–PB-09 not started** — no further DEV without Founder schedule |

## Context

Today: LINE「綁定 姓名 手機」+ Portal name/ID+phone; SSOT `StudentContactPhone` (`parent_phone`→`Phone`).

Gaps: fail copy collapses causes; Portal empty-phone 401 leaks existence; LINE first-match vs Portal 409; Portal name login cross-campus; no staff ops/Inbox; orphan SLB; unbind≠session expire; full phone in LINE chat.

Benchmark: school-issued pairing + staff approval fallback; OTP-first not mainstream.

**Research limitations（UNCONFIRMED）:** ClassDojo/PowerSchool hash; Infinite Campus global single-use; on-site code acceptance.

## Scoring

| Criterion | Weight |
|-----------|--------|
| Security/privacy | 30% |
| Ops executability | 25% |
| Parent UX | 20% |
| Compat/migration | 15% |
| Cost/ops | 10% |

| Option | Weighted | Notes |
|--------|----------|-------|
| **H Hybrid** | **4.45** | **Accepted** |
| B Pairing only | 4.40 | No request fallback |
| A Enhanced name+phone | 3.45 | Insecure |
| C Manual only | 3.40 | Staff load |
| D SMS OTP-first | 3.15 | Out of scope now |

## Decision（Founder Accepted）

**Option H Hybrid:** (1) Primary campus-scoped PairingCredential (code+link/QR) (2) Fallback BindingRequest→director Inbox; **parent self-serve** with authenticated LINE (3) Legacy name+phone controlled fallback + safe copy + ambiguous fail-closed + KPI sunset (**no hard date**) (4) Introduce ParentIdentity / GSR / PairingCredential / BindingRequest / BindingAttempt; SLB as LINE projection (5) **OTP not in Phase 0–2**.

### Founder parameters

| PairingCredential | Decision |
|-------------------|----------|
| max_uses | **1** (per-guardian independent) |
| Active unused cap | **4** / student+campus |
| TTL | Default **7d**; staff **24h/72h/7d** only |
| Permanent / extend | **Forbidden** / **Forbidden** — reissue only |
| Consume | **Atomic** |
| Storage | Hash only; **no raw** in DB/logs |

| Sunset gate (all) | |
|-------------------|--|
| Share | pairing+request ≥ **80%** new binds |
| Window | **30 consecutive days** |
| Support | legacy remediation **< 10%** |
| Incidents | no open **P0/P1** identity/PII/cross-campus |
| Evidence | revoke→session + rollback verified |
| Approval | **Founder re-approval**; **no** auto hard date |

| Student status | Access |
|----------------|--------|
| `paused` | keep normal access |
| `graduated`/`inactive` | **read_only 365d** → then **`suspended`** |
| Staff | may extend read_only / unsuspend (audited) |
| Relationship **revoked** | **ParentSession immediate invalidate** |

| Cross-campus / multi-child | BindingRequest |
|----------------------------|----------------|
| ParentIdentity **may** multi-campus + multi-child | Self-serve OK |
| Each GSR **campus-scoped**; UI **student+campus** | Auth LINE required |
| **No** phone auto-merge; campus/URL cannot bypass authZ | Safe generic; RL; dedupe; masked evidence; staff proxy OK |

## Rejected

| Alt | Why |
|-----|-----|
| Copy-only | Misses wrong-bind/ops/orphan |
| Keycloak/authentik parent IdP | Dual IdP; LINE already primary |
| OpenFGA runtime | App-layer GSR enough |
| Immediate kill name+phone | No migration evidence |
| Second identity truth | Dual truth |
| Shared multi-use default | Rejected — max_uses=1 |
| OTP in Phase 0–2 | Founder |

## Consequences / migration

+: Industry align; less phone in chat; staff workflow; reason≠copy; staged rollback. −: New tables/APIs; staff learn issue-code; extra parent step; legacy parallel via flags. Neutral: SLB projection short-term; OTP future only.

| Phase | Intent |
|-------|--------|
| 0 | Observability |
| 1 | Safe UX + completeness + Inbox |
| 2 | Pairing + request + relationship |
| 3 | Legacy sunset (KPI + Founder; no hard date) |

Validation: [x] Accepted 2026-07-26 · [x] params in siblings · [ ] PB GitHub issues scheduled · [x] no prod code this round.
