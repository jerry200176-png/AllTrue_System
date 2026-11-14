## 2026-11-14 — perf(billing): 收據移到正確帳單的候選帳單一次讀完 (#5113 step 3b, in-app #522)
<!-- release-notes: silent_ship=silent-2026-11-14-receipt-reassign-targets-batch -->
<!-- silent-reason: 只改後端讀取方式，功能旗標預設關閉、清單內容不變，教職員看不到差異 -->
- `GET payment-reports/{id}/reassign-targets` now reads every candidate bill, its payments and items, its course (with pricing amendments), the paid-package holds and the package-aware pending reports in one query per kind, instead of several queries per candidate. The list and its order are unchanged.
- New `PaymentReport::pendingScopeCourseIdsFor()` (batched form); `pendingScopeCourseIds()` now delegates to it with the same result, so `directorRecord` keeps its scope.
- The refusal test for a receipt whose money is not on its bill now pins the exact code (`reassign_source_not_applied`, 409) for each shape.
