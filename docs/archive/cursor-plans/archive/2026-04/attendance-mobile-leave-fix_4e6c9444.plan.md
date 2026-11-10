---
name: attendance-mobile-leave-fix
overview: 調查結果顯示「手機按請假確認沒反應」是前端彈窗層級問題與請假狀態契約不一致的疊加。依 CTO/架構師確認，本計畫採雙軌相容（前後端都修）並以 full hardening 方式一次補齊可用性、契約、錯誤訊息與回歸測試。
todos:
  - id: reproduce-mobile-leave-confirm
    content: 用手機視窗重現『請假確認無反應』並確認是否為層級覆蓋與422契約錯誤雙因子
    status: completed
  - id: fix-frontend-confirm-overlay
    content: 調整 AttendancePage 確認彈窗 z-index/safe-area 並將確認流程改為 await + 送出中狀態
    status: completed
  - id: align-attendance-status-contract
    content: 更新 AttendanceController validation 接受 leave 並保留 excused 相容轉換
    status: completed
  - id: add-regression-tests
    content: 新增/調整 Attendance Feature tests 覆蓋 Status=leave 與手機請假流程關鍵回歸
    status: completed
  - id: deploy-and-verify
    content: 執行 frontend deploy 並完成老師手機端請假確認實機驗證
    status: completed
isProject: false
---

# 手機請假確認無反應修正計畫

## 調查結論
- 前端在 [AttendancePage.vue](/home/admin/frontend/src/pages/AttendancePage.vue) 的請假確認彈窗使用 `.att-confirm-overlay { z-index: 1000 }`，但全域 [styles.css](/home/admin/frontend/src/styles.css) 的手機底部導覽 `.mobile-bottom-nav` 是 `z-index: 10000`，手機上確認按鈕可能被覆蓋，導致看起來「按了沒反應」。
- 前端送出請假時會傳 `Status: 'leave'`（同檔案 `doSubmitPendingMark` / `submitMakeupMark`），但後端 [AttendanceController.php](/home/admin/backend/app/Http/Controllers/AttendanceController.php) validation 目前僅允許 `present,absent,late,excused`，造成 422 失敗，使用者體感為「無效」。
- 確認按鈕目前是 `confirmDialog.onConfirm(); confirmDialog.visible = false`，未等待非同步送出完成，錯誤訊息也可能被使用者忽略。

## 修正策略（Full Hardening）
1. **修正前端可點擊性與行動裝置可用性**（P0）
   - 在 [AttendancePage.vue](/home/admin/frontend/src/pages/AttendancePage.vue) 提升確認彈窗層級（高於 `10000`），並加入手機 safe-area 底部間距，避免按鈕落在底部導覽覆蓋區。
2. **雙軌相容統一請假狀態契約**（P0）
   - 後端 [AttendanceController.php](/home/admin/backend/app/Http/Controllers/AttendanceController.php) 調整 validation，同時接受 `leave` 與 `excused`，並在內部統一落地為 `leave`。
   - 前端 [AttendancePage.vue](/home/admin/frontend/src/pages/AttendancePage.vue) 保留目前 `leave` 送出行為，並在註解/常數明確標示相容契約，避免未來頁面再次漂移。
3. **改善確認互動回饋**（P1）
   - [AttendancePage.vue](/home/admin/frontend/src/pages/AttendancePage.vue) 將確認按鈕改為 `await` 流程：送出中禁用按鈕、成功後再關 dialog、失敗時保留 dialog 並顯示可見錯誤。
4. **補強錯誤可觀測性與訊息標準化**（P1）
   - 前端失敗訊息除 `message` 外，顯示第一筆 validation error（如 `errors.Status[0]`），讓現場知道不是「沒反應」。
   - 後端 403/422/428 針對老師常見情境補充可讀訊息，降低一線人員誤判。
