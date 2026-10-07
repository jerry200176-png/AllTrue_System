## 2026-10-07 — feat(billing): 每筆合約每一堂課的付款狀態（帳務大改版 V1a）
<!-- release-notes: silent_ship=silent-2026-10-07-contract-session-coverage -->
<!-- silent-reason: 只新增後端讀取 API，畫面還沒接上；繳費單內容不變。 -->
- 新增 `GET accounting/contracts/{id}/sessions`：列出一筆合約全部上課日（不含取消），每堂標 paid／partial／unpaid／no_invoice，附合約備註與堂數制「還沒排日期」堂數。涵蓋規則抽成 `MonthlyBillingService::invoiceCoveredSessions`，繳費單（slip-data）共用同一份，不另寫日期算法。PRD v2 D13/D16/D17。
