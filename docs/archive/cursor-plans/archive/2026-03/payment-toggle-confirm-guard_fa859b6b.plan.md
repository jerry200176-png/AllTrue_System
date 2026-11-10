---
name: payment-toggle-confirm-guard
overview: 為所有「已繳費/未繳費」切換按鈕加入確認機制，降低誤觸造成的狀態誤改。此次先採前端確認視窗方案，不改後端 API 契約。
todos:
  - id: add-confirm-students-list
    content: 在 StudentsList.vue 的 togglePaymentStatus 加入 confirm 二次確認與取消即中止流程
    status: completed
  - id: add-confirm-course-management
    content: 在 CourseManagement.vue 的 togglePaymentStatus 加入 confirm 二次確認與取消即中止流程
    status: completed
  - id: prevent-double-tap
    content: 在兩頁加入 pending 狀態鎖定邏輯，避免連點重複切換
    status: completed
  - id: manual-regression-check
    content: 完成兩頁手動驗證：確認/取消分支、API 失敗回復、連點防護
    status: completed
isProject: false
---

# 繳費狀態防誤觸修正計畫

## 目標
- 對所有可切換「已繳費/未繳費」的按鈕，統一加上二次確認（`window.confirm`）。
- 在送出狀態更新期間鎖定該按鈕，避免連點重複觸發。
- 不變更既有 API (`PUT /api/v1/student-classes/{id}`) 與資料結構。

## 影響範圍
- [StudentsList.vue](/home/admin/frontend/src/pages/StudentsList.vue)
- [CourseManagement.vue](/home/admin/frontend/src/pages/CourseManagement.vue)

## 現況與痛點
- 兩頁都在 `togglePaymentStatus(...)` 內直接計算新狀態後立刻送 API，成功就直接改 `payment_status`，沒有任何確認步驟。
- 在表格密集按鈕區（編輯/刪除/加購鄰近）很容易誤觸，造成即時狀態翻轉。

## 實作步驟
1. 在兩個頁面的 `togglePaymentStatus` 一開始加入確認流程。
- 訊息格式統一為「將 [學生/科目] 從『未繳費』改為『已繳費』，確定嗎？」（反向亦同）。
- 使用者按取消就直接 `return`，完全不觸發 API。

2. 加入「單筆按鈕請求中」保護。
- 在頁面 state 增加 `pendingPaymentStatusIds`（`Set` 或等價結構）。
- 點擊後先標記 pending、請求結束後解除。
- 模板上對該筆按鈕套 `:disabled="isPaymentStatusPending(id)"`，避免連點與重覆送出。

3. 保持 UI/樣式最小變更。
- 不改既有色彩語意（已繳費綠、未繳費橘）。
- 僅在 pending 狀態下呈現 disabled（沿用現有按鈕 disabled 視覺）。

4. 手動驗證清單。
- 在學生管理頁：點已繳費/未繳費，確認「取消」不變更、「確認」才變更。
- 在課程管理頁：同上。
- 快速連點同一按鈕時，只應發出一次更新請求。
- API 失敗時維持原狀態，pending 會正確解除。

## 風險與注意事項
- 此方案屬於「防誤觸確認」，不會提供復原（Undo）。
- 若未來要再降低心智負擔，可再迭代為可撤銷 toast 或編輯模式切換，但不在本次範圍。