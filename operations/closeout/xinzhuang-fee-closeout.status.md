# Xinzhuang fee closeout — public-safe status

Last updated: 2026-10-20T02:50Z  
Financial case count: **0/3** verified execution/readback (courses 4064 / 3753 / 3693) — independent of in-app reply/`resolved`/reporter acceptance  
No secrets, state hashes, financial payloads, or student PII in this file.

**Factual checkpoint (not new authority):** [`lane-coordination.status.md`](./lane-coordination.status.md) · [#4070](https://github.com/jerry200176-png/AllTrue_System/issues/4070) (**registration PENDING** — not a merge gate)  
**Owners (observed):** Da’an = **#4312** + **Lane N** narrow primary billing only (not expanded to Lane B). **#4312 MERGED** `177954283c` @ `02:40:24Z`. Lane B implementation = **existing owner — to be verified**. Local Cursor = relay only. Secondary Cloud = Goal A–E docs hygiene only. Main billing must not wait on this docs PR.

## Tip / live (read-only)

| Ref | SHA (12) | Note |
|-----|----------|------|
| `origin/main` tip | `177954283c` | includes #4312 pop-mount bind + #4319/#4317/#4318 lineage |
| Live `deployment.json` backend | `ad9e36904283` | deployed_at `2026-10-19T11:02:17Z` (DtP `39241462999`); pin **unchanged** after #4312 merge |
| #4283 schema squash | `506729c78ab4` | tip-only for **Lane B**; **not** a hard gate for **Lane N** |
| #4300 allowlist surface | `8e6228a35fad` | on main; Founder-gated; Lane B only |

**Do not treat aggregate `deploy.yml` success as live.** Waiting release train `39333337571` remains **HOLD / DO NOT APPROVE** from coordination messages (not financial WRITE_GO). #4312 was queued `02:21:58Z` then **MERGED** `02:40:24Z` — secondary lane took **no** queue/branch action; post-merge tip Deploy remains out of scope here.

## Two lanes (do not collapse)

| Lane | What | Live on `ad9e369`? | Requires `#4283` tip activation? | Impl owner |
|------|------|--------------------|----------------------------------|------------|
| **N** Narrow unpaid-invoice alignment | Primary review: operation already on live pin | Claimed **yes** (code path) | **No** | **Da’an** (narrow only) |
| **B** Broad agreed-fee schema / allowlist / flag | `#4283` + `#4300` + flag | Schema **no** (tip ahead) | **Yes** (Founder gates unchanged) | **Existing — to be verified** (not newly Da’an) |

### Evidence independence

- **Financial N/3:** runtime/state · authorized tuple · POP · real staff session · affected-path readback. Reporter response is **not** a new prerequisite for counting a verified financial case.
- **In-app** public reply / `resolved` / reporter acceptance: record separately under existing policy; financial success does **not** imply in-app closeout.

Lane B must **not** be treated as a prerequisite for Lane N.

## Tip evidence (dumps on `5e4c48c0`; serial after #4296)

Serial fee dumps after #4296 (`xinzhuang-fee-dump-request` run `39309549854`):

| Course | Dump run | Public case status |
|--------|----------|--------------------|
| 4064 | 39309569171 | `ok=true`, `snapshot_complete=false`, `completeness=correction_schema_missing` |
| 3753 | 39309623293 | `ok=true`, `snapshot_complete=false`, `completeness=correction_schema_missing` |
| 3693 | 39309674831 | `ok=true`, `snapshot_complete=false`, `completeness=correction_schema_missing` |

Note: `correction_schema_missing` is a **Lane B** schema signal. It must not be used alone to block Lane N financial verification on the narrow live path.

## Blockers (by lane)

### Lane N (Da’an — narrow financial)

1. Authorized tuple + POP + real staff session + affected-path readback for each applicable course
2. 3753: original reversal-row proof + owner disposition (do not force-align)
3. Count N/3 only from financial evidence states — **not** from in-app reporter acceptance

### Lane B (separate — owner verify; Founder gates unchanged)

1. Confirm **existing** implementation owner (not reassigned by this note)
2. Cloud decrypt custody when needed — bind `DUMP_ARTIFACT_KEY` via Personal vault; never paste into chat/PR
3. Tip `production-activation` for `#4283` only after verified owner + Founder — **not** implied by Lane N
4. Allowlist (`#4300`) + flag stay Founder-gated / default-off until case POP
5. Tip Deploy Merge/activation hygiene historically failed when `pop-mount` lacked `queue: max` (Deploy run `39346856677`); #4312 merge binds mount to serving FPM — post-merge tip Deploy ownership stays Da’an / primary; #4320 CLOSED (not a #4312 predecessor); secondary lane does not queue tip Deploy

## Historical safety holds (retained)

- Waiting release train `39333337571` **HOLD / DO NOT APPROVE**
- In-app **#394**: HOLD further public reply until comment **999** body is privately verified
- No financial WRITE_GO from docs hygiene PRs

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
- #4312 pop-mount bind squash `177954283c` (observe-only from secondary lane)  

## Related

- Decision issue: #4261  
- Plan: `docs/plans/2026-10-19-xinzhuang-minimal-release.md`  
- Closeout product decisions: `docs/plans/2026-10-20-inapp-closeout-product-decisions.md`  
- Release package: `docs/incidents/xinzhuang-agreed-fee-minimal-release-package.md`  
- In-app #394: HOLD further public reply until comment 999 body is privately verified  

## Next sequence

1. **Lane N financial** execution/readback on live pin (Da’an)  
2. **Lane B** only if intentionally activated (verified owner + Founder gates)  
3. Never hard-gate Lane N on Lane B; never collapse financial vs in-app evidence  
