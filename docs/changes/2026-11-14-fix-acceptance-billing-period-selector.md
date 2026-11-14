## 2026-11-14 — fix(e2e): 課程查找帳務資料的合約期間改用固定測試標記，並新增驗收腳本選取器守門 (issue 4989)
<!-- release-notes: silent_ship=silent-2026-11-14-fix-acceptance-billing-period-selector -->
<!-- silent-reason: Adds one invisible test hook (data-testid) on the 帳務資料 contract period and a CI guard for acceptance specs; no visible text, number, layout or request change for staff or parents. -->
- 課程查找「帳務資料」每份合約的期間文字外觀與內容完全不變；只在頁面上加了一個看不見的測試標記，讓上線後的驗收腳本不會因為版面改版而找不到它。
