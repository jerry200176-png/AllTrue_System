## 2026-11-01 — refactor(scheduling): the calendar's 改到其他合約 moves through one shared service (#4554)
<!-- release-notes: silent_ship=silent-2026-11-01-reassign-contract-service -->
<!-- silent-reason: pure move of existing code into a service; staff see no change -->
- `ClassSessionContractReassigner::reassign()` holds the existing move logic from `ClassSessionController::reassignContract()` unchanged. The controller keeps access checks and the HTTP response. The upcoming exact-case POP for #4554 / in-app #452 reuses the same move, instead of copying it.
