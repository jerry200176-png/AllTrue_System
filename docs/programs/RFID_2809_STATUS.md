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
| 2026-09-16 | **RFID-0** | merged (#2975) | RFC + cross-links + this status file; runtime unchanged |
| 2026-09-19 | **RFID-1** | coded → PR (review pending) | Dedicated presence model/API, campus isolation, transaction/unique-open safeguards |

---

## Current slice

**RFID-1** — dedicated presence model and read APIs. This does not activate the
RFID-2 swipe re-boundary or production behavior. Merge and any activation remain
Founder-only gates under the accepted RFC.

### RFID-0 checklist

- [x] RFC accepted architecture (presence domain B)
- [x] Current vs target called out (runtime still auto-deducts until RFID-2)
- [x] Feature flag names reserved in RFC
- [x] INDEX / SYSTEM_TECH_GUIDE / api-swipe-rfid cross-links
- [x] PR opened (RFID-0 / #2975)
- [x] CI green (RFID-0 / #2975)
- [x] Merged (#2975)
- [ ] Deployed (docs-only; N/A beyond merge to main)
- [ ] Runtime verified (N/A — no behavior change)

---

## Next selected task

**RFID-2** — `SwipeRfidController` re-boundary (flag; no production activation in this slice).

Blocked on: nothing for design/schema PR after RFID-0 merge.  
RFID-2 production canary still needs a canary `Campus.id` (defer until flag enable).

---

## Remaining blockers

| Blocker | Severity | Notes |
|---|---|---|
| Production still auto-deducts on swipe | High (known) | Fence in RFID-2 behind flag; do not claim fixed until runtime SHA proves flag path |
| Canary campus id unset | Medium | Needed only to turn flag on in production |
| Dual pipeline `attendance/swipe` + PendingSwipe | Low | Quarantine; do not expand |

---

## Slice backlog

1. RFID-0 docs/contracts — **merged (#2975)**
2. RFID-1 presence model + API — **review pending; no production activation**
3. RFID-2 SwipeRfidController re-boundary (flag)
4. RFID-3 card onboarding/audit
5. RFID-4 teacher evidence UI
6. RFID-5 parent presence semantics
7. RFID-6 device health/reliability
8. RFID-7 optional automation (default off)
