---
name: paid-status-regression-fix
overview: 修復「已繳費但繳費日期空白，編輯備註後被改成未繳費」的回歸問題，並補齊跨頁面一致性與回歸測試。
todos:
  - id: confirm-contract
    content: 確認並固定 paid_at 與 payment_status 的 API 契約語意
    status: completed
  - id: cto-decision
    content: CTO 定案 paid_at 空值語意（null=清空日期但不改 Paid）
    status: completed
  - id: backend-fix-mapper
    content: 修正 StudentClassController mapFrontendPayload，移除 paid_at 空值隱式改 unpaid
    status: completed
  - id: frontend-guard
    content: 檢查並收斂 StudentsList/CourseManagement 編輯 payload，避免非意圖覆寫
    status: completed
  - id: regression-tests
    content: 新增 PUT student-classes 的 Paid/PayDate 回歸 Feature tests
    status: completed
  - id: qa-matrix
    content: 建立 API+UI 測試矩陣（含跨頁與提醒清單）
    status: completed
  - id: release-guard
    content: 上線前執行守門檢查與回滾方案演練
    status: completed
  - id: manual-qa
    content: 執行兩頁編輯流程與催繳清單手動驗收
    status: completed
isProject: false
---

# 已繳費狀態誤降級修復計畫

## 問題定義（PM）
- 使用者情境：學生課程狀態為已繳費，但「繳費日期（選填）」留空。
- 目前異常：僅編輯備註並儲存後，課程被改成未繳費。
- 業務影響：
  - 主任看板與催繳清單誤判，造成錯誤催繳。
  - 課程結案/續課判斷（依 `Paid`）可能被誤觸發。

## 根因分析（架構）
- 前端在課程編輯 PUT 會送 `paid_at: form.paid_at || null`：
  - [StudentsList]( /home/admin/frontend/src/pages/StudentsList.vue )
  - [CourseManagement]( /home/admin/frontend/src/pages/CourseManagement.vue )
- 後端 mapping 目前邏輯：只要 payload 包含 `paid_at` 且為空，就在未帶 `payment_status` 時強制 `Paid=0`：
  - [StudentClassController::mapFrontendPayload]( /home/admin/backend/app/Http/Controllers/StudentClassController.php )
- 這導致「未填日期」被誤解為「改為未繳費」，而不是「不變更繳費狀態」。

## QA 審查更新
- 測試缺口補強：
  - 目前計畫以 API case 為主，需補 UI 觸發路徑（`StudentsList` / `CourseManagement`）的 payload 實際內容驗證。
  - 增加「同一課程跨頁編輯」檢查：A 頁儲存後，B 頁重整結果需一致。
- 回歸範圍擴大：
  - 除催繳清單外，需檢查看板提醒與家長端課程支付狀態，避免 `Paid` 同步不一致。
  - 補「批次切換繳費狀態後再編輯備註」場景，確認不互相覆蓋。
- 驗收資料準備：
  - 建立至少 3 筆測試資料：`Paid=1, PayDate=null`、`Paid=1, PayDate=有值`、`Paid=0, PayDate=null`。
  - 每筆資料都走「編輯備註儲存」與「切換 payment_status 儲存」雙流程。

## CTO 架構更新
- 契約語意定案：
  - `payment_status` 是唯一支付狀態切換入口。
  - `paid_at` 只代表日期欄位；`paid_at=null` 僅表示日期清空，不代表改為未繳費。
- 相容性策略：
  - 後端先落地語意修正，保證所有客戶端（含舊前端）不再被空值誤傷。
  - 前端再做 payload 最小化作為加強，不依賴前端來保證資料正確性。
- 可觀測性與追蹤：
  - 在 `StudentClassController::update` 新增 debug log（僅短期）記錄 `payment_status/paid_at/Paid/PayDate` 變化，便於快速確認修復有效。
  - 修復完成後保留測試，不保留冗餘 log（避免長期噪音）。

## 修復策略
- 原則：`paid_at` 是選填輔助欄位，不得隱式覆寫 `Paid`。
- 後端修正（主修）：
  - 在 [StudentClassController]( /home/admin/backend/app/Http/Controllers/StudentClassController.php ) 調整 `mapFrontendPayload`：
    - `paid_at` 有值：設定 `PayDate`，並可維持 `Paid=1`（現有行為保留）。
    - `paid_at` 空值：只清 `PayDate`（或不更新，依需求），**不得自動設 `Paid=0`**。
    - 僅當 payload 明確帶 `payment_status=unpaid` 時，才改 `Paid=0`。
