# Xinzhuang fee closeout — public-safe status

Last updated: 2026-10-20T01:02Z  
Acceptance: **0/3** (courses 4064 / 3753 / 3693)  
No secrets, state hashes, financial payloads, or student PII in this file.

## Tip / live (read-only)

| Ref | SHA (12) | Note |
|-----|----------|------|
| `origin/main` tip | `ac9657830af3` | includes #4317 closeout product-decision plan + #4314 status refresh + #4308 acceptance trigger + #4283 lineage |
| Live `deployment.json` backend | `ad9e36904283` | deployed_at `2026-10-19T11:02:17Z` (DtP `39241462999`) |
| #4283 schema squash | `506729c78ab4` | ancestor of tip; **not** ancestor of live (GATE=FAIL_NOT_LIVE) |
| #4300 allowlist surface | `8e6228a35fad` | on main; `set_allowlist` still Founder-gated after tip live |

**Do not treat aggregate `deploy.yml` success as live.** Recent runs completed with **Deploy to Production skipped** (or failed before DtP). Waiting release train `39333337571` (head `d587a927e38d`) is **DO NOT APPROVE** while local Cursor case-execution pin holds live `ad9e369`. Fallback auto window ≈ `2026-10-20T04:17Z` still requires Founder `production-activation` for tip ≥ `506729c` before counting schema live.

## Tip evidence (dumps on `5e4c48c0`; serial after #4296)

Serial fee dumps after #4296 (`xinzhuang-fee-dump-request` run `39309549854`):

| Course | Dump run | Public case status |
|--------|----------|--------------------|
| 4064 | 39309569171 | `ok=true`, `snapshot_complete=false`, `completeness=correction_schema_missing` |
| 3753 | 39309623293 | `ok=true`, `snapshot_complete=false`, `completeness=correction_schema_missing` |
| 3693 | 39309674831 | `ok=true`, `snapshot_complete=false`, `completeness=correction_schema_missing` |

## Hard blockers (Founder / vault)

1. **Cloud decrypt custody** — bind `DUMP_ARTIFACT_KEY` (and optionally `ALLTRUE_TRIAGE_TOKEN`) on the AllTrue Cloud environment via Personal vault. Do not paste values into chat/PR/issues.
2. **Tip production activation** — #4283 additive `invoice_corrections` extension (`506729c7`, migration `2026_10_19_090000_extend_invoice_corrections_for_agreed_fee.php`) is on tip `ac9657830`, but live dumps still report `correction_schema_missing` and live backend remains `ad9e369` (behind `506729c`). Approve `production-activation` only after coordinating with the local Cursor case-execution owner (pin `ad9e369` / courses 4064+3693). Keep `billing.agreed_fee_correction_enabled` **default-off** until case-specific POP packs exist.
3. **Allowlist config surface** — after tip is live, use Founder-gated `agreed-fee-allowlist-activation.yml` (`set_allowlist` / `clear_allowlist`) with repo secrets; does **not** enable the API flag (#4300 on main).
4. **Per-case POP** — after decrypt + live schema, authorize 4064/3693 alignment separately; 3753 needs original reversal-row proof + owner disposition (do not force-align). Cloud agents do not write cases while the local pin holds.
5. **Affected-path verification** — required after each correction before counting N/3.

## Merged enablers

- #4291 Phase-C reconcile / fail-hard alignment preview  
- #4283 agreed-fee schema extension (default-off API)  
- #4292 land-queue UNSTABLE when required green  
- #4293 request-file dump dispatcher  
- #4296 serialize dumps (recovered cancelled 3753)  
- #4297 public closeout status + queue dump refresh  
- #4298 contract_4131 Track B dump request-file (`3f37116a2806`) — dump run `39327056654` public ok (path scoring = Dot)  
- #4300 Founder-gated `agreed-fee-allowlist-activation.yml` on main (`8e6228a3`); secrets + tip activation still required before `set_allowlist`  
- #4304 supplemental release-train when tip advances past window-serving head  
- #4305 / #4314 closeout status tip/live refreshes  
- #4308 calendar/course acceptance listens for `Deploy to Pi` **and** `Release train` — observability after a future approved train; not tip activation itself  
- #4317 in-app closeout product-decision packet (#398/#410/#412/#413) — decisions still open; no WRITE_GO  

## Related

- Decision issue: #4261  
- Plan: `docs/plans/2026-10-19-xinzhuang-minimal-release.md`  
- Closeout product decisions: `docs/plans/2026-10-20-inapp-closeout-product-decisions.md`  
- Release package: `docs/incidents/xinzhuang-agreed-fee-minimal-release-package.md`  
- In-app #394: HOLD further public reply until comment 999 body is privately verified  

## Next sequence

coordinate pin → tip activation + migration → (optional) set_allowlist via activation workflow → fresh dumps if needed → decrypt → private hash packs → Founder POP → separate flag activation → corrections → clear_allowlist / flag off → affected-path verify → N/3.
