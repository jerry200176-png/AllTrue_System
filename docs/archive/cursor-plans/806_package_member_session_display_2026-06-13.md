# Bug Fix Plan: 共用方案成員「購買堂數」每科顯示 full total 造成誤導加總

> GitHub #806｜in-app #162｜2026-06-13｜Owner: AI Agent｜復發家族 F4 / §R21

## 0. 根因確認（Root Cause）— 已用 production SQL 驗證

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P2（回報 severity high；帳務數字正確、純顯示誤導） |
| 根因類型 | 前端顯示層語意不一致（非帳務錯誤） |
| 根因摘要 | 共用方案成員的「上課日期」面板 header 與歷史卡片用 `getPurchasedSessions()`（= 成員本地 `StudentClass.SessionCount`）顯示「購買 N 堂」。建包時每個成員 `SessionCount` 都被設為共池 `total_sessions`（12），於是兩科各顯示「購買 12」，並列看起來像買了 24 堂；且該 header 未反映共池已用 4 堂。 |
| 已驗證資料 | `course_packages #115`：total **12** / used **4** / remaining **8**（✅ 正確）。成員 `StudentClass` #2255（數學）、#2256（物理）：各 `SessionCount=12, RemainingSessions=12, UsedSessions=0`（本地欄位停在建立值）。 |
| 既有正確處 | 主表「剩餘」欄已用 `displayRemainingSessions()`＝`package_remaining_sessions`（8）+「（方案共用）」提示，**正確不動**。後端 `index()` 已正確回傳 `package_total/used/remaining`，**不動**。 |
| 錯誤行為 | 「上課日期（已上 X / 購買 12 堂）」per-subject → 兩科並列誤導為 24，且未顯示共池已用。 |
| 預期行為 | 方案成員的堂數摘要以**共池單一權威**呈現：本科已上 X 堂｜方案共用 12 堂（已用 4 / 剩 8）；非方案課程維持原「已上 / 購買」。 |

## 1. 範圍

**In Scope（純前端顯示）**
- `frontend/src/lib/packageSessions.js`：新增純函式 `packageMemberSessionSummary(course, {completed, cancelled})` 計算摘要文字（方案走共池、一般走購買）。
- `frontend/src/lib/packageSessions.test.js`：補測試（方案不加總、共池 used 正確、一般課程不變、cancelled 後綴）。
- `frontend/src/pages/CourseManagement.vue`：current 課程 dates-panel header、history card details 行與 dates-panel header 改用該函式。

**Out of Scope（明確不動）**
- 後端 `StudentClassController::index` 共池欄位計算、`course_packages` 會計、扣堂（`SessionDeductionService` / package ledger）。
- 主表「剩餘」欄（已正確）。
- 建包時成員 `SessionCount=total` 的寫入行為（屬資料設計，本案只修顯示；若要改寫入屬另一張單）。

## 2. Acceptance Criteria
- AC-1：方案成員（PackageID>0）dates-panel header 顯示「方案共用 {total} 堂（已用 {used} / 剩 {remaining}）」，不再每科顯示「購買 {total}」。
- AC-2：`used = total − remaining`，以共池欄位為準（#115 → 已用 4 / 剩 8）。
- AC-3：非方案 session 課程顯示維持「已上 X / 購買 Y 堂」不變。
- AC-4：cancelled > 0 時後綴「，N 堂已取消」保留。
- AC-5：`npm run test:package-sessions`（及 build 串連的前端純測試）全綠。

## 3. DoD
- 前端純函式測試綠 → push → CI（Vite Build 含 test:package-sessions）綠 → PR `Closes #806` → merge → deploy.yml → version.json 更新 → in-app #162 `resolved` + 白話留言請依婷主任驗收。
