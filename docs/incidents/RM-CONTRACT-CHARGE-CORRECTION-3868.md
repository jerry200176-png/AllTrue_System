# Repair Manifest — contract Charge correction (#3868)

Status: POP operation `contract-charge-correction-3868`; nothing runs until a Founder-approved POP request. Risk: R3/T3 (production data repair).
Founder 2026-10-29 (#3868): StudentClass 2569 Charge 8000 to 4000; StudentClass 3288 Charge 8800 to 1100. Both new values equal Rate x sessions. No other contract changes.

Scope: `ContractChargeCorrectionStrategy::MANIFESTS`, `StudentClass.Charge` of those two rows, nothing else (no invoice, payment, report, session, Paid or counter write). Audit: `pop.contract_charge_correction`, one strict row per course with old and new Charge.

Guards (plan, and again under the course row locks; any difference fails the whole plan, codes carry the course id): `schedule_mode` (count), `package`, `settlement_locked`, `charge_drifted` (not the manifest from or to), `rate_quantity_drifted` (Rate x sessions, or x hours for hourly, is not the manifest base), `invoice_present` (any non-void invoice, anchored or linked by an item), `payment_evidence` (report, paid invoice or non-void payment, also on a voided or item-linked invoice), `paid_flag` (Paid, Pay or PayDate), `pricing_amendment_active`, `session_charge_adjustments`, `mixed_state`. G-009: after the repair `Charge - Rate x quantity` is 0, so a later edit cannot re-apply the old difference; the dry-run prints `preserved_delta_if_applied`.

Run: `.github/workflows/pop-contract-charge-correction-3868.yml`, `direction=repair`, `attempt=1`, `mode=dry-run` (no click; read-only), then `mode=approve` (Founder click on `production-activation`, same `attempt`). If an approval expires unconsumed, rerun both modes with the next `attempt` (a fresh request and approval). Dry-run expects `ok=true state=before`, `counts courses=2 to_change=2`, and per course the live Charge, Rate x quantity and the invoice/payment/amendment counts (all 0). The Pi scheduler (`pop:execute-approved`) runs execute, then verify (Charge applied, guards still clean).

Rollback: the reverse manifest `rollback-contract-charge-3868-20261029` (2569 4000 to 8000, 3288 1100 to 8800) through the same operation and workflow (`direction=rollback`, dry-run then approve). Pi-local rollback phase is not used for this strategy, as for the other exact POP repairs.

## Case 4138 (#4138 item 4, Founder 2026-10-29)

The campus-2 director entered the per-lesson price 455 as the total. StudentClass 4050 and 4051 go from Charge 455 to 9100, which is Rate 455 × 20 sessions. The operation, guards and audit are the ones above. Run the same workflow with `contract_case=4138` and confirm `DRY_RUN_CONTRACT_CHARGE_4138_REPAIR`, then `APPROVE_CONTRACT_CHARGE_4138_REPAIR` (Founder click). Rollback is `rollback-contract-charge-4138-20261029` (9100 back to 455), via `direction=rollback`.
