## 2026-11-11 — fix(courses): new-contract fee field defaults to 1200 (#5158)
<!-- release-notes: staff_update=staff-2026-11-11-default-session-fee-1200 -->
- The shared 新增課程／續約 scheduler prefilled 單堂費用 with 1000 in four places (single course and multi-subject package, initial state and reset). All four now read one constant, `DEFAULT_PRICE_PER_SESSION = 1200` in `frontend/src/lib/coursePricing.js`.
- Only the prefilled value of a new form changes. Staff can still type another amount; existing contracts, issued bills, payments and renewal paths that carry a contract's own rate are untouched. No backend, API or data change.
