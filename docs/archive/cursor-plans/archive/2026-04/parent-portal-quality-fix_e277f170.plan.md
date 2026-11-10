---
name: parent-portal-quality-fix
overview: 修正家長 LINE 入口的可見資訊品質：只顯示仍需家長關注的繳費通知、統一近期課程時間格式、並讓學習評量可分頁載入，避免長期資料造成體驗與效能下滑。
todos:
  - id: align-parent-payment-alert-rule
    content: 定義並實作家長端繳費通知可見性規則，確保已結束且已繳費課程不顯示
    status: completed
  - id: standardize-upcoming-time-format
    content: 統一近期課程時間輸出與前端顯示格式為日期加時分
    status: completed
  - id: add-parent-learning-record-pagination
    content: 導入學習評量分頁API與前端逐頁載入互動
    status: completed
  - id: parent-portal-regression-check
    content: 建立回歸驗收場景並驗證三項目標全部達成
    status: completed
isProject: false
---

# 家長端資訊品質優化計畫

## 目標與成功標準
- **繳費通知精準化**：家長端「繳費通知」只顯示仍需處理的課程，不再出現「課程已結束且已繳清」項目。
- **近期課程時間可讀化**：時間顯示統一為「日期 + 時:分」，移除秒數與多餘格式，手機畫面一眼可讀。
- **學習評量可持續瀏覽**：導入分頁/逐頁載入，避免資料累積後頁面過長、載入慢、難查找。

## 目前現況（已確認）
- 後端家長儀表板資料集中在 [backend/app/Http/Controllers/ParentPortalController.php](/home/admin/backend/app/Http/Controllers/ParentPortalController.php)。
- 前端家長頁面集中在 [frontend/src/pages/ParentPortal.vue](/home/admin/frontend/src/pages/ParentPortal.vue)。
- 家長 API 入口在 [backend/routes/api.php](/home/admin/backend/routes/api.php)。
- 目前 `payment_alerts` 篩選條件較寬，仍可能把不該提醒的課程送到前端。
- 目前「近期課程」直接顯示 `StartTime/EndTime` 原值，容易出現秒數。
- 目前學習評量在 dashboard 一次回傳固定上限並全量渲染，缺乏可擴充的翻頁機制。

## 執行策略（管理層版本）
1. **先修正資料正確性（繳費通知）**
   - 以「是否仍需家長處理」作為唯一判斷原則，重整 `payment_alerts` 過濾邏輯。
   - 對齊「課程已結束 + 已繳費 = 不顯示」的業務規則，避免誤提醒與家長困惑。

2. **再修正資訊呈現一致性（近期課程時間）**
   - 統一時間輸出規格為 `YYYY-MM-DD HH:mm`（或頁面既有日期樣式 + `HH:mm`），前後端採單一標準。
   - 確保所有近期課程卡片一致，不再出現秒數或格式混雜。

3. **最後處理可擴充性（學習評量分頁）**
   - 新增學習評量分頁查詢能力（頁碼 + 每頁筆數 + 總筆數/是否有下一頁）。
   - 前端改為首屏載入近期資料，提供「下一頁/載入更多」互動，維持手機端流暢度。

## 交付範圍
- **後端**
  - 調整 [backend/app/Http/Controllers/ParentPortalController.php](/home/admin/backend/app/Http/Controllers/ParentPortalController.php) 的 `dashboard` 回傳策略（繳費通知邏輯 + 學習評量分頁輸出）。
  - 視需要在 [backend/routes/api.php](/home/admin/backend/routes/api.php) 增加家長學習評量專用分頁端點，或在既有 dashboard 支援分頁參數。
- **前端**
  - 調整 [frontend/src/pages/ParentPortal.vue](/home/admin/frontend/src/pages/ParentPortal.vue) 的繳費通知顯示條件、近期課程時間格式化、學習評量分頁互動。
  - 更新 [frontend/src/api.js](/home/admin/frontend/src/api.js) 的家長 API 呼叫參數與資料結構處理。
- **測試與驗收**
  - 補家長入口回歸案例（重點：已結束已繳費不顯示、時間格式、分頁切換資料正確）。

## 優先順序與里程碑
- **M1（高優先）**：繳費通知邏輯修正上線。
- **M2（中優先）**：近期課程時間格式統一上線。
- **M3（高價值）**：學習評量分頁上線，支援長期資料成長。

## 風險控管
- 避免影響主任端既有繳費提醒邏輯（家長端與主任端規則需明確分流）。
- 分頁改造需保持既有家長 token/session 驗證流程不變。
- 若既有資料存在歷史異常（課程狀態與繳費狀態不一致），需保留容錯顯示但不誤提醒。

## 驗收指標
- 家長端不再出現「已結束且已繳清」課程提醒。
- 近期課程時間顯示不含秒，格式一致。
- 學習評量可逐頁瀏覽，首次載入時間與頁面操作流暢度明顯改善。