## 2026-10-07 — refactor(frontend): one closed-reason verdict in courseMoneyState; TuitionCollectionPage on authedFetch
<!-- release-notes: silent_ship=silent-2026-10-07-closed-reason-verdict -->
<!-- silent-reason: 前端內部整併（結案判斷共用一份、請求改用共用 authedFetch），正常資料下畫面不變。 -->
- `closedReason` / `isHistoryCourse` / `isClosedReason` 移入 `lib/courseMoneyState.js`，課程管理與學生管理共用；舊資料邊界案例以較嚴格的學生管理規則為準（見 `courseMoneyState.test.js` DIVERGENCES）。
- `TuitionCollectionPage.vue` 15 處 raw `fetch` 改走 `authedFetch`（同 headers／錯誤處理）。
