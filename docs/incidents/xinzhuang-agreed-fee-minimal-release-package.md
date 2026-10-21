# Xinzhuang agreed-fee correction: minimal release package

**Risk:** R3 / T3 — billing schema, bounded API activation, and later financial repair.
**State (2026-10-19T20:35Z):** additive schema + default-off API merged via #4283 (`506729c7`); dump path #4293/#4296 (`5e4c48c0`); allowlist config surface #4300 (`8e6228a3`) on main. **Not** production-activated: tip dumps report public `completeness=correction_schema_missing` / `snapshot_complete=false` for courses 4064/3753/3693; Deploy to Production still SKIPPED (release-train window `10:17Z` already served by `39241462999`; next auto ≈ `04:17Z` or Founder manual train). Feature flag remains default-off. No financial write authorized. Acceptance still **0/3**. Public status: `operations/closeout/xinzhuang-fee-closeout.status.md`.

## Production evidence and reconciliation

The deployed backend source contains `2026_10_15_120000_create_invoice_corrections_table.php`. That migration creates `invoice_corrections` only when the table is absent and returns immediately when it already exists. The post-deployment read-only checks confirmed that the table exists, `security_audit_events` exists, and the agreed-fee extension columns do not. The production migration ledger was not inspected through SSH and remains unverified; no migration was run during this investigation.

`AgreedFeeCorrection::available()` requires these seven fields on `invoice_corrections`: `kind`, `campus_id`, `old_charge`, `new_charge`, `original_invoice_total`, `request_hash`, and `state_hash`. The controller also returns 404 while `billing.agreed_fee_correction_enabled` is false. The proposed migration adds those fields, makes the existing invoice links and amount nullable for the already-supported unbilled correction kind, and adds the existing service's `(student_class_id, idempotency_key)` uniqueness requirement. It preserves old rows with `kind='receipt_reissue'` and null extension metadata.

## Scope and release gates

The existing API and behavior from #4259 are reused. No UI from the stale #4251 draft is included. The proposed campus/course allowlist fails closed when empty. Production allowlist values must be supplied only through the Founder-gated workflow [`.github/workflows/agreed-fee-allowlist-activation.yml`](../../.github/workflows/agreed-fee-allowlist-activation.yml) (`set_allowlist` / `clear_allowlist`), reading repo secrets `AGREED_FEE_CORRECTION_CAMPUS_IDS` and `AGREED_FEE_CORRECTION_COURSE_IDS` (never committed; logs emit counts/fingerprint only). That surface does **not** flip `billing.agreed_fee_correction_enabled` (literal false until a separate Founder-authorized code activation). Tip schema still requires normal `deploy.yml` production-activation for #4283.

For course 003753, the private parallel history review found that the excess reversal and pending report can be planned as a signed-net-zero sequence, but the exported evidence omitted the original reversal row. The original server-side row proof and owner disposition are still mandatory before any restatement or fee correction. The other two cases likewise require fresh post-release previews before case-specific financial authorization.

## Migration compatibility and rollback

- **Phase:** Expand only; nullable metadata plus a safe default; old code ignores the additional columns.
- **Reversibility:** `down()` restores the old table shape only when the permanent history table is empty. With any history row, it refuses and retains the schema.
- **Production rollback:** disable/rollback application code through the standard deployment workflow and keep the additive schema. Do not run `migrate:rollback` against permanent correction history.
- **Feature rollback:** leave or restore the literal flag to false and clear the bounded allowlist through the approved runtime configuration path.
- **Financial rollback:** not included. Each later POP operation has its own immutable manifest, preconditions, authorization, and verifier.

## Required verification before release

Run exact-head CI, migration dry-run against MariaDB 10.11, independent review, and direct post-release read-only previews for the three allowlisted courses. Verify migration fields, feature flag, allowlist, campus checks, and the absence of payment/report/receipt side effects. Keep acceptance at 0/3 until each correction is executed through the in-app SOP and verified against authoritative records.
