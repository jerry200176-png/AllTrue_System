## 2026-10-16 — ci(acceptance): 唯讀正式驗收新增 `scope=payments`，核對 #4201／#4204／#4206 的繳費顯示（#4150）
<!-- release-notes: silent_ship=silent-2026-10-16-payment-display-acceptance -->
<!-- silent-reason: 只新增唯讀的正式驗收測試與 CI 判讀腳本；網站與 App 的功能、畫面和資料都不變 -->
- 新 spec `frontend/e2e/payment-display-production.spec.js`：免費／贈送不顯示未繳、不收了的課沒有收款按鈕、舊資料只有已收標記顯示需核對、已沖銷歸零的款項不顯示繳費日；期望值從頁面實際收到的 GET 資料算出，沒有案例時標為「未驗到」而不是通過。
- `calendar-course-acceptance.yml` 的 `scope` 新增 `payments`（不加入 `all`）；判讀腳本在 job log 只印每個測試的標題、狀態與數字計數。
