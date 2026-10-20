# Lane coordination — public-safe checkpoint (DRAFT)

Last updated: 2026-10-20T02:25Z  
Shared GitHub ownership issue: [#4070](https://github.com/jerry200176-png/AllTrue_System/issues/4070)  
No secrets, student PII, financial amounts, encrypted dump payloads, or case tuples in this file.

**Status of this file:** prepared DRAFT for review — **not** yet the implemented ownership source of truth until merged to `main` and acknowledged on #4070.

## Ownership (as of Dot/Jerry coordination 2026-10-20)

| Lane | Owner | Role | Must not |
|------|-------|------|----------|
| **#4312** + **Lane N** narrow primary billing (impl + queue) | **Da’an** | Sole implementation + queue for that scope | Other agents edit `chore/pop-mount-chmod644` or queue #4312; **scope must not expand** into Lane B by this coordination |
| **Lane B** broad `#4283` schema / allowlist / flag **implementation** | **Existing owner — to be verified** (separate scope; **not** newly assigned to Da’an here) | Tip code ownership TBD by prior record / coordinator | Treat this DRAFT as reassignment; change Founder protected activation / allowlist / flag gates |
| Primary Cloud reviewer/coordinator | Cloud task `bc-01a1549a-4b74-7985-95f8-2ac405420cfb` | Review / coordinate | Mutate Da’an-owned branches; invent financial WRITE_GO; queue #4312 without Dot’s separate authorization gate |
| Local Cursor | **Released — relay only** | Relay evidence / status | Case execution; claim sole case-writer |
| Secondary Goal A–E / product follow-up | This Cloud session (Goal A–E lane) | Non-billing product closeout prep, Track B Dot PASS wait, D/E WRITE_GO wait, docs hygiene | Edit #4312; merge/deploy/approve; financial writes; claim #4320 as #4312 predecessor |

### #4070 registration — **PENDING (uncompleted)**

Proposed registration text is retained in [#4321](https://github.com/jerry200176-png/AllTrue_System/pull/4321) body. Direct issue comment API returned **403** (2026-10-20). **No alternate-route retry.** Treat posting to #4070 as still open until a permitted actor completes it.

## Checkpoint — live / trains (public)

| Item | Value | Note |
|------|-------|------|
| Live `deployment.json` backend | `ad9e36904283` | DtP `39241462999`; deployed_at `2026-10-19T11:02:17Z` |
| Waiting release train | `39333337571` | **HOLD / DO NOT APPROVE** from this message |
| This message | Coordination only | **Not** financial write GO; **not** production-activation |
| #4312 queue | **Do not touch** | Dot holds a separate explicit authorization gate and will coordinate |

## Two separate billing lanes (do not collapse)

### Lane N — narrow unpaid-invoice alignment (runtime already on live pin)

| Field | Content |
|-------|---------|
| Claim (primary review) | Narrow unpaid-invoice-alignment operation is **already deployed** on live backend `ad9e369` |
| Does **not** require | Broad `#4283` schema / allowlist / `agreed_fee_correction_enabled` flag migration path |
| Owner | **Da’an** (narrow primary billing only) + primary Cloud coordinator |
| **Must not** | Hard-gate this lane on tip ≥ `506729c` / `#4283` live; expand Da’an ownership into Lane B via this file |

### Lane B — broad agreed-fee schema / allowlist / flag (`#4283` lineage)

| Field | Content |
|-------|---------|
| Code on tip | `#4283` squash `506729c78ab4` (ancestor of tip; **not** of live) |
| Live | Schema **not** live; dumps may still show `correction_schema_missing` for schema-dependent paths |
| Implementation owner | **Existing owner — to be verified** (separate scope; **not** newly assigned to Da’an by this coordination) |
| Protected gates | Founder `production-activation` / allowlist / flag — **unchanged** |
| Next | Only after verified owner + Founder gates — **not** implied by Lane N |

## Independent evidence states (existing policy — do not collapse)

Record each row separately. **Do not** require an in-app reporter response as a new prerequisite for counting a **verified financial case**. **Do not** imply that financial case success closes an in-app report.

| Evidence state | What it proves | Policy |
|----------------|----------------|--------|
| **Financial execution / readback** | Runtime/state · authorized tuple · POP · real staff session · affected-path readback for that case | Case/POP / billing evidence — independent of in-app lifecycle |
| **In-app public reply** | Reporter-facing comment posted | `EVIDENCE_CONTRACT` / CHAT_BUG §3.6–§3.7 |
| **In-app `resolved`** | Engineering closeout + required production evidence fields | `EVIDENCE_CONTRACT` — ≠ reporter acceptance |
| **Reporter acceptance** | Reporter-verify succeeded (or documented timeout path) | Product-loop learning/closure — **not** a gate for financial N/3 count |

Pointers (no new gates): [`EVIDENCE_CONTRACT.md`](../../docs/governance/EVIDENCE_CONTRACT.md) · [`INAPP_PRODUCT_LOOP_EXECUTION_POLICY_V1.md`](../../docs/plans/INAPP_PRODUCT_LOOP_EXECUTION_POLICY_V1.md) · [`BUG_INTAKE_TO_PRODUCTION.md`](../../docs/sop/BUG_INTAKE_TO_PRODUCTION.md) · [`RISK_BASED_MERGE_POLICY.md`](../../docs/governance/RISK_BASED_MERGE_POLICY.md)

## #4320 vs #4312 dependency (verified)

| Question | Answer |
|----------|--------|
| Owner of #4320 | Secondary Goal A–E lane (this session) |
| Branch | `cursor/fix-pop-mount-queue-max-aa31` |
| Is `queue: max` a **required-check blocker on #4312 exact head**? | **No.** Head `10f3a9ab1e91` — PR CI green while file still lacks `queue: max`. |
| Actual failing assertion | `test_every_shared_production_lock_queues_instead_of_replacing_pending_runs` |
| Actual failing run | Deploy to Pi [`39346856677`](https://github.com/jerry200176-png/AllTrue_System/actions/runs/39346856677) head `ef1db197a3ac` (tip Merge/activation contract) — **not** #4312 PR CI |
| Draft conversion | **Unverified.** At ~2026-10-20T02:18Z GitHub API still reported `isDraft=false`. Convert-to-draft via API is unsupported on this forge for this agent. |
| Later state | PR **CLOSED** (`closedAt` ~2026-10-20T02:20:53Z) with `isDraft=false` — secondary lane closed it as not-a-#4312-predecessor; **not** a verified draft conversion |
| Implication | #4320 is **not** a mandatory predecessor of #4312. Secondary lane stops further mount-workflow edits. Absorbing `queue: max` into #4312 (if desired) is **Da’an / Dot coordination** — secondary lane does **not** queue #4312 |

## Goal A–E track snapshot (secondary lane)

| Track | Status | Next action (owner) |
|-------|--------|---------------------|
| A Xinzhuang | INCOMPLETE — Lane N vs Lane B separate | Da’an: Lane N **financial** execution/readback evidence; Lane B owner **verify separately**; Founder gates unchanged |
| B #401/#402 / 4131 | Code LIVE on pin; Dot PASS pending | Dot scores dump `39327056654` |
| C #4132 | COMPLETE | — |
| D remaining-18 | Packages prepared; **0 sent** | Founder/Dot **production-write GO** (absent) — in-app send path |
| E closeout matrix | SHA bindings prepared; **0/14** public replies | After D WRITE_GO; no dummy Closes; independent of financial N/3 |

## Notification route (verified only)

| Route | Verified? |
|-------|-----------|
| `DAAN_NEEDS_FOUNDER` comments on **#4312** → Dot checks → notify Jerry in **ChatGPT conversation** when personal login / approval / necessary data / unresolved important blockers require him | **Yes** (per Dot coordination 2026-10-20) |
| Extends to other PRs automatically | **No** |
| Slack / Teams / Cloud→Da’an direct channel | **Not verified** — do not claim |

## Decision / source links

- Ownership issue: [#4070](https://github.com/jerry200176-png/AllTrue_System/issues/4070) — registration **PENDING**
- #4312: https://github.com/jerry200176-png/AllTrue_System/pull/4312 — queue: Dot gate only
- #4320: https://github.com/jerry200176-png/AllTrue_System/pull/4320 — CLOSED; draft conversion unverified
- #4321 (this DRAFT): https://github.com/jerry200176-png/AllTrue_System/pull/4321
- Goal A–E public status: [`goal-ae-owner-unblock.status.md`](./goal-ae-owner-unblock.status.md)
- Xinzhuang fee status: [`xinzhuang-fee-closeout.status.md`](./xinzhuang-fee-closeout.status.md)
- Product decisions packet: [`docs/plans/2026-10-20-inapp-closeout-product-decisions.md`](../../docs/plans/2026-10-20-inapp-closeout-product-decisions.md)
