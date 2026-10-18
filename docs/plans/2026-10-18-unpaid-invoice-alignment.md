# Unpaid invoice alignment preparation

Repository AllTrue_System; base e9e3aa6ba745; task worktree xinzhuang-invoice-alignment-20261018; branch chore/task-xinzhuang-invoice-alignment-20261018; session 106562edb2fb457686a5037647a8682a. Local preflight passed. R3/T3 preparation; no production write/deploy/schema approval inferred from code-only #4259 GO.

Goal: align a single unpaid invoice to its already-correct course charge after every payment and report is reconciled. No fee-feature activation, new financial table, course/entitlement change, collection, receipt or report confirmation. The historical excess reversal and pending-report disposition remain separate protected prerequisites for the third case.

1. Prepare a POP strategy with exact ownership, full before-state hash, bounded graph and existing money guards. Keep it unregistered until the complete protected package is reviewed.
2. Store the immutable before/after proof in existing pop_execution_records, in the same transaction as the invoice update. Internal proof phases are separate from POP's public execute/verify/rollback records; no new public phase or approval bypass. Require the originating POP request and exact parameters.
3. Test invoice-only effects, target amounts, matched void history, foreign owners, standing/pending/unmatched money, drift, proof/audit failures, replay and rollback after downstream work. Use isolated MySQL and synthetic identifiers only.
4. Complete catalog/approval integration and independent review before presenting a consolidated exact release/repair/recovery decision. Do not deploy, migrate, repair real rows or publish the director announcement here. Acceptance remains 0/3.
