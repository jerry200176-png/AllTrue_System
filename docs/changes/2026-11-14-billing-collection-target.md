## 2026-11-14 — refactor(billing): 「哪些課可以收款、收款後算不算繳清」抽成共用規則（行為不變，#5113 step 3b 前置）
<!-- release-notes: silent_ship=silent-2026-11-14-billing-collection-target -->
<!-- silent-reason: 只把確認入帳既有的規則搬到共用類別，確認入帳的行為不變，教職員看不到差異 -->
- `App\Services\Billing\CollectionTarget` now holds confirm()'s rules for money landing on a course: `gate()` (waived / refunded / tutoring / zero-fee refusals, the exact responses confirm gave) and `settle()` (course Paid from the resolver; a shared count package marked paid when its bill is paid, except a monthly member or a bill billed at the entered amount because the package price is unknown), plus `packagePrice()`. `PaymentReportController::confirm()` and its helpers call them; no condition, query or write changed.
- So the receipt move (#5113) can call the same rules instead of re-implementing them. The receipt-move test fixtures move into `tests/Support/ReceiptReassignFixtures.php` unchanged.
