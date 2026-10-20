# Lane coordination — public-safe checkpoint (DRAFT)

Last updated: 2026-10-20T02:20Z  
Shared GitHub ownership issue: [#4070](https://github.com/jerry200176-png/AllTrue_System/issues/4070)  
No secrets, student PII, financial amounts, encrypted dump payloads, or case tuples in this file.

**Status of this file:** prepared DRAFT for review — **not** yet the implemented ownership source of truth until merged to `main` and acknowledged on #4070.

## Ownership (as of Dot/Jerry coordination 2026-10-20)

| Lane | Owner | Role | Must not |
|------|-------|------|----------|
| **#4312** + primary billing / POP mount implementation & queue | **Da’an** | Sole implementation + queue | Other agents edit `chore/pop-mount-chmod644` or queue #4312 |
| Primary Cloud reviewer/coordinator | Cloud task `bc-01a1549a-4b74-7985-95f8-2ac405420cfb` | Review / coordinate | Mutate Da’an-owned branches; invent financial WRITE_GO |
| Local Cursor | **Released — relay only** | Relay evidence / status | Case execution; claim sole case-writer |
| Secondary Goal A–E / product follow-up | This Cloud session (Goal A–E lane) | Non-billing product closeout prep, Track B Dot PASS wait, D/E WRITE_GO wait, docs hygiene | Edit #4312; merge/deploy/approve; financial writes; claim #4320 as #4312 predecessor |

## Checkpoint — live / trains (public)

| Item | Value | Note |
|------|-------|------|
| Live `deployment.json` backend | `ad9e36904283` | DtP `39241462999`; deployed_at `2026-10-19T11:02:17Z` |
| Waiting release train | `39333337571` | **HOLD / DO NOT APPROVE** from this message |
| This message | Coordination only | **Not** financial write GO; **not** production-activation |

## Two separate billing lanes (do not collapse)

### Lane N — narrow unpaid-invoice alignment (runtime already on live pin)

| Field | Content |
|-------|---------|
| Claim (primary review) | Narrow unpaid-invoice-alignment operation is **already deployed** on live backend `ad9e369` |
| Does **not** require | Broad `#4283` schema / allowlist / `agreed_fee_correction_enabled` flag migration path |
| Still required before counting case done | Runtime/state check · authorized tuple · POP · real staff session · fresh acceptance / reporter response (see `EVIDENCE_CONTRACT.md` + product-loop policy) |
| Owner | Da’an (primary billing) + primary Cloud coordinator |
| **Must not** | Hard-gate this lane on tip ≥ `506729c` / `#4283` live |

### Lane B — broad agreed-fee schema / allowlist / flag (`#4283` lineage)

| Field | Content |
|-------|---------|
| Code on tip | `#4283` squash `506729c78ab4` (ancestor of tip; **not** of live) |
| Live | Schema **not** live; dumps may still show `correction_schema_missing` for schema-dependent paths |
| Owner | Da’an + Founder gates for activation / allowlist / flag |
| Next | Only after explicit owner coordination — **not** implied by Lane N |

## #4320 vs #4312 dependency (verified — prepared, not merged)

| Question | Answer |
|----------|--------|
| Owner of #4320 | Secondary Goal A–E lane (this session) |
| Branch | `cursor/fix-pop-mount-queue-max-aa31` |
| Is `queue: max` a **required-check blocker on #4312 exact head**? | **No.** Head `10f3a9ab1e91` — PR CI green while file still lacks `queue: max`. |
| Actual failing assertion | `test_every_shared_production_lock_queues_instead_of_replacing_pending_runs` |
| Actual failing run | Deploy to Pi [`39346856677`](https://github.com/jerry200176-png/AllTrue_System/actions/runs/39346856677) head `ef1db197a3ac` (tip Merge/activation contract) — **not** #4312 PR CI |
| Implication | #4320 is **optional tip-Deploy hygiene**, not a mandatory predecessor of #4312. Secondary lane **stops further mount-workflow edits**. Da’an decides whether to absorb `queue: max` into #4312. |

## Goal A–E track snapshot (secondary lane)

| Track | Status | Next action (owner) |
|-------|--------|---------------------|
| A Xinzhuang | INCOMPLETE — keep Lane N vs Lane B separate | Da’an: Lane N acceptance evidence; Founder only if Lane B activation requested |
| B #401/#402 / 4131 | Code LIVE on pin; Dot PASS pending | Dot scores dump `39327056654` |
| C #4132 | COMPLETE | — |
| D remaining-18 | Packages prepared; **0 sent** | Founder/Dot **production-write GO** (absent) |
| E closeout matrix | SHA bindings prepared; **0/14** public replies | After D WRITE_GO; no dummy Closes |

## Notification route (verified only)

| Route | Verified? |
|-------|-----------|
| `DAAN_NEEDS_FOUNDER` comments on **#4312** → Dot checks → notify Jerry in **ChatGPT conversation** when personal login / approval / necessary data / unresolved important blockers require him | **Yes** (per Dot coordination 2026-10-20) |
| Extends to other PRs automatically | **No** |
| Slack / Teams / Cloud→Da’an direct channel | **Not verified** — do not claim |

## Acceptance / reporter-response (policy pointers — not new gates)

- Engineering ≠ reporter acceptance: [`docs/governance/EVIDENCE_CONTRACT.md`](../../docs/governance/EVIDENCE_CONTRACT.md)
- In-app product loop closure kinds: [`docs/plans/INAPP_PRODUCT_LOOP_EXECUTION_POLICY_V1.md`](../../docs/plans/INAPP_PRODUCT_LOOP_EXECUTION_POLICY_V1.md)
- Intake → production writeback: [`docs/sop/BUG_INTAKE_TO_PRODUCTION.md`](../../docs/sop/BUG_INTAKE_TO_PRODUCTION.md)
- Merge risk: [`docs/governance/RISK_BASED_MERGE_POLICY.md`](../../docs/governance/RISK_BASED_MERGE_POLICY.md)

## Decision / source links

- Ownership issue: [#4070](https://github.com/jerry200176-png/AllTrue_System/issues/4070)
- #4312: https://github.com/jerry200176-png/AllTrue_System/pull/4312
- #4320 (HOLD / not #4312 predecessor): https://github.com/jerry200176-png/AllTrue_System/pull/4320
- Goal A–E public status: [`goal-ae-owner-unblock.status.md`](./goal-ae-owner-unblock.status.md)
- Xinzhuang fee status: [`xinzhuang-fee-closeout.status.md`](./xinzhuang-fee-closeout.status.md)
- Product decisions packet: [`docs/plans/2026-10-20-inapp-closeout-product-decisions.md`](../../docs/plans/2026-10-20-inapp-closeout-product-decisions.md)
