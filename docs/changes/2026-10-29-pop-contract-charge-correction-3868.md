## 2026-10-29 — chore(ops): POP operation to correct the Charge of two contracts (Founder-approved, #3868)
<!-- release-notes: silent_ship=silent-2026-10-29-pop-contract-charge-correction-3868 -->
<!-- silent-reason: data repair operation and dry-run/approve workflow; runs only after Founder approval -->
- New POP operation `contract-charge-correction-3868` (`ContractChargeCorrectionStrategy`, exact manifest, Founder approval, Pi-local): StudentClass 2569 Charge 8000 to 4000 and 3288 Charge 8800 to 1100 (both Rate x sessions), nothing else written. Any invoice, payment, report, pricing amendment or per-session charge fails the plan. A reverse manifest restores the old values through the same operation. Runbook: `docs/incidents/RM-CONTRACT-CHARGE-CORRECTION-3868.md`. Refs #3868
