# Lane coordination — public-safe factual checkpoint

Last updated: 2026-10-20T02:50Z  
Shared GitHub ownership issue: [#4070](https://github.com/jerry200176-png/AllTrue_System/issues/4070)  
No secrets, student PII, financial amounts, encrypted dump payloads, or case tuples in this file.

**Nature of this file:** factual **checkpoint / reference** recording verified coordination observations. It is **not** a new ownership, permission, or merge-authority source. Canonical policy remains Constitution / `RISK_BASED_MERGE_POLICY` / `EVIDENCE_CONTRACT` / `BUG_INTAKE_TO_PRODUCTION`. #4070 acknowledgement is **not** a merge gate for this T0 docs PR.

## Ownership observations (Dot/Jerry coordination 2026-10-20)

| Lane | Owner (observed) | Role | Must not (from coordination) |
|------|------------------|------|------------------------------|
| **#4312** + **Lane N** narrow primary billing (impl + queue) | **Da’an** | Sole implementation + queue for that scope (**#4312 MERGED** `177954283c` @ `2026-10-20T02:40:24Z`) | Other agents edit post-merge tip deploy ownership; **scope must not expand** into Lane B by this note |
| **Lane B** broad `#4283` schema / allowlist / flag **implementation** | **Existing owner — to be verified** (separate scope; **not** newly assigned to Da’an here) | Tip code ownership TBD by prior record / coordinator | Treat this file as reassignment; change Founder protected activation / allowlist / flag gates |
| Primary Cloud reviewer/coordinator | Cloud task `bc-01a1549a-4b74-7985-95f8-2ac405420cfb` | Review / coordinate | Mutate Da’an-owned branches; invent financial WRITE_GO |
| Local Cursor | **Released — relay only** | Relay evidence / status | Case execution; claim sole case-writer |
| Secondary Goal A–E / product follow-up | This Cloud session (Goal A–E lane) | Non-billing product closeout prep, Track B Dot PASS wait, D/E send prep, docs hygiene | Edit #4312; financial writes; claim #4320 as #4312 predecessor |

### #4070 registration — **PENDING (uncompleted)**

Proposed registration text is retained in [#4321](https://github.com/jerry200176-png/AllTrue_System/pull/4321) body. Direct issue comment API returned **403** (2026-10-20). **No alternate-route retry.** Pending registration does **not** block merge of factual T0 docs under existing R0/T0 gates.

## Checkpoint — live / trains (public)

| Item | Value | Note |
|------|-------|------|
| Live `deployment.json` backend | `ad9e36904283` | DtP `39241462999`; deployed_at `2026-10-19T11:02:17Z` (pin unchanged after #4312 merge) |
| `origin/main` tip | `177954283c` | Includes #4312 squash; main CI / Deploy-to-Pi for this tip observed separately |
| Waiting release train | `39333337571` | **HOLD / DO NOT APPROVE** from coordination messages |
| This file | Factual checkpoint only | **Not** financial write GO; **not** production-activation |
| #4312 | **MERGED** `177954283c` @ `02:40:24Z` (was queued `02:21:58Z`) | Secondary lane took **no** queue/branch action; post-merge tip deploy remains Da’an / primary |
| #4321 land | Exact-head CI **green** (`08325bb`); `BEHIND`; auto-merge **off**; `queue` label **403** for this agent | Serial land-queue only — no independent auto-merge / direct merge |

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
| Implementation owner | **Existing owner — to be verified** (separate scope; **not** newly assigned to Da’an by this note) |
| Protected gates | Founder `production-activation` / allowlist / flag — **unchanged** |
| Next | Only after verified owner + Founder gates — **not** implied by Lane N |

## Independent evidence states (existing policy — do not collapse)

Record each row separately. **Do not** require an in-app reporter response as a new prerequisite for counting a **verified financial case**. **Do not** imply that financial case success closes an in-app report.

| Evidence state | What it proves | Policy |
|----------------|----------------|--------|
| **Financial execution / readback** | Runtime/state · authorized tuple · POP · real staff session · affected-path readback for that case | Case/POP / billing evidence — independent of in-app lifecycle |
| **In-app public reply** | Reporter-facing comment posted | `EVIDENCE_CONTRACT` / CHAT_BUG §3.6–§3.7 |
| **In-app `resolved`** | Engineering closeout + required production evidence fields | `EVIDENCE_CONTRACT` — ≠ reporter acceptance |
| **Reporter acceptance** | Reporter-verify succeeded **only** | Affirmative acceptance — **not** a gate for financial N/3 count (`EVIDENCE_CONTRACT`) |
| **Timeout closure** | Operational closure without affirmative reporter acceptance | Separate outcome under `EVIDENCE_CONTRACT` — never counts as reporter acceptance |

Pointers (no new gates): [`EVIDENCE_CONTRACT.md`](../../docs/governance/EVIDENCE_CONTRACT.md) · [`INAPP_PRODUCT_LOOP_EXECUTION_POLICY_V1.md`](../../docs/plans/INAPP_PRODUCT_LOOP_EXECUTION_POLICY_V1.md) · [`BUG_INTAKE_TO_PRODUCTION.md`](../../docs/sop/BUG_INTAKE_TO_PRODUCTION.md) · [`RISK_BASED_MERGE_POLICY.md`](../../docs/governance/RISK_BASED_MERGE_POLICY.md)

## In-app send capability (Track D/E) — canonical

See [`BUG_INTAKE_TO_PRODUCTION.md`](../../docs/sop/BUG_INTAKE_TO_PRODUCTION.md):

- **SSH Phase-C allowlist path retired** (2026-10-19; ADR-POP-015 / #4289). Allowlist request-file / `bug-phase-c-allowlist.yml` remote write is **not** an executable path.
- **Currently executable** (when authorized): `bug-followup-comment.yml` — **comment-only** follow-up (main `7c6f3bdf`: #4386 batch path installed, preview → write; evidence and authorization remain required); does **not** change bug status by itself.
- **Unavailable:** Pi-local `inapp-report-resolve-after-deploy` is **not** catalogued / standing-policy-enabled — do **not** treat as runnable.
- A bare **production-write GO** / WRITE_GO stamp does **not** re-enable retired SSH Phase-C or invent resolve-after-deploy.

## #4320 vs #4312 dependency (verified)

| Question | Answer |
|----------|--------|
| Owner of #4320 | Secondary Goal A–E lane (this session) |
| Branch | `cursor/fix-pop-mount-queue-max-aa31` |
| Is `queue: max` a **required-check blocker on #4312 exact head**? | **No.** Head `10f3a9ab1e91` — PR CI green while file still lacks `queue: max`. |
| Actual failing assertion | `test_every_shared_production_lock_queues_instead_of_replacing_pending_runs` |
| Actual failing run | Deploy to Pi [`39346856677`](https://github.com/jerry200176-png/AllTrue_System/actions/runs/39346856677) head `ef1db197a3ac` (tip Merge/activation contract) — **not** #4312 PR CI |
| Draft conversion | **Unverified.** At ~2026-10-20T02:18Z GitHub API still reported `isDraft=false`. |
| Later state | PR **CLOSED** (`closedAt` ~2026-10-20T02:20:53Z) with `isDraft=false` — not a verified draft conversion |
| Implication | #4320 is **not** a mandatory predecessor of #4312. Secondary lane takes **no** #4312 queue/branch action |

## Goal A–E track snapshot (secondary lane)

| Track | Status | Next action (owner) |
|-------|--------|---------------------|
| A Xinzhuang | INCOMPLETE — Lane N vs Lane B separate | Da’an: Lane N **financial** execution/readback; Lane B owner **verify separately**; Founder gates unchanged |
| B #401/#402 / 4131 | Code LIVE on pin; Dot PASS pending | Dot scores dump `39327056654` |
| C #4132 | COMPLETE | — |
| D remaining-18 | Packages prepared; **0 sent** | Authorized **comment-only** follow-up path per `BUG_INTAKE_TO_PRODUCTION` when GO exists; **not** retired SSH Phase-C; **not** resolve-after-deploy |
| E closeout matrix | SHA bindings prepared; **0/14** public replies | Same as D; no dummy Closes; independent of financial N/3 |

## Historical safety holds (retained)

- Waiting release train `39333337571` **HOLD**
- In-app **#394**: HOLD further public reply until comment **999** body is privately verified
- No financial WRITE_GO from this docs PR
- Main billing must not wait on this T0 docs PR

## Notification route (verified only)

| Route | Verified? |
|-------|-----------|
| `DAAN_NEEDS_FOUNDER` comments on **#4312** → Dot checks → notify Jerry in **ChatGPT conversation** when personal login / approval / necessary data / unresolved important blockers require him | **Yes** (per Dot coordination 2026-10-20) |
| Extends to other PRs automatically | **No** |
| Slack / Teams / Cloud→Da’an direct channel | **Not verified** — do not claim |

## Decision / source links

- Ownership issue: [#4070](https://github.com/jerry200176-png/AllTrue_System/issues/4070) — registration **PENDING** (not a merge gate)
- #4312: https://github.com/jerry200176-png/AllTrue_System/pull/4312 — **MERGED** `177954283c` @ `02:40:24Z` (queued earlier `02:21:58Z`); secondary took no queue/branch action
- #4320: https://github.com/jerry200176-png/AllTrue_System/pull/4320 — CLOSED; draft conversion unverified
- #4321: https://github.com/jerry200176-png/AllTrue_System/pull/4321 — T0 land-ready; needs permitted actor `queue` label (agent 403)
- Goal A–E public status: [`goal-ae-owner-unblock.status.md`](./goal-ae-owner-unblock.status.md)
- Xinzhuang fee status: [`xinzhuang-fee-closeout.status.md`](./xinzhuang-fee-closeout.status.md)
- Product decisions packet: [`docs/plans/2026-10-20-inapp-closeout-product-decisions.md`](../../docs/plans/2026-10-20-inapp-closeout-product-decisions.md)
- In-app intake/writeback capability: [`docs/sop/BUG_INTAKE_TO_PRODUCTION.md`](../../docs/sop/BUG_INTAKE_TO_PRODUCTION.md)
