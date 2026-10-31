## 2026-10-31 — ci(arch): FIT-16 ratchet on fee math in controllers (#4659 step 3)
<!-- release-notes: silent_ship=silent-2026-10-31-fit16-fee-math-ratchet -->
<!-- silent-reason: CI architecture check only; no product change -->
- Recurring money-display bugs came from screens computing fees themselves (calendar 輔導 fee #443, slip vs reminder). The new `arch-fitness-check.mjs` FIT-16 counts direct `Rate × count` arithmetic in `backend/app/Http/Controllers` (`->Rate *`, `* (float) $x->rate`). It may only go down: today 4 sites (FinanceController ×2, StudentClassController ×2). New fee math belongs in `StudentClassPricingService` / `ContractMoneyVerdict`. Tested by adding a probe controller with `$sc->Rate * 3`: the check fails 5 > 4.
