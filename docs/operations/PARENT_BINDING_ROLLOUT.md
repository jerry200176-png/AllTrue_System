# Parent Binding Rollout

| Field | Value |
|-------|-------|
| Status | **ADR Accepted** (Founder 2026-07-26) — plan only; no prod code this round |
| OTP | **Not in Phase 0–2** |

Success = KPIs, not “shipped”. Expand-contract; flags+rollback; CI→PR→merge→deploy; no schedule/billing/leave mix-in.

## Phases

| Phase | Goal | Flags | Changes | Exit |
|-------|------|-------|---------|------|
| **0 Observability** | Baseline failures; **no** success-path change | `observability=on` | reason_code + masked attempts/logs; missing-phone report | ≥7d baseline; Founder sees missing % |
| **1 Safe UX + completeness** | Less enum; staff tools; success still name+phone | `safe_copy`, `completeness_ui`, `inbox_v1` | Safe fail copy; fix Portal empty-phone leak; filters; Import/Wizard `parent_phone`; high-signal Inbox | Support misdiagnosis↓ 14d; UI used |
| **2 Pairing + request + GSR** | Primary credential; legacy fallback | `pairing`, `requests`, `legacy_bind=on` | Migrations+backfill; issue/consume/approve; dual-write SLB | Pairing ≥50% new (or Founder); no P0 wrong-bind; revoke kills session |
| **3 Legacy sunset** | Name+phone not default | `legacy_bind=false` **only after gate** | Guide to code; orphan cleanup | Gate + Founder re-approval |

### Sunset gate（all required; **no hard date**）

1. pairing+BindingRequest ≥ **80%** of new binds  
2. **30 consecutive days**  
3. legacy support/remediation **< 10%**  
4. no open **P0/P1** identity/PII/cross-campus  
5. revoke→session + migration rollback **verified**  
6. **Founder re-approves**  

## Flag matrix

| Flag | P0 | P1 | P2 | P3 |
|------|----|----|----|-----|
| observability | on | on | on | on |
| safe_copy / completeness / inbox | off | on | on | on |
| pairing / requests | off | off | on | on |
| legacy_bind | on | on | on | **off** |

## Verify / support / KPI

Post-merge: `GET /api/v1/health`; version.json if FE; smoke director+parent paths. Never claim done pre-CI/merge.

| Parent issue | Director |
|--------------|----------|
| Fail | Check missing phone / existing code |
| No code | Issue code privately |
| Expired | Regenerate |
| Ex-partner access | Revoke; confirm session dead |
| Transfer | Revoke old campus; issue at new |
| Suspected guess-bind | Revoke+reissue; shorten TTL; check attempts |

| KPI | Dir |
|-----|-----|
| Bind success / first-try / time-to-bind | ↑ / ↑ / ↓ |
| Missing contact; pending age; wrong-bind revokes; AMBIGUOUS; expired unused; support rate; unattributable | ↓ |
| Director handle volume | ↑ then ↓ |
| Rate-limit / cross-campus deny / dupes prevented | monitor |

**Not KPI:** PR merge / deploy green alone / doc pages.

| Risk | Mitigation |
|------|------------|
| Staff won’t issue codes | Train; simple TTL; desk script |
| Legacy parallel | Flags + exit gate |
| Backfill miss | CI audit SLB↔GSR |
| Inbox noise | Dedupe/cooldown |
| OTP creep | Out of Phase 0–2 |