- 前端防呆（次修）：
  - 在 [StudentsList]( /home/admin/frontend/src/pages/StudentsList.vue ) 與 [CourseManagement]( /home/admin/frontend/src/pages/CourseManagement.vue )，避免「只編輯備註」時無意送出會觸發狀態推導的欄位；若保留送 `paid_at`，也要依後端新契約確保不會降級 `Paid`。
- API 契約補充：
  - 在 controller 註解/文件註明：`paid_at` 與 `payment_status` 為獨立語意；狀態切換必須顯式使用 `payment_status`。

## 實作步驟
1. CTO 定案 API 契約：`paid_at` 不得隱式改 `Paid`，並寫入註解/文件。
2. 後端調整 `mapFrontendPayload` 的 `paid_at` 分支，移除「空值 => Paid=0」推導。
3. 檢查 `update()` 與其他寫入路徑是否仍會以空值重設 `Paid`，一併封堵。
4. 前端兩個編輯入口做 payload 最小化或欄位明確化，避免未意圖欄位覆寫。
5. 補 Feature 測試，覆蓋 `PUT /api/v1/student-classes/{id}` 在不同 payload 組合下的 `Paid/PayDate` 行為。
6. 執行 QA 測試矩陣（API + UI + 跨頁一致性 + 提醒清單）。
7. 上線前守門：比對修復前後 `Paid` 異常變更數、確認可回滾步驟。

## 驗收標準（Definition of Done）
- 已繳費 + `PayDate` 空白：只改備註後仍維持已繳費。
- 已繳費 + `PayDate` 空白：送 `paid_at: null` 不會改 `Paid`。
- 顯式送 `payment_status: unpaid` 才會變未繳費。
- 顯式送 `payment_status: paid` 可維持/改為已繳費（`paid_at` 仍可空）。
- 既有繳費提醒/催繳清單行為不產生新回歸。

## 風險與相依
- 高風險：`Paid` 被多處商業邏輯依賴（提醒、家長端、財務統計）；需避免改壞既有 paid/unpaid 切換。
- 相依模組：
  - [AlertController]( /home/admin/backend/app/Http/Controllers/AlertController.php )
  - [FinanceController]( /home/admin/backend/app/Http/Controllers/FinanceController.php )
  - [ParentPortalController]( /home/admin/backend/app/Http/Controllers/ParentPortalController.php )
- 發佈重點：先以後端修正為主，前端防呆為輔，降低 API 使用端不一致風險。

## 測試計畫（最小回歸集）
- 新增/擴充後端 Feature test（建議放在 `backend/tests/Feature/`）：
  - case1: 初始 `Paid=1, PayDate=null`，PUT 僅改 `Memo` + `paid_at=null`，期望 `Paid` 仍 1。
  - case2: 初始 `Paid=1`，PUT `payment_status=unpaid`，期望 `Paid=0`。
  - case3: 初始 `Paid=0`，PUT `paid_at=2026-04-14`，期望 `Paid=1, PayDate` 更新。
  - case4: 初始 `Paid=1, PayDate=某日`，PUT `paid_at=null`（不含 `payment_status`），期望 `Paid=1`。
- 手動測試：
  - 在 [StudentsList]( /home/admin/frontend/src/pages/StudentsList.vue ) 與 [CourseManagement]( /home/admin/frontend/src/pages/CourseManagement.vue )各跑一次「只改備註儲存」。
  - 驗證 [TuitionCollectionPage]( /home/admin/frontend/src/pages/TuitionCollectionPage.vue ) 清單不會錯誤新增該生。
  - 驗證 [ParentPortalController]( /home/admin/backend/app/Http/Controllers/ParentPortalController.php ) 對應頁面回傳 paid/unpaid 未受影響。

## 上線與回滾（新增）
- 上線守門：
  - 先部署後端修復，再部署前端防呆，避免舊前端仍有風險。
  - 發佈後抽樣查核「已繳費且無日期」族群，確認未再被改為未繳費。
- 回滾條件：
  - 若發現 paid/unpaid 異常波動，立即回滾 `StudentClassController` 相關變更。
  - 回滾後保留新增測試，作為 hotfix 重新上線前的必跑門檻。
