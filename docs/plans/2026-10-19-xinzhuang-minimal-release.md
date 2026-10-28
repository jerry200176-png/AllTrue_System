# Xinzhuang three-case minimal release

## Goal
Unblock only courses 004064, 003753, and 003693 through the smallest protected billing release. Preserve financial data and keep the correction feature disabled until protected approvals and case-specific authorization are complete.

## Steps
1. Reconcile deployed backend source, prior create-table migration behavior, and current read-only runtime schema evidence; record exact missing fields and API gate.
2. Add an additive, repeatable extension migration that supports an already-existing `invoice_corrections` table without rewriting legacy rows, plus a fail-closed server-side course allowlist for these three cases.
3. Add regression coverage for populated legacy schema upgrades, idempotent migration behavior, default-off and allowlist/campus boundaries, and existing correction behavior.
4. Run targeted tests and required CI checks; prepare release/repair evidence with exact SHA, migration, flag and allowlist scope, verification, rollback, and per-case authorization/precondition gaps.
5. Update the private handoff with safe evidence only. Stop before production migration, feature activation, or financial writes.

## Verification
- Targeted migration/schema lifecycle, API scope, agreed-fee correction, and 003753 reversal-restatement tests.
- Repository CI and required independent review for the exact release SHA.
- Read-only production evidence only; no SSH or production mutation.

## Stop points
- Founder/protected approval is required before production migration or enabling the API.
- Each financial correction requires its own exact preview and case-specific authorization; 003753 additionally requires original reversal-row proof and owner disposition.
