## 2026-11-07 — test(ci): every frontend test file now runs (28 never did); a guard keeps it that way
<!-- release-notes: silent_ship=silent-2026-11-07-frontend-tests-all-run -->
<!-- silent-reason: 只補上原本從沒執行過的前端測試與 CI 步驟，教職員看到的畫面與功能完全不變。 -->
- `npm run test:lib-node` 用 Node 內建測試執行器跑 `frontend/src` 內所有純 node 測試（85 個檔案），CI 新增一個步驟；8 個 vitest 格式但漏列的測試補進 `vitest.config.js` 的 include。
- 新的守門測試 `src/lib/testCoverageGuard.test.js`：任何 `*.test.js` 既不是純 node 也不在 vitest include 就會失敗。
- 原本沒跑的測試中只有 `paymentStatus.test.js` 因過時而失敗（未知狀態早已改回傳 `null`），已更新斷言。
