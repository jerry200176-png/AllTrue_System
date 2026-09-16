# Program status — RFID / #2809

**Epic:** [#2809](https://github.com/jerry200176-png/AllTrue_System/issues/2809)  
**Canonical policy:** [`docs/architecture/RFC_RFID_CAMPUS_PRESENCE_V1.md`](../architecture/RFC_RFID_CAMPUS_PRESENCE_V1.md)  
**Owner session:** agent task `RFID-2809-0-policy-contract` (and successors)

Update this file on every delivery. Do not collapse coded / PR / CI / merged / deployed / runtime-verified.

---

## Locked policy (short)

RFID = campus presence only. Teacher/manual attendance remains course-attendance + deduction authority. Raw swipe must never mark session attended/late, deduct, mutate counters, or billing-backfill.

---

## Delivery log

| When (UTC) | Slice | State | Evidence |
|---|---|---|---|
| 2026-09-16 | Planning | accepted | Issue comments + Founder locked v1 policy in session |
| 2026-09-16 | **RFID-0** | merged | PR [#2975](https://github.com/jerry200176-png/AllTrue_System/pull/2975) merge `c9c219a895237c5783b449216b3ddec152b19c50` |
| 2026-09-16 | **RFID-1** | coded (this branch) | `StudentCampusPresence` + service + read APIs |

---

## Current slice

**RFID-1** — presence data model + read APIs. Does **not** re-boundary `SwipeRfidController` yet.

### RFID-1 checklist

- [x] Additive `StudentCampusPresence` migration
- [x] Lifecycle service (arrive/depart/orphan/candidates) with no SDS/effects imports
- [x] Read APIs: open / student today / candidates
- [x] Feature tests for lifecycle + invariant
- [ ] PR opened
- [ ] CI green
- [ ] Merged
- [ ] Deployed
- [ ] Runtime verified (presence table exists; swipe behavior unchanged until RFID-2)

---

## Next selected task

**RFID-2** — Re-boundary `SwipeRfidController` behind `FEATURE_RFID_PRESENCE_ONLY` to write presence only (no deduct/effects/backfill).

---

## Remaining blockers

| Blocker | Severity | Notes |
|---|---|---|
| Production still auto-deducts on swipe | High (known) | Fence in RFID-2 behind flag |
| Canary campus id unset | Medium | Needed only to turn flag on in production |
| Dual pipeline `attendance/swipe` + PendingSwipe | Low | Quarantine; do not expand |

---

## Slice backlog

1. RFID-0 docs/contracts — **merged**
2. RFID-1 presence model + API — **in progress**
3. RFID-2 SwipeRfidController re-boundary (flag)
4. RFID-3 card onboarding/audit
5. RFID-4 teacher evidence UI
6. RFID-5 parent presence semantics
7. RFID-6 device health/reliability
8. RFID-7 optional automation (default off)
