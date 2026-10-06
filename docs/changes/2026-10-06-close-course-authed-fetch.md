## 2026-10-06 — refactor(course-management): closeCourseNoRenew 改用 authedFetch
<!-- release-notes: silent_ship=silent-2026-10-06-close-course-authed-fetch -->
<!-- silent-reason: 內部重構：結案請求改用共用 authedFetch，請求內容與畫面行為不變，教職員看不到差異 -->
- `lib/closeCourseNoRenew.js` 不再自己組 Bearer header，改用 `getAccessToken`/`authedFetch`（移除 `supabase`／`fetchImpl` 注入參數，測試改 mock authedFetch；無 token 時仍提示「請重新登入」）。
