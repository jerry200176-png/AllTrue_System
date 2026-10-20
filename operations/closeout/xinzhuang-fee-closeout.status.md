# Xinzhuang fee closeout — public-safe status

Last updated: 2026-10-20T02:20Z  
Acceptance: **0/3** (courses 4064 / 3753 / 3693) — counting requires per-case evidence; see lanes below  
No secrets, state hashes, financial payloads, or student PII in this file.

**Coordination:** [`lane-coordination.status.md`](./lane-coordination.status.md) · [#4070](https://github.com/jerry200176-png/AllTrue_System/issues/4070)  
**Owner:** Da’an = primary billing / #4312 implementation+queue. Local Cursor = relay only. Secondary Cloud = Goal A–E docs hygiene only.

## Tip / live (read-only)

| Ref | SHA (12) | Note |
|-----|----------|------|
| `origin/main` tip | `e6208fc8d7aa` | includes #4319 Goal A–E owner-unblock + #4317/#4318 lineage |
| Live `deployment.json` backend | `ad9e36904283` | deployed_at `2026-10-19T11:02:17Z` (DtP `39241462999`) |
| #4283 schema squash | `506729c78ab4` | tip-only for **Lane B**; **not** a hard gate for **Lane N** |
| #4300 allowlist surface | `8e6228a35fad` | on main; Founder-gated; Lane B only |

**Do not treat aggregate `deploy.yml` success as live.** Waiting release train `39333337571` remains **HOLD / DO NOT APPROVE** from coordination messages (not financial WRITE_GO).

## Two lanes (do not collapse)

| Lane | What | Live on `ad9e369`? | Requires `#4283` tip activation? |
|------|------|--------------------|----------------------------------|
| **N** Narrow unpaid-invoice alignment | Primary review: operation already on live pin | Claimed **yes** (code path) | **No** |
| **B** Broad agreed-fee schema / allowlist / flag | `#4283` + `#4300` + flag | Schema **no** (tip ahead) | **Yes** (separate Founder/owner decision) |

Lane N still needs: runtime/state · authorized tuple · POP · real staff session · fresh acceptance / reporter response before counting N/3.  
Lane B must **not** be treated as a prerequisite for Lane N.

## Tip evidence (dumps on `5e4c48c0`; serial after #4296)

Serial fee dumps after #4296 (`xinzhuang-fee-dump-request` run `39309549854`):

| Course | Dump run | Public case status |
|--------|----------|--------------------|
| 4064 | 39309569171 | `ok=true`, `snapshot_complete=false`, `completeness=correction_schema_missing` |
| 3753 | 39309623293 | `ok=true`, `snapshot_complete=false`, `completeness=correction_schema_missing` |
| 3693 | 39309674831 | `ok=true`, `snapshot_complete=false`, `completeness=correction_schema_missing` |

Note: `correction_schema_missing` is a **Lane B** schema signal. It must not be used alone to block Lane N acceptance on the narrow live path.

## Blockers (by lane)

### Lane N (Da’an primary)

1. Authorized tuple + POP + real staff session + fresh acceptance for each applicable course
2. 3753: original reversal-row proof + owner disposition (do not force-align)
3. Affected-path verification before counting each of N/3

### Lane B (separate; Founder / vault)

1. Cloud decrypt custody when needed — bind `DUMP_ARTIFACT_KEY` via Personal vault; never paste into chat/PR
2. Tip `production-activation` for `#4283` only after owner coordination — **not** implied by Lane N
3. Allowlist (`#4300`) + flag stay Founder-gated / default-off until case POP
4. Tip Deploy Merge/activation contract may fail on tip if `pop-mount` lacks `queue: max` (Deploy run `39346856677`) — **tip Deploy hygiene**, not a #4312 PR required-check blocker; Da’an owns whether to absorb into #4312 (#4320 is **not** a mandatory predecessor)

## Merged enablers (historical)

- #4291 Phase-C reconcile / fail-hard alignment preview  
- #4283 agreed-fee schema extension (default-off API) — **Lane B**  
- #4292 land-queue UNSTABLE when required green  
- #4293 request-file dump dispatcher  
- #4296 serialize dumps  
- #4297 public closeout status + queue dump refresh  
- #4298 contract_4131 Track B dump (`39327056654`)  
- #4300 Founder-gated allowlist activation surface — **Lane B**  
- #4308 acceptance listens for Deploy to Pi + Release train  

## Next sequence

1. **Lane N first** (Da’an): accept on live pin with POP + staff session evidence  
2. **Lane B only if intentionally activated** (Founder/owner): tip activation → allowlist/flag → schema-dependent corrections  
3. Never hard-gate Lane N on Lane B  
