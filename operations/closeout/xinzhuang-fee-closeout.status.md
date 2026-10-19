# Xinzhuang fee closeout — public-safe status

Last updated: 2026-10-19T19:50Z  
Acceptance: **0/3** (courses 4064 / 3753 / 3693)  
No secrets, state hashes, financial payloads, or student PII in this file.

## Tip evidence (main `5e4c48c0`)

Serial fee dumps after #4296 (`xinzhuang-fee-dump-request` run `39309549854`):

| Course | Dump run | Public case status |
|--------|----------|--------------------|
| 4064 | 39309569171 | `ok=true`, `snapshot_complete=false`, `completeness=correction_schema_missing` |
| 3753 | 39309623293 | `ok=true`, `snapshot_complete=false`, `completeness=correction_schema_missing` |
| 3693 | 39309674831 | `ok=true`, `snapshot_complete=false`, `completeness=correction_schema_missing` |

## Hard blockers (Founder / vault)

1. **Cloud decrypt custody** — bind `DUMP_ARTIFACT_KEY` (and optionally `ALLTRUE_TRIAGE_TOKEN`) on the AllTrue Cloud environment via Personal vault. Do not paste values into chat/PR/issues.
2. **Tip production activation** — #4283 additive `invoice_corrections` extension (`506729c7`, migration `2026_10_19_090000_extend_invoice_corrections_for_agreed_fee.php`) is on `main`, but live dumps still report `correction_schema_missing`. Approve `production-activation` / `ACTIVATE_PRODUCTION:<target_sha>` through the normal deploy path. Keep `billing.agreed_fee_correction_enabled` **default-off** until case-specific POP packs exist.
3. **Allowlist config surface** — after tip is live, use Founder-gated `agreed-fee-allowlist-activation.yml` (`set_allowlist` / `clear_allowlist`) with repo secrets; does **not** enable the API flag.
4. **Per-case POP** — after decrypt + live schema, authorize 4064/3693 alignment separately; 3753 needs original reversal-row proof + owner disposition (do not force-align).
5. **Affected-path verification** — required after each correction before counting N/3.

## Merged enablers

- #4291 Phase-C reconcile / fail-hard alignment preview  
- #4283 agreed-fee schema extension (default-off API)  
- #4292 land-queue UNSTABLE when required green  
- #4293 request-file dump dispatcher  
- #4296 serialize dumps (recovered cancelled 3753)  
- #4297 public closeout status + queue dump refresh

## Related

- Decision issue: #4261  
- Plan: `docs/plans/2026-10-19-xinzhuang-minimal-release.md`  
- Release package: `docs/incidents/xinzhuang-agreed-fee-minimal-release-package.md`  
- In-app #394: HOLD further public reply until comment 999 body is privately verified  

## Next sequence

tip activation + migration → (optional) set_allowlist via activation workflow → fresh dumps if needed → decrypt → private hash packs → Founder POP → separate flag activation → corrections → clear_allowlist / flag off → affected-path verify → N/3.