5. **回歸測試與驗收清單一次補齊**（P1）
   - 補足 leave/excused 契約測試、手機流程驗證清單、以及 batch partial failure 顯示測試/檢查項。

## 驗證與測試計畫
- **手動驗證（手機）**
  - 老師登入 → 出缺勤頁 → 將單筆待點名改為請假 → 點確認送出。
  - 預期：按鈕可點、Network 有 `POST /api/v1/attendance`、UI 顯示「已請假並順延」或清楚失敗原因。
- **API 驗證**
  - `Status=leave` 與 `Status=excused` 均可成功入站，並落地為請假流程（含順延邏輯），作為相容契約基準。
- **回歸重點**
  - `present/late/absent` 不受影響。
  - `batch-mark` 既有流程不退化。
  - 老師權限限制（非授課/代課仍 403）維持不變。
  - `require_password_change`（428）與 `require_campus`（403）在前端有可理解提示，不再呈現「沒反應」。

## QA 驗證計畫（補充）
### 測試範圍與環境
- 裝置：iPhone Safari、Android Chrome（至少各 1 台真機）。
- 帳號：`teacher`（合約授課）、`teacher`（非授課）、`director`。
- 分校：至少 2 個分校資料（驗證跨校區權限與 branch 切換）。
- 網路：正常網路 + 慢速網路（模擬 3G）各跑一次。

### 測試案例清單（QA 執行順序）
1. **手機確認按鈕可點（核心）**
   - 步驟：老師登入 -> 出缺勤 -> 選 `請假` -> 開確認彈窗 -> 點「確認送出」。
   - 預期：按鈕可點擊、沒有被底部導覽遮擋、API 請求確實送出。
2. **`Status=leave` 正常成功**
   - 步驟：以待點名堂次送出請假。
   - 預期：HTTP 成功、前端提示成功訊息、待點名列表刷新。
3. **`Status=excused` 相容成功**
   - 步驟：以 API 工具或測試入口送 `excused`。
   - 預期：HTTP 成功、後端最終語意為請假（`leave` 流程）。
4. **失敗訊息可讀（422 validation）**
   - 步驟：故意送非法 `Status` 值。
   - 預期：前端顯示明確錯誤（非僅「未知錯誤」）。
5. **老師權限 403 行為**
   - 步驟：非該課授課/代課老師嘗試請假。
   - 預期：HTTP 403，前端顯示可理解權限訊息。
6. **密碼需更新 428 行為**
   - 步驟：用需改密碼帳號送出請假。
   - 預期：HTTP 428，前端顯示需先變更密碼提示。
7. **批次點名不回歸**
   - 步驟：執行 batch-mark（含成功與失敗混合案例）。
   - 預期：成功/失敗筆數與明細可見，無「按了沒反應」。
8. **慢網路 UX**
   - 步驟：慢速網路下點「確認送出」。
   - 預期：按鈕有送出中狀態且防重複提交，完成後才關閉 dialog。

### 驗收門檻（Release Criteria）
- P0 案例（1~3）全部通過才可上線。
- 案例 4~8 至少通過 90%，若失敗需有已登記風險與 workaround。
- 不得出現「點擊無請求送出」與「請假請求成功但 UI 無回饋」兩類阻斷問題。

## 交付物
- 程式調整：
  - [AttendancePage.vue](/home/admin/frontend/src/pages/AttendancePage.vue)
  - [AttendanceController.php](/home/admin/backend/app/Http/Controllers/AttendanceController.php)
- 測試補強：
  - 既有出缺勤 Feature tests 補一案 `Status=leave` 契約測試（可放在 [backend/tests/Feature/AttendanceExcusedLeaveCascadeTest.php](/home/admin/backend/tests/Feature/AttendanceExcusedLeaveCascadeTest.php) 或新增專用測試檔）。
- 上線步驟：
  - 前端變更完成後執行 `cd /home/admin/frontend && npm run deploy`。
  - 驗證手機端請假確認流程與 API log。