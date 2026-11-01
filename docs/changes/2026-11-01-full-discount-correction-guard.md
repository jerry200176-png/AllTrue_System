## 2026-11-01 — fix(billing): a full-discount bill must be voided before a charge or billing correction (#4657)
<!-- release-notes: silent_ship=silent-2026-11-01-full-discount-correction-guard -->
<!-- silent-reason: a refusal staff should never hit in normal use; the message names the 全額折扣 bill to void first -->
- 費用更正 and 堂數更正 used to refuse only a live 免費／贈送 bill. A settled 全額折扣 renewal bill (#4018: NT$0, Status=paid) could be raised in place to a positive total that could be neither paid nor voided. `Invoice::markedZeroBillLabel()` now covers both marks. Both endpoints refuse with the existing `*_free_gift_locked` code, the message names the bill to void, and the check is repeated under the row lock.
