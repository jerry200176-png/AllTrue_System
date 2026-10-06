## 2026-10-06 — refactor(course-management): MonthlyBatchRenewModal 改用 authedFetch
<!-- release-notes: silent_ship=silent-2026-10-06-batch-renew-authed-fetch -->
<!-- silent-reason: 內部重構：批次月結續報改用共用 authedFetch，請求內容與畫面行為不變，教職員看不到差異 -->
- `MonthlyBatchRenewModal.vue` 的 renewal-preview／renew-monthly 請求由手組 Bearer header 改為 `authedFetch`（token 缺漏時錯誤訊息不變），並補元件測試。
